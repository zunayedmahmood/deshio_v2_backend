<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBarcode;
use App\Models\ProductBatch;
use App\Models\Employee;
use App\Models\ReservedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FloatingBarcodeRelabelService;
use Illuminate\Support\Facades\Validator;

class StoreFulfillmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api'); // Employee authentication
    }

    /**
     * Get orders assigned to employee's store
     */
    public function getAssignedOrders(Request $request): JsonResponse
    {
        try {
            $employeeId = auth('api')->id();
            $employee = Employee::with('store')->findOrFail($employeeId);

            if (!$employee->store_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee is not assigned to a store',
                ], 400);
            }

            $status = $request->query('status', 'assigned_to_store,picking');
            $perPage = $request->query('per_page', 15);

            // Convert comma-separated statuses to array
            $statuses = explode(',', $status);

            $orders = Order::where('store_id', $employee->store_id)
                ->whereIn('status', $statuses)
                ->whereIn('order_type', ['ecommerce', 'social_commerce'])

                ->with([
                    'customer',
                    'items.product.images',
                    'items.product.barcodes' => function($query) use ($employee) {
                        $query->where('current_store_id', $employee->store_id)
                              ->where('current_status', 'in_shop');
                    },
                ])
                ->orderBy('created_at', 'asc')
                ->paginate($perPage);

            // Add fulfillment progress for each order
            foreach ($orders as $order) {
                $totalItems = $order->items->sum('quantity');
                $fulfilledItems = $order->items->sum(function($item) {
                    return !is_null($item->product_barcode_id) ? (int) $item->quantity : 0;
                });

                $order->fulfillment_progress = [
                    'total_items' => $totalItems,
                    'fulfilled_items' => $fulfilledItems,
                    'pending_items' => $totalItems - $fulfilledItems,
                    'percentage' => $totalItems > 0 ? round(($fulfilledItems / $totalItems) * 100, 2) : 0,
                    'is_complete' => $fulfilledItems === $totalItems,
                ];

                // Add item scan status
                $order->items->each(function($item) {
                    $item->scan_status = $item->product_barcode_id ? 'scanned' : 'pending';
                    $item->available_barcodes_count = $item->product->barcodes->count();
                });
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'store' => [
                        'id' => $employee->store->id,
                        'name' => $employee->store->name,
                        'address' => $employee->store->address,
                    ],
                    'orders' => $orders->items(),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'total_pages' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                    ],
                    'summary' => [
                        'total_orders' => $orders->total(),
                        'assigned_to_store_count' => Order::where('store_id', $employee->store_id)
                            ->where('status', 'assigned_to_store')
                            ->count(),
                        'picking_count' => Order::where('store_id', $employee->store_id)
                            ->where('status', 'picking')
                            ->count(),
                        'ready_for_shipment_count' => Order::where('store_id', $employee->store_id)
                            ->where('status', 'ready_for_shipment')
                            ->count(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch assigned orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific order details for fulfillment
     */
    public function getOrderDetails($orderId): JsonResponse
    {
        try {
            $employeeId = auth('api')->id();
            $employee = Employee::with('store')->findOrFail($employeeId);

            $order = Order::where('id', $orderId)
                ->where('store_id', $employee->store_id)
                ->with([
                    'customer',
                    'items.product.images',
                    'items.product.barcodes' => function($query) use ($employee) {
                        $query->where('current_store_id', $employee->store_id)
                              ->where('current_status', 'in_shop');
                    },
                    'items.barcode', // Already scanned barcode
                ])
                ->firstOrFail();

            // Add fulfillment details for each item
            $order->items->each(function($item) use ($employee) {
                $item->scan_status = $item->product_barcode_id ? 'scanned' : 'pending';
                $item->scanned_barcode = $item->barcode;
                $item->available_barcodes = $item->product->barcodes;
                $item->available_count = $item->product->barcodes->count();
            });

            $totalItems = $order->items->sum('quantity');
            $fulfilledItems = $order->items->sum(fn($item) => !is_null($item->product_barcode_id) ? (int) $item->quantity : 0);

            return response()->json([
                'success' => true,
                'data' => [
                    'order' => $order,
                    'fulfillment_status' => [
                        'total_items' => $totalItems,
                        'fulfilled_items' => $fulfilledItems,
                        'pending_items' => $totalItems - $fulfilledItems,
                        'percentage' => $totalItems > 0 ? round(($fulfilledItems / $totalItems) * 100, 2) : 0,
                        'is_complete' => $fulfilledItems === $totalItems,
                        'can_ship' => $fulfilledItems === $totalItems,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Scan barcode to fulfill order item
     */
    public function scanBarcode(Request $request, $orderId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'barcode' => 'required|string',
                'order_item_id' => 'required|exists:order_items,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $employeeId = auth('api')->id();
            $employee = Employee::with('store')->findOrFail($employeeId);

            $order = Order::where('id', $orderId)
                ->where('store_id', $employee->store_id)
                ->whereIn('status', ['assigned_to_store', 'picking', 'ready_for_shipment', 'confirmed'])

                ->firstOrFail();

            $orderItem = OrderItem::where('id', $request->order_item_id)
                ->where('order_id', $orderId)
                ->with('product')
                ->firstOrFail();

            // Check if item already scanned
            if ($orderItem->product_barcode_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order item has already been scanned',
                    'data' => [
                        'scanned_barcode' => $orderItem->barcode->barcode ?? null,
                    ],
                ], 400);
            }

            // 1. Find and validate barcode. Replacement barcodes are normal scan identities
            // for the same product/batch/store pool and do not increase stock.
            $barcode = ProductBarcode::where('barcode', $request->barcode)
                ->where('current_store_id', $employee->store_id)
                ->whereIn('current_status', FloatingBarcodeRelabelService::SELLABLE_STATUSES)
                ->with(['product', 'batch'])
                ->first();

            if (!$barcode) {
                return response()->json([
                    'success' => false,
                    'message' => 'Barcode not found or not available in this store',
                ], 404);
            }

            app(FloatingBarcodeRelabelService::class)->validateBarcodeCanBeSold($barcode, $order, $orderItem->id);

            // 2. Validate barcode belongs to the correct product
            if ($barcode->product_id !== $orderItem->product_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Scanned barcode does not match the order item product',
                    'data' => [
                        'expected_product' => $orderItem->product->name,
                        'scanned_product' => $barcode->product->name,
                    ],
                ], 400);
            }

            // NOTE: We do NOT enforce batch_id matching here.
            // If the order item had a different batch assigned (or no batch), 
            // we update it to the batch associated with the physical barcode scanned.

            DB::beginTransaction();

            try {
                // 3. PHYSICAL STOCK DEDUCTION REMOVED
                // Stock will be deducted centralizing in OrderController@complete
                // Reservations are also released in OrderController@complete to ensure available_stock sync
                Log::info('Barcode scanned, stock deduction deferred to completion', [
                    'order_id' => $order->id,
                    'product_id' => $barcode->product_id,
                    'barcode' => $barcode->barcode,
                ]);

                // 5. Attach exactly one scanned barcode to exactly one order-item row.
                // If the line quantity is greater than 1, split off one scanned unit and
                // keep the remaining quantity pending for the next barcode scan.
                if ($orderItem->quantity > 1) {
                    $originalQuantity = $orderItem->quantity;
                    $remainingQuantity = $originalQuantity - 1;
                    $discountPerUnit = $originalQuantity > 0 ? ((float) $orderItem->discount_amount / $originalQuantity) : 0;
                    $taxPerUnit = $originalQuantity > 0 ? ((float) $orderItem->tax_amount / $originalQuantity) : 0;
                    $cogsPerUnit = $originalQuantity > 0 ? ((float) ($orderItem->cogs ?? 0) / $originalQuantity) : 0;

                    $orderItem->update([
                        'quantity' => $remainingQuantity,
                        'discount_amount' => round($discountPerUnit * $remainingQuantity, 2),
                        'tax_amount' => round($taxPerUnit * $remainingQuantity, 2),
                        'cogs' => round($cogsPerUnit * $remainingQuantity, 2),
                        'total_amount' => round(((float) $orderItem->unit_price * $remainingQuantity) - ($discountPerUnit * $remainingQuantity) + ($taxPerUnit * $remainingQuantity), 2),
                    ]);

                    $scannedOrderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $orderItem->product_id,
                        'product_batch_id' => $barcode->batch_id,
                        'product_barcode_id' => $barcode->id,
                        'store_id' => $orderItem->store_id,
                        'product_name' => $orderItem->product_name,
                        'product_sku' => $orderItem->product_sku,
                        'quantity' => 1,
                        'unit_price' => $orderItem->unit_price,
                        'discount_amount' => round($discountPerUnit, 2),
                        'tax_amount' => round($taxPerUnit, 2),
                        'cogs' => round($cogsPerUnit, 2),
                        'total_amount' => round((float) $orderItem->unit_price - $discountPerUnit + $taxPerUnit, 2),
                        'product_options' => $orderItem->product_options,
                        'notes' => $orderItem->notes,
                    ]);
                } else {
                    $orderItem->update([
                        'product_barcode_id' => $barcode->id,
                        'product_batch_id' => $barcode->batch_id, // Sync with the actual physical batch
                    ]);
                    $scannedOrderItem = $orderItem;
                }

                // Update order status to picking if this is first scan or if a ready order is edited/re-scanned
                if (in_array($order->status, ['assigned_to_store', 'confirmed', 'ready_for_shipment'])) {
                    $order->update(['status' => 'picking']);
                }

                // Check if all quantity units are scanned
                $allItemsScanned = $order->items()->whereNull('product_barcode_id')->count() === 0;
                
                if ($allItemsScanned) {
                    $order->update([
                        'status' => 'ready_for_shipment',
                        'fulfillment_status' => 'fulfilled',
                        'fulfilled_at' => now(),
                        'fulfilled_by' => $employeeId,
                    ]);
                }

                DB::commit();

                // Reload relationships
                $scannedOrderItem->load('barcode', 'batch');
                $order->load('items');

                $fulfilledItems = $order->items->sum(fn($item) => !is_null($item->product_barcode_id) ? (int) $item->quantity : 0);
                $totalItems = $order->items->sum('quantity');

                return response()->json([
                    'success' => true,
                    'message' => 'Barcode scanned successfully',
                    'data' => [
                        'order_item' => $scannedOrderItem,
                        'scanned_barcode' => $barcode,
                        'order_status' => $order->status,
                        'fulfillment_progress' => [
                            'fulfilled_items' => $fulfilledItems,
                            'total_items' => $totalItems,
                            'percentage' => $totalItems > 0 ? round(($fulfilledItems / $totalItems) * 100, 2) : 0,
                            'is_complete' => $fulfilledItems === $totalItems,
                        ],
                    ],
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to scan barcode',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark order as ready for shipment manually
     */
    public function markReadyForShipment($orderId): JsonResponse
    {
        try {
            $employeeId = auth('api')->id();
            $employee = Employee::with('store')->findOrFail($employeeId);

            $order = Order::where('id', $orderId)
                ->where('store_id', $employee->store_id)
                ->with('items')
                ->firstOrFail();

            DB::beginTransaction();
            try {
                $unscannedItems = $order->items()->whereNull('product_barcode_id')->get();

                // Stock deduction and reservation release moved to OrderController@complete
                Log::info("Order status update to ready_for_shipment, deduction deferred to completion", [
                    'order_number' => $order->order_number
                ]);

                $order->update([
                    'status' => 'ready_for_shipment',
                    'fulfillment_status' => 'fulfilled',
                    'fulfilled_at' => now(),
                    'fulfilled_by' => $employeeId,
                ]);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'success' => true,
                'message' => 'Order marked as ready for shipment',
                'data' => ['order' => $order],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark order as ready for shipment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

