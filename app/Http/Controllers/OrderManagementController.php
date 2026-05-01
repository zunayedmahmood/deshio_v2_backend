<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ProductBatch;
use App\Models\ReservedProduct;
use App\Models\ProductBarcode;
use App\Models\Store;
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
     * Get orders pending store assignment
     * Includes both ecommerce and social_commerce orders
     */
    public function getPendingAssignmentOrders(Request $request): JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);
            $sortOrder = $request->query('sort_order', 'asc');
            $status = $request->query('status', 'pending_assignment');
            
            // Validate sort order to prevent SQL injection or invalid values
            if (!in_array(strtolower($sortOrder), ['asc', 'desc'])) {
                $sortOrder = 'asc';
            }
            
            $orders = Order::where('status', $status)
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

            // Get all active online stores (warehouses can also fulfill if marked is_online = true)
            $stores = Store::where('is_online', true)->get();

            $productIds = $order->items->pluck('product_id')->unique()->toArray();

            // 1. Fetch Global Reserved Inventory View
            $reservedProducts = ReservedProduct::whereIn('product_id', $productIds)
                ->get()
                ->keyBy('product_id');

            // 2. Fetch Physical Inventory View (Per Store/Product)
            // Filter batches by availability and expiry to match frontend logic
            $batches = ProductBatch::whereIn('product_id', $productIds)
                ->where('availability', true)
                ->where('quantity', '>', 0)
                ->where(function($query) {
                    $query->whereNull('expiry_date')
                        ->orWhere('expiry_date', '>', now());
                })
                ->get()
                ->groupBy(['store_id', 'product_id']);

            // 3. Fetch Already Assigned (But Not Yet Deducted) Orders for these products
            // Deduction from batches happens when status becomes 'confirmed' or 'delivered' or 'cancelled' etc.
            // We need to know which quantities are already promised to specific stores.
            $deductedStatuses = ['confirmed', 'delivered', 'cancelled', 'returned'];
            $assignedOrders = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereIn('order_items.product_id', $productIds)
                ->whereNotNull('orders.store_id')
                ->whereNotIn('orders.status', $deductedStatuses)
                ->whereNull('orders.deleted_at')
                ->where('orders.id', '!=', $order->id) // Exclude current order if re-assigning
                ->select('orders.store_id', 'order_items.product_id', DB::raw('SUM(order_items.quantity) as total_assigned'))
                ->groupBy('orders.store_id', 'order_items.product_id')
                ->get()
                ->groupBy('store_id');

            $storeInventory = [];

            foreach ($stores as $store) {
                $canFulfillEntireOrder = true;
                $storeData = [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'store_address' => $store->address,
                    'inventory_details' => [],
                    'total_items_available' => 0,
                    'total_items_required' => $order->items->sum('quantity'),
                ];

                $assignedStoreData = $assignedOrders->get($store->id, collect())->keyBy('product_id');

                foreach ($order->items as $orderItem) {
                    $productId = $orderItem->product_id;
                    $requiredQuantity = $orderItem->quantity;

                    // Physical stock in this store for this product
                    $productBatchesInStore = $batches->get($store->id, collect())->get($productId, collect());
                    $totalPhysicalInStore = $productBatchesInStore->sum('quantity');

                    // Already assigned to this store (from other pending/processing orders)
                    $alreadyAssignedInStore = $assignedStoreData->get($productId)->total_assigned ?? 0;

                    // TRUE Available in this store for this specific order
                    $actuallyAvailableInStore = max(0, $totalPhysicalInStore - $alreadyAssignedInStore);

                    // Global stats from ReservedProduct for context
                    $globalReserved = $reservedProducts->get($productId);
                    $globalAvailable = $globalReserved ? $globalReserved->available_inventory : 0;

                    $inventoryDetail = [
                        'product_id' => $productId,
                        'product_name' => $orderItem->product_name,
                        'product_sku' => $orderItem->product_sku,
                        'required_quantity' => $requiredQuantity,
                        'physical_quantity' => $totalPhysicalInStore,
                        'assigned_quantity' => $alreadyAssignedInStore,
                        'available_quantity' => $actuallyAvailableInStore, // Store-specific true available
                        'global_available' => $globalAvailable,
                        'can_fulfill' => $actuallyAvailableInStore >= $requiredQuantity,
                        'batches' => $productBatchesInStore->map(function($batch) {
                            return [
                                'batch_id' => $batch->id,
                                'batch_number' => $batch->batch_number,
                                'quantity' => $batch->quantity,
                                'sell_price' => $batch->sell_price,
                                'expiry_date' => $batch->expiry_date,
                            ];
                        })->values(),
                    ];

                    $storeData['inventory_details'][] = $inventoryDetail;
                    $storeData['total_items_available'] += $actuallyAvailableInStore;

                    if ($actuallyAvailableInStore < $requiredQuantity) {
                        $canFulfillEntireOrder = false;
                    }
                }

                $storeData['can_fulfill_entire_order'] = $canFulfillEntireOrder;
                $storeData['fulfillment_percentage'] = $storeData['total_items_required'] > 0
                    ? min(100, round(($storeData['total_items_available'] / $storeData['total_items_required']) * 100, 2))
                    : 0;

                $storeInventory[] = $storeData;
            }

            // Sort by fulfillment capability (stores that can fulfill entire order first)
            usort($storeInventory, function($a, $b) {
                if ($a['can_fulfill_entire_order'] !== $b['can_fulfill_entire_order']) {
                    return $b['can_fulfill_entire_order'] <=> $a['can_fulfill_entire_order'];
                }
                return $b['fulfillment_percentage'] <=> $a['fulfillment_percentage'];
            });

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

            // Double check TRUE inventory availability at the moment of assignment
            // This prevents race conditions or overlapping assignments
            $productIds = $order->items->pluck('product_id')->unique()->toArray();
            
            // 1. Current Physical Stock
            $physicalStock = ProductBatch::whereIn('product_id', $productIds)
                ->where('store_id', $storeId)
                ->where('availability', true)
                ->where('quantity', '>', 0)
                ->where(function($query) {
                    $query->whereNull('expiry_date')
                        ->orWhere('expiry_date', '>', now());
                })
                ->select('product_id', DB::raw('SUM(quantity) as total'))
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');

            // 2. Current Assigned (Promised) Quantities
            $deductedStatuses = ['confirmed', 'delivered', 'cancelled', 'returned'];
            $assignedQuantityMap = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereIn('order_items.product_id', $productIds)
                ->where('orders.store_id', $storeId)
                ->whereNotIn('orders.status', $deductedStatuses)
                ->whereNull('orders.deleted_at')
                ->where('orders.id', '!=', $order->id)
                ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as total'))
                ->groupBy('order_items.product_id')
                ->get()
                ->keyBy('product_id');

            foreach ($order->items as $orderItem) {
                $pid = $orderItem->product_id;
                $pStock = $physicalStock->get($pid)->total ?? 0;
                $aStock = $assignedQuantityMap->get($pid)->total ?? 0;
                $actualAvailable = max(0, $pStock - $aStock);

                if ($actualAvailable < $orderItem->quantity) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient real-time inventory for '{$orderItem->product_name}' at {$store->name} due to other recent assignments.",
                        'data' => [
                            'product' => $orderItem->product_name,
                            'required' => $orderItem->quantity,
                            'physically_present' => $pStock,
                            'assigned_to_other_orders' => $aStock,
                            'actually_free' => $actualAvailable,
                        ],
                    ], 400);
                }
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
                    // Update Batch Quantity
                    if ($item->product_batch_id) {
                        $batch = ProductBatch::find($item->product_batch_id);
                        if ($batch) {
                            $batch->increment('quantity', $item->quantity);
                        }
                    }

                    // Update Global Reserved Stats (Total and Reserved)
                    if ($reserved = ReservedProduct::where('product_id', $item->product_id)->first()) {
                        $reserved->increment('total_inventory', $item->quantity);
                        $reserved->increment('reserved_inventory', $item->quantity);
                        
                        $reserved->fresh();
                        $reserved->available_inventory = $reserved->total_inventory - $reserved->reserved_inventory;
                        $reserved->save();
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

