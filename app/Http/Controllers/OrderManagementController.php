<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ReservedProduct;
use App\Models\ProductBarcode;
use App\Models\Store;
use App\Services\InventoryReservationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api'); // Employee authentication
    }

    /**
     * Keep social-commerce availability checks in sync with the same live stock
     * reality that Product List shows.
     *
     * Product List computes reserved/available stock live from batches + open
     * order rows. The social-commerce cart availability path uses the
     * reserved_products table for the global availability guard. After low-stock
     * return/exchange workflows, that table can be stale unless we rebuild it
     * immediately before answering "can this store fulfill this cart?".
     */
    private function syncReservationRowsForProducts(array $productIds): void
    {
        $productIds = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return;
        }

        $service = app(InventoryReservationService::class);
        foreach ($productIds as $productId) {
            $service->syncProduct((int) $productId);
        }
    }

    /**
     * Get orders pending store assignment
     * Includes both ecommerce and social_commerce orders
     */
    public function getPendingAssignmentOrders(Request $request): JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);
            $sortOrder = $request->query('sort_order', 'asc');
            $status = 'pending_assignment';
            
            // Validate sort order to prevent SQL injection or invalid values
            if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
                $sortOrder = 'asc';
            }
            
            $orders = Order::where('status', $status)
                ->whereNull('store_id')
                ->whereIn('order_type', ['ecommerce', 'social_commerce'])
                ->with(['customer', 'items.product'])
                ->orderBy('created_at', $sortOrder)
                ->paginate($perPage);

            // Add summary for each order
            foreach ($orders as $order) {
                $order->items_summary = $order->items->map(function ($item) {
                    return [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                    ];
                });
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $orders->items(),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'total_pages' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch pending orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available stores for an order based on inventory
     */
    public function getAvailableStores($orderId): JsonResponse
    {
        try {
            $order = Order::with('items.product')->findOrFail($orderId);

            if ($order->status !== 'pending_assignment') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is not pending assignment',
                ], 400);
            }

            // Use the same barcode-aware availability matrix as the assignment
            // action and bulk-assignment page. The old version of this endpoint
            // only summed product_batches.quantity, so returned stock from old
            // stocked-out batches could appear unavailable even when a sellable
            // barcode was physically back in the selected store.
            $stores = Store::where('is_active', true)
                ->orderBy('name')
                ->get();

            $storeInventory = $this->buildStoreFulfillmentRowsForOrder($order, $stores);

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_items' => $order->items->sum('quantity'),
                    'stores' => $storeInventory,
                    'recommendation' => $this->getRecommendation($storeInventory),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available stores',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }


    /**
     * Check which active stores can fulfill a not-yet-created cart.
     * Used by social-commerce/amount-details manual store assignment.
     */
    public function getCartStoreAvailability(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'store_id' => 'nullable|exists:stores,id',
                'items' => 'required|array|min:1|max:200',
                'items.*.product_id' => 'required|integer|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $storesQuery = Store::where('is_active', true)->orderBy('name');
            if ($request->filled('store_id')) {
                $storesQuery->where('id', (int) $request->store_id);
            }

            $stores = $storesQuery->get();
            $requiredProducts = $this->requiredProductQuantitiesFromPayload((array) $request->input('items', []));
            $this->syncReservationRowsForProducts(array_keys($requiredProducts));
            $rows = $this->buildStoreFulfillmentRowsForRequiredProducts($requiredProducts, $stores, [], null, true);

            return response()->json([
                'success' => true,
                'message' => 'Cart store availability loaded.',
                'data' => [
                    'stores' => $rows,
                    'fulfillable_stores' => collect($rows)->where('can_fulfill_entire_order', true)->values(),
                    'best_fulfillment_store' => $this->getRecommendation($rows),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check cart store availability',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk page data for assigning pending_assignment orders to one selected store.
     * Returns order summaries plus a compact fulfillment matrix for every active store.
     */
    public function getBulkPendingAssignmentOrders(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 100);
            $perPage = max(1, min($perPage, 200));
            $sortOrder = strtolower((string) $request->query('sort_order', 'asc'));

            if (!in_array($sortOrder, ['asc', 'desc'], true)) {
                $sortOrder = 'asc';
            }

            $stores = Store::where('is_active', true)
                ->orderBy('name')
                ->get();

            $orders = Order::where('status', 'pending_assignment')
                ->whereNull('store_id')
                ->whereIn('order_type', ['ecommerce', 'social_commerce'])
                ->with(['customer', 'items.product'])
                ->orderBy('created_at', $sortOrder)
                ->paginate($perPage);

            $formattedOrders = collect($orders->items())->map(function (Order $order) use ($stores) {
                $order->items_summary = $this->buildItemsSummary($order);
                $fulfillmentRows = $this->buildStoreFulfillmentRowsForOrder($order, $stores);

                $orderArray = $order->toArray();
                $orderArray['items_summary'] = $order->items_summary;
                $orderArray['available_stores_summary'] = $fulfillmentRows;
                $orderArray['best_fulfillment_store'] = $this->getRecommendation($fulfillmentRows);

                return $orderArray;
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $formattedOrders,
                    'stores' => $stores->map(function (Store $store) {
                        return [
                            'id' => $store->id,
                            'name' => $store->name,
                            'address' => $store->address,
                            'store_code' => $store->store_code,
                            'is_warehouse' => (bool) $store->is_warehouse,
                            'is_online' => (bool) $store->is_online,
                            'is_active' => (bool) $store->is_active,
                        ];
                    })->values(),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'total_pages' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bulk pending assignment orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Assign multiple pending_assignment orders to a selected store and move them to assigned_to_store.
     * This does not deduct physical stock. It only promises the order to the store.
     */
    public function bulkAssignOrdersToStorePending(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'store_id' => 'required|exists:stores,id',
                'order_ids' => 'required|array|min:1|max:200',
                'order_ids.*' => 'integer|distinct|exists:orders,id',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $store = Store::find((int) $request->store_id);
            if (!$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected store was not found or has been deleted.',
                ], 422);
            }

            if (!$store->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected store is inactive. Activate the store before assigning orders.',
                ], 422);
            }

            $orderIds = collect($request->order_ids)
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values();

            $ordersById = Order::with(['customer', 'items.product'])
                ->whereIn('id', $orderIds)
                ->get()
                ->keyBy('id');

            $results = [
                'success' => [],
                'failed' => [],
            ];

            // Quantities successfully promised earlier in this same bulk operation.
            // This prevents selecting multiple orders that together exceed the store's free stock.
            $bulkReservedByProduct = [];

            foreach ($orderIds as $orderId) {
                /** @var Order|null $order */
                $order = $ordersById->get($orderId);

                if (!$order) {
                    $results['failed'][] = [
                        'order_id' => $orderId,
                        'order_number' => null,
                        'reason' => 'Order was not found.',
                    ];
                    continue;
                }

                if ($order->status !== 'pending_assignment') {
                    $results['failed'][] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'reason' => "Order status is {$order->status}, not pending_assignment.",
                    ];
                    continue;
                }

                if (!empty($order->store_id)) {
                    $results['failed'][] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'reason' => 'Order is already assigned to a store.',
                    ];
                    continue;
                }

                if ($order->items->isEmpty()) {
                    $results['failed'][] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'reason' => 'Order has no product items to fulfill.',
                    ];
                    continue;
                }

                $availabilityRows = $this->buildStoreFulfillmentRowsForOrder(
                    $order,
                    collect([$store]),
                    $bulkReservedByProduct
                );
                $storeAvailability = $availabilityRows[0] ?? null;

                if (!$storeAvailability || empty($storeAvailability['can_fulfill_entire_order'])) {
                    $blockingProducts = collect($storeAvailability['inventory_details'] ?? [])
                        ->filter(fn ($detail) => empty($detail['can_fulfill']))
                        ->map(function ($detail) {
                            return [
                                'product_id' => $detail['product_id'] ?? null,
                                'product_name' => $detail['product_name'] ?? 'Unknown Product',
                                'required' => $detail['required_quantity'] ?? 0,
                                'available' => $detail['available_quantity'] ?? 0,
                            ];
                        })
                        ->values();

                    $results['failed'][] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'reason' => "{$store->name} cannot fully fulfill this order with current free inventory.",
                        'store_id' => $store->id,
                        'store_name' => $store->name,
                        'fulfillment_percentage' => $storeAvailability['fulfillment_percentage'] ?? 0,
                        'blocking_products' => $blockingProducts,
                    ];
                    continue;
                }

                DB::beginTransaction();

                try {
                    $order->update([
                        'store_id' => $store->id,
                        'status' => 'assigned_to_store',
                        'fulfillment_status' => 'pending_fulfillment',
                        'processed_by' => auth('api')->id(),
                        'metadata' => array_merge($order->metadata ?? [], [
                            'bulk_store_assigned_at' => now()->toISOString(),
                            'bulk_store_assigned_by' => auth('api')->id(),
                            'bulk_assignment_target_status' => 'assigned_to_store',
                            'bulk_assignment_notes' => $request->notes,
                        ]),
                    ]);

                    // Keep item-level store assignment consistent with the order-level assignment.
                    $order->items()->update(['store_id' => $store->id]);

                    DB::commit();

                    foreach ($this->requiredProductQuantities($order) as $productId => $required) {
                        $bulkReservedByProduct[$productId] = ($bulkReservedByProduct[$productId] ?? 0) + $required['quantity'];
                    }

                    $results['success'][] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'store_id' => $store->id,
                        'store_name' => $store->name,
                        'new_status' => 'assigned_to_store',
                    ];
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $results['failed'][] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            $successCount = count($results['success']);
            $failedCount = count($results['failed']);

            return response()->json([
                'success' => $successCount > 0 && $failedCount === 0,
                'partial_success' => $successCount > 0 && $failedCount > 0,
                'message' => "Bulk store assignment completed: {$successCount} assigned, {$failedCount} failed.",
                'data' => [
                    'store' => [
                        'id' => $store->id,
                        'name' => $store->name,
                    ],
                    'results' => $results,
                    'assigned_count' => $successCount,
                    'failed_count' => $failedCount,
                ],
            ], $failedCount > 0 && $successCount === 0 ? 422 : 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk assign orders to store',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildItemsSummary(Order $order): array
    {
        return $order->items->map(function ($item) {
            return [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'quantity' => (int) $item->quantity,
            ];
        })->values()->toArray();
    }

    private function requiredProductQuantities(Order $order): array
    {
        $required = [];

        foreach ($order->items as $item) {
            $productId = (int) $item->product_id;
            if (!$productId) {
                continue;
            }

            if (!isset($required[$productId])) {
                $required[$productId] = [
                    'product_id' => $productId,
                    'product_name' => $item->product_name,
                    'product_sku' => $item->product_sku,
                    'quantity' => 0,
                ];
            }

            $required[$productId]['quantity'] += (int) $item->quantity;
        }

        return $required;
    }

    private function requiredProductQuantitiesFromPayload(array $items): array
    {
        $required = [];
        $productIds = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $productIds[] = $productId;

            if (!isset($required[$productId])) {
                $required[$productId] = [
                    'product_id' => $productId,
                    'product_name' => 'Product #' . $productId,
                    'product_sku' => null,
                    'quantity' => 0,
                ];
            }
            $required[$productId]['quantity'] += $quantity;
        }

        if (!empty($productIds)) {
            $products = Product::whereIn('id', array_values(array_unique($productIds)))->get()->keyBy('id');
            foreach ($required as $productId => &$row) {
                $product = $products->get($productId);
                if ($product) {
                    $row['product_name'] = $product->name;
                    $row['product_sku'] = $product->sku;
                }
            }
            unset($row);
        }

        return $required;
    }

    private function buildStoreFulfillmentRowsForOrder(Order $order, $stores, array $extraAssignedByProduct = []): array
    {
        return $this->buildStoreFulfillmentRowsForRequiredProducts(
            $this->requiredProductQuantities($order),
            $stores,
            $extraAssignedByProduct,
            (int) $order->id
        );
    }

    private function buildStoreFulfillmentRowsForRequiredProducts(array $requiredProducts, $stores, array $extraAssignedByProduct = [], ?int $excludeOrderId = null, bool $respectGlobalAvailability = false): array
    {
        $stores = collect($stores)->filter(fn ($store) => !empty($store?->id))->values();
        if ($stores->isEmpty()) {
            return [];
        }

        $productIds = array_keys($requiredProducts);
        $storeIds = $stores->pluck('id')->map(fn ($id) => (int) $id)->values()->toArray();

        if (empty($productIds)) {
            return [];
        }

        $reservationService = app(InventoryReservationService::class);
        $reservationService->healSellableBarcodeBatchLinksForStore($productIds, $storeIds, null);

        if ($respectGlobalAvailability) {
            $this->syncReservationRowsForProducts($productIds);
        }

        $reservedProducts = ReservedProduct::whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $barcodeTrackedProductIds = $reservationService->barcodeTrackedProductIds($productIds);
        $sellableBarcodeCounts = $reservationService->sellableBarcodeQuantitiesByStore($productIds, $storeIds, $excludeOrderId);

        $batches = ProductBatch::whereIn('product_id', $productIds)
            ->whereIn('store_id', $storeIds)
            ->where('availability', true)
            ->where('quantity', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>', now());
            })
            ->get()
            ->groupBy(['store_id', 'product_id']);

        // These statuses mean physical stock was already deducted, so they should not reserve again here.
        $deductedStatuses = ['confirmed', 'delivered', 'cancelled', 'returned', 'refunded'];

        $assignedQuery = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('order_items.product_id', $productIds)
            ->whereIn('orders.store_id', $storeIds)
            ->whereNotIn('orders.status', $deductedStatuses)
            ->whereNull('orders.deleted_at');

        if ($excludeOrderId) {
            $assignedQuery->where('orders.id', '!=', $excludeOrderId);
        }

        $assignedOrders = $assignedQuery
            ->select(
                'orders.store_id',
                'order_items.product_id',
                DB::raw('SUM(order_items.quantity) as total_assigned'),
                DB::raw('SUM(CASE WHEN order_items.product_barcode_id IS NULL THEN order_items.quantity ELSE 0 END) as unbarcoded_assigned')
            )
            ->groupBy('orders.store_id', 'order_items.product_id')
            ->get()
            ->groupBy('store_id');

        $rows = [];

        foreach ($stores as $store) {
            $assignedStoreData = $assignedOrders->get($store->id, collect())->keyBy('product_id');
            $inventoryDetails = [];
            $totalRequiredQty = 0;
            $fulfillableQty = 0;
            $canFulfillEntireOrder = true;

            foreach ($requiredProducts as $productId => $requiredMeta) {
                $requiredQuantity = (int) $requiredMeta['quantity'];
                $productBatchesInStore = $batches->get($store->id, collect())->get($productId, collect());
                $batchPhysicalInStore = (int) $productBatchesInStore->sum('quantity');
                $usesBarcodeAvailability = !empty($barcodeTrackedProductIds[(int) $productId]);
                $sellableBarcodeQuantity = (int) ($sellableBarcodeCounts[(int) $store->id][(int) $productId] ?? 0);

                // Barcode-tracked products should be judged by barcode lifecycle/location,
                // not only by product_batches.store_id. This fixes low-stock returns where
                // the barcode is back in the store but the old batch row is stale or not
                // store-scoped in the way the social-commerce cart expected.
                $totalPhysicalInStore = $usesBarcodeAvailability ? $sellableBarcodeQuantity : $batchPhysicalInStore;
                $assignedRow = $assignedStoreData->get($productId);
                $alreadyAssignedInStore = (int) ($assignedRow->total_assigned ?? 0);
                $unbarcodedAssignedInStore = (int) ($assignedRow->unbarcoded_assigned ?? 0);
                $extraAssigned = (int) ($extraAssignedByProduct[$productId] ?? 0);

                // sellableBarcodeQuantitiesByStore() already excludes barcodes held by
                // open order_items. For barcode-tracked products, subtract only open
                // store assignments that have not yet locked a specific barcode. This
                // avoids double-subtracting the same reserved unit and fixes the
                // stock 2 / reserved 1 / cannot assign case.
                $assignedQuantityToSubtract = $usesBarcodeAvailability
                    ? $unbarcodedAssignedInStore
                    : $alreadyAssignedInStore;
                $freePhysicalInStore = max(0, $totalPhysicalInStore - $assignedQuantityToSubtract - $extraAssigned);
                $globalReserved = $reservedProducts->get($productId);
                $globalAvailable = $globalReserved ? (int) $globalReserved->available_inventory : 0;
                $actuallyAvailableInStore = $respectGlobalAvailability
                    ? min($freePhysicalInStore, $globalAvailable)
                    : $freePhysicalInStore;
                $canFulfill = $actuallyAvailableInStore >= $requiredQuantity;

                if (!$canFulfill) {
                    $canFulfillEntireOrder = false;
                }

                $totalRequiredQty += $requiredQuantity;
                $fulfillableQty += min($requiredQuantity, $actuallyAvailableInStore);

                $inventoryDetails[] = [
                    'product_id' => (int) $productId,
                    'product_name' => $requiredMeta['product_name'],
                    'product_sku' => $requiredMeta['product_sku'],
                    'required_quantity' => $requiredQuantity,
                    'physical_quantity' => $totalPhysicalInStore,
                    'batch_physical_quantity' => $batchPhysicalInStore,
                    'sellable_barcode_quantity' => $sellableBarcodeQuantity,
                    'stock_source' => $usesBarcodeAvailability ? 'barcode_lifecycle' : 'batch_quantity',
                    'assigned_quantity' => $alreadyAssignedInStore,
                    'unbarcoded_assigned_quantity' => $unbarcodedAssignedInStore,
                    'assigned_quantity_subtracted' => $assignedQuantityToSubtract,
                    'bulk_selected_quantity' => $extraAssigned,
                    'free_physical_quantity' => $freePhysicalInStore,
                    'available_quantity' => $actuallyAvailableInStore,
                    'global_available' => $globalAvailable,
                    'can_fulfill' => $canFulfill,
                    'batches' => $productBatchesInStore->map(function ($batch) {
                        return [
                            'batch_id' => $batch->id,
                            'batch_number' => $batch->batch_number,
                            'quantity' => (int) $batch->quantity,
                            'sell_price' => $batch->sell_price,
                            'expiry_date' => $batch->expiry_date,
                        ];
                    })->values()->toArray(),
                ];
            }

            $rows[] = [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'store_address' => $store->address,
                'store_code' => $store->store_code,
                'store_type' => $store->is_warehouse ? 'warehouse' : ($store->is_online ? 'online' : 'store'),
                'is_warehouse' => (bool) $store->is_warehouse,
                'is_online' => (bool) $store->is_online,
                'inventory_details' => $inventoryDetails,
                'total_items_available' => collect($inventoryDetails)->where('can_fulfill', true)->count(),
                'total_items_required' => count($inventoryDetails),
                'total_required_quantity' => $totalRequiredQty,
                'fulfillable_quantity' => $fulfillableQty,
                'can_fulfill_entire_order' => $canFulfillEntireOrder,
                'fulfillment_percentage' => $totalRequiredQty > 0
                    ? min(100, round(($fulfillableQty / $totalRequiredQty) * 100, 2))
                    : 0,
            ];
        }

        usort($rows, function ($a, $b) {
            if ($a['can_fulfill_entire_order'] !== $b['can_fulfill_entire_order']) {
                return $b['can_fulfill_entire_order'] <=> $a['can_fulfill_entire_order'];
            }

            return $b['fulfillment_percentage'] <=> $a['fulfillment_percentage'];
        });

        return $rows;
    }

    /**
     * Assign order to a specific store
     */
    public function assignOrderToStore(Request $request, $orderId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'store_id' => 'required|exists:stores,id',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $order = Order::with('items.product')->findOrFail($orderId);

            if ($order->status !== 'pending_assignment') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is not pending assignment',
                ], 400);
            }

            $storeId = $request->store_id;
            $store = Store::findOrFail($storeId);

            // Double check TRUE inventory availability at the moment of assignment.
            // Keep this in the exact same barcode-aware path used by the store
            // assignment matrix. This avoids the old batch-only false negative
            // where Product List/Lookup showed stock, but assignment still failed.
            $availabilityRows = $this->buildStoreFulfillmentRowsForOrder($order, collect([$store]));
            $storeAvailability = $availabilityRows[0] ?? null;

            if (!$storeAvailability || empty($storeAvailability['can_fulfill_entire_order'])) {
                $blockingProducts = collect($storeAvailability['inventory_details'] ?? [])
                    ->filter(fn ($detail) => empty($detail['can_fulfill']))
                    ->map(function ($detail) {
                        return [
                            'product_id' => $detail['product_id'] ?? null,
                            'product_name' => $detail['product_name'] ?? 'Unknown Product',
                            'required' => $detail['required_quantity'] ?? 0,
                            'physical_quantity' => $detail['physical_quantity'] ?? 0,
                            'batch_physical_quantity' => $detail['batch_physical_quantity'] ?? 0,
                            'sellable_barcode_quantity' => $detail['sellable_barcode_quantity'] ?? 0,
                            'assigned_to_other_orders' => $detail['assigned_quantity'] ?? 0,
                            'unbarcoded_assigned_to_other_orders' => $detail['unbarcoded_assigned_quantity'] ?? 0,
                            'assigned_quantity_subtracted' => $detail['assigned_quantity_subtracted'] ?? 0,
                            'actually_free' => $detail['available_quantity'] ?? 0,
                            'stock_source' => $detail['stock_source'] ?? null,
                        ];
                    })
                    ->values();

                return response()->json([
                    'success' => false,
                    'message' => "Insufficient real-time inventory at {$store->name}. The availability check is barcode-aware; see blocking_products for the exact reason.",
                    'data' => [
                        'store_id' => $store->id,
                        'store_name' => $store->name,
                        'fulfillment_percentage' => $storeAvailability['fulfillment_percentage'] ?? 0,
                        'blocking_products' => $blockingProducts,
                    ],
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Note: Stock batches will be determined dynamically during the barcode scanning phase at the branch.
                // Reserved inventory remains untouched; it will be released during barcode scanning.


                // Update order status to assigned_to_store
                $order->update([
                    'store_id' => $storeId,
                    'status' => 'assigned_to_store',
                    'fulfillment_status' => 'pending_fulfillment', // Required for warehouse fulfillment workflow
                    'processed_by' => auth('api')->id(),
                    'metadata' => array_merge($order->metadata ?? [], [
                        'assigned_at' => now()->toISOString(),
                        'assigned_by' => auth('api')->id(),
                        'assignment_notes' => $request->notes,
                    ]),
                ]);

                // Keep item-level store assignment consistent with the order-level assignment.
                // Bulk assignment already did this; single assignment must do the same so
                // packing/fulfillment and reporting never see a half-assigned order.
                $order->items()->update(['store_id' => $storeId]);

                DB::commit();

                $order->load(['customer', 'items.product', 'store']);

                return response()->json([
                    'success' => true,
                    'message' => "Order successfully assigned to {$store->name}",
                    'data' => [
                        'order' => $order,
                    ],
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign order to store',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get recommendation for best store to assign order
     */
    private function getRecommendation(array $storeInventory): ?array
    {
        if (empty($storeInventory)) {
            return null;
        }

        // Find stores that can fulfill entire order
        $canFulfillStores = array_filter($storeInventory, function($store) {
            return $store['can_fulfill_entire_order'];
        });

        if (empty($canFulfillStores)) {
            // No store can fulfill entire order
            // Recommend store with highest fulfillment percentage
            $bestStore = reset($storeInventory);
            return [
                'store_id' => $bestStore['store_id'],
                'store_name' => $bestStore['store_name'],
                'reason' => 'Highest partial fulfillment capability',
                'fulfillment_percentage' => $bestStore['fulfillment_percentage'],
                'note' => 'Consider splitting order or restocking before assignment',
            ];
        }

        // Among stores that can fulfill, find the one with the earliest expiring required batch
        $bestStore = null;
        $earliestExpiry = null;
        
        foreach ($canFulfillStores as $store) {
            $storeEarliest = null;
            // Get expiry of the batches this store would use for exact variant ID
            foreach ($store['inventory_details'] ?? [] as $detail) {
                foreach ($detail['batches'] ?? [] as $batch) {
                    if (!empty($batch['expiry_date'])) {
                        $expiryTime = strtotime($batch['expiry_date']);
                        if ($storeEarliest === null || $expiryTime < $storeEarliest) {
                            $storeEarliest = $expiryTime;
                        }
                    }
                }
            }
            
            // If this store has an earlier expiry than our current best, or if we haven't found one yet
            if (!$bestStore || ($storeEarliest !== null && ($earliestExpiry === null || $storeEarliest < $earliestExpiry))) {
                $earliestExpiry = $storeEarliest;
                $bestStore = $store;
            }
        }
        
        // Fallback to the first store if logic failed
        if (!$bestStore) {
            $bestStore = reset($canFulfillStores);
        }

        return [
            'store_id' => $bestStore['store_id'],
            'store_name' => $bestStore['store_name'],
            'reason' => 'Can fulfill entire order' . ($earliestExpiry ? ' (Optimized FIFO expiry)' : ''),
            'fulfillment_percentage' => 100,
        ];
    }

    /**
     * Revert order back to pending_assignment
     */
    public function revertAssignment(Request $request, $orderId)
    {
        try {
            DB::beginTransaction();

            $order = Order::with('items')->findOrFail($orderId);

            $oldStatus = $order->status;
            $oldStoreId = $order->store_id;
            $oldFulfillmentStatus = $order->fulfillment_status;

            // Safety guard: only a clean store-assigned order can be reverted.
            // Once picking/scanning/batch allocation has started, assignment rollback must
            // go through a dedicated fulfillment cancellation flow so stock/barcode state
            // cannot be silently cleared by mistake.
            if ($order->status !== 'assigned_to_store' || empty($order->store_id)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Only clean assigned_to_store orders with a store can be reverted to pending assignment.',
                ], 422);
            }

            $hasScannedOrReservedItems = $order->items()
                ->where(function ($q) {
                    $q->whereNotNull('product_barcode_id')
                        ->orWhereNotNull('product_batch_id');
                })
                ->exists();

            if ($hasScannedOrReservedItems) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'This order already has barcode/batch activity. Use a fulfillment cancellation flow instead of reverting assignment.',
                ], 422);
            }

            // 1. Handle stock restoration if order was already "deducted" (e.g. from OrderController@complete)
            // Deducted statuses usually include 'confirmed', 'delivered'
            $deductedStatuses = ['confirmed', 'delivered'];
            $isDeducted = in_array($oldStatus, $deductedStatuses);

            foreach ($order->items as $item) {
                // a. Handle Barcodes
                if ($item->product_barcode_id) {
                    $barcode = ProductBarcode::find($item->product_barcode_id);
                    if ($barcode) {
                        // Reset barcode status to be available again in the shop
                        $barcode->update([
                            'is_active' => true,
                            'current_status' => 'in_shop',
                            'location_updated_at' => now(),
                        ]);
                    }
                }

                // b. Restore Physical Stock if it was deducted
                if ($isDeducted) {
                    if ($item->product_batch_id) {
                        $batch = ProductBatch::find($item->product_batch_id);
                        if ($batch) {
                            $batch->increment('quantity', $item->quantity);
                        }
                    }
                }

                // Clear barcode/batch assignments from order item
                $item->update([
                    'product_barcode_id' => null,
                    'product_batch_id' => null,
                ]);
            }

            // 2. Reset core order fields
            $order->status = 'pending_assignment';
            $order->store_id = null;
            $order->fulfillment_status = null;
            $order->confirmed_at = null;
            $order->fulfilled_at = null;
            $order->fulfilled_by = null;

            $order->metadata = array_merge($order->metadata ?? [], [
                'reverted_at' => now()->toISOString(),
                'reverted_by' => auth('api')->id(),
                'reverted_from_status' => $oldStatus,
                'reverted_from_store' => $oldStoreId,
            ]);

            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order assignment successfully reverted and stock restored.',
                'data' => [
                    'order' => $order->load(['customer', 'items.product']),
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to revert order assignment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Rescue a mistakenly plain pending online order back into pending_assignment.
     * Used from Orders page for the rare social-commerce/e-commerce orders that
     * were downgraded by stale edit state before the workflow guard existed.
     */
    public function markAsPendingAssignment(Request $request, $orderId)
    {
        try {
            DB::beginTransaction();

            $order = Order::with(['items.product', 'customer'])
                ->lockForUpdate()
                ->findOrFail($orderId);

            if (!in_array($order->order_type, ['social_commerce', 'ecommerce'], true)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Only social-commerce or e-commerce orders can be moved to pending_assignment.',
                ], 422);
            }

            if ($order->status === 'pending_assignment' && empty($order->store_id)) {
                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Order is already pending assignment.',
                    'data' => ['order' => $order->load(['customer', 'items.product'])],
                ]);
            }

            if ($order->status !== 'pending') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Only plain pending orders can be rescued with this button. Current status: {$order->status}.",
                ], 422);
            }

            if (!empty($order->store_id)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'This order already has a store. Use Revert Assignment for assigned orders.',
                ], 422);
            }

            if ($order->items()->count() < 1) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'This order has no product items, so it cannot enter store assignment.',
                ], 422);
            }

            $oldStatus = $order->status;
            $oldFulfillmentStatus = $order->fulfillment_status;

            // Recalculate from fresh DB rows first; then set assignment workflow.
            $order->calculateTotals();
            $order->refresh();

            $order->forceFill([
                'status' => 'pending_assignment',
                'store_id' => null,
                'fulfillment_status' => null,
                'confirmed_at' => null,
                'fulfilled_at' => null,
                'fulfilled_by' => null,
                'metadata' => array_merge($order->metadata ?? [], [
                    'rescued_to_pending_assignment_at' => now()->toISOString(),
                    'rescued_to_pending_assignment_by' => auth('api')->id() ?: auth()->id(),
                    'rescued_from_status' => $oldStatus,
                    'rescued_from_fulfillment_status' => $oldFulfillmentStatus,
                    'rescue_source' => 'orders_page_button',
                ]),
            ])->save();

            foreach ($order->items()->pluck('product_id')->filter()->unique() as $productId) {
                app(InventoryReservationService::class)->syncProduct((int) $productId);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order moved to pending_assignment and is now available for store assignment.',
                'data' => [
                    'order' => $order->fresh(['customer', 'items.product']),
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to move order to pending assignment.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark order as delivered manually
     */
    public function markAsDelivered(Request $request, $orderId)
    {
        try {
            DB::beginTransaction();

            $order = Order::findOrFail($orderId);

            // Validation: Only confirmed (completed) or fulfilled orders can be marked as delivered
            if ($order->status !== 'confirmed' && $order->fulfillment_status !== 'fulfilled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only confirmed or fulfilled orders can be marked as delivered.',
                ], 422);
            }

            if ($order->status === 'delivered') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is already marked as delivered.',
                ], 422);
            }

            $order->status = 'delivered';
            $order->delivered_at = now();
            
            $order->metadata = array_merge($order->metadata ?? [], [
                'delivered_at' => now()->toISOString(),
                'delivered_by' => auth('api')->id(),
                'delivery_manual_mark' => true,
            ]);

            $order->save();

            // Record purchase for customer history
            if ($order->customer) {
                $order->customer->recordPurchase($order->total_amount, $order->id);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order successfully marked as delivered.',
                'data' => [
                    'order' => $order->load(['customer', 'items.product']),
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark order as delivered.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark multiple orders as delivered
     * 
     * POST /api/order-management/orders/bulk-mark-as-delivered
     */
    public function bulkMarkAsDelivered(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_ids' => 'required|array|min:1',
                'order_ids.*' => 'exists:orders,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $orderIds = $request->order_ids;
            $results = [
                'success' => [],
                'failed' => [],
            ];

            foreach ($orderIds as $orderId) {
                try {
                    DB::beginTransaction();

                    $order = Order::findOrFail($orderId);

                    // Validation same as single markAsDelivered
                    if ($order->status !== 'confirmed' && $order->fulfillment_status !== 'fulfilled') {
                        throw new \Exception('Order must be confirmed or fulfilled to be marked as delivered.');
                    }

                    if ($order->status === 'delivered') {
                        throw new \Exception('Order is already marked as delivered.');
                    }

                    $order->status = 'delivered';
                    $order->delivered_at = now();
                    
                    $order->metadata = array_merge($order->metadata ?? [], [
                        'delivered_at' => now()->toISOString(),
                        'delivered_by' => auth('api')->id(),
                        'delivery_manual_mark' => true,
                        'bulk_process' => true,
                    ]);

                    $order->save();

                    // Record purchase for customer history
                    if ($order->customer) {
                        $order->customer->recordPurchase($order->total_amount, $order->id);
                    }

                    DB::commit();

                    $results['success'][] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                    ];

                } catch (\Exception $e) {
                    DB::rollBack();
                    $results['failed'][] = [
                        'order_id' => $orderId,
                        'order_number' => Order::find($orderId)->order_number ?? 'Unknown',
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            $successCount = count($results['success']);
            $failedCount = count($results['failed']);

            return response()->json([
                'success' => true,
                'message' => "Bulk delivery completed: $successCount succeeded, $failedCount failed.",
                'data' => $results,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk delivery',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

