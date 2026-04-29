<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBatch;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * MultiStoreOrderController
 * 
 * Handles orders where different items are fulfilled from different stores.
 * This is NEW functionality that doesn't affect existing single-store order flow.
 * 
 * Use Case:
 * - Customer orders Product A, B, C
 * - Product A only available at Store 1
 * - Product B only available at Store 2
 * - Product C only available at Store 3
 * - System assigns each item to appropriate store
 * - Each store fulfills their assigned items
 */
class MultiStoreOrderController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api'); // Employee authentication
    }

    /**
     * Get store availability for each item in an order
     * Shows which stores have which products
     * 
     * GET /api/orders/{orderId}/multi-store-availability
     */
    public function getItemStoreAvailability($orderId): JsonResponse
    {
        try {
            $order = Order::with('items.product')->findOrFail($orderId);

            if ($order->store_id !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is already assigned to a single store. Use multi-store flow only for unassigned orders.',
                    'assigned_store' => $order->store_id,
                ], 400);
            }

            $itemAvailability = [];
            
            foreach ($order->items as $item) {
                // Get all stores that have this product in stock
                $storesWithStock = DB::table('product_batches')
                    ->join('stores', 'product_batches.store_id', '=', 'stores.id')
                    ->where('product_batches.product_id', $item->product_id)
                    ->where('product_batches.quantity', '>=', $item->quantity)
                    ->where('product_batches.availability', true)
                    ->whereNull('product_batches.deleted_at')
                    ->select(
                        'stores.id as store_id',
                        'stores.name as store_name',
                        'stores.address as store_address',
                        DB::raw('SUM(product_batches.quantity) as total_available')
                    )
                    ->groupBy('stores.id', 'stores.name', 'stores.address')
                    ->having('total_available', '>=', $item->quantity)
                    ->get();

                $itemAvailability[] = [
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity_required' => $item->quantity,
                    'current_store_assignment' => $item->store_id,
                    'available_stores' => $storesWithStock->map(function($store) use ($item) {
                        return [
                            'store_id' => $store->store_id,
                            'store_name' => $store->store_name,
                            'store_address' => $store->store_address,
                            'quantity_available' => $store->total_available,
                            'can_fulfill' => $store->total_available >= $item->quantity,
                        ];
                    }),
                    'stores_count' => $storesWithStock->count(),
                ];
            }

            // Determine if order can be fulfilled
            $allItemsCanBeFulfilled = collect($itemAvailability)->every(fn($item) => $item['stores_count'] > 0);
            $needsMultiStore = collect($itemAvailability)->filter(fn($item) => $item['stores_count'] > 0)->groupBy(fn($item) => $item['available_stores'][0]['store_id'] ?? null)->count() > 1;

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'can_be_fulfilled' => $allItemsCanBeFulfilled,
                    'requires_multi_store' => $needsMultiStore,
                    'items' => $itemAvailability,
                    'summary' => [
                        'total_items' => count($itemAvailability),
                        'items_with_stock' => collect($itemAvailability)->filter(fn($item) => $item['stores_count'] > 0)->count(),
                        'items_without_stock' => collect($itemAvailability)->filter(fn($item) => $item['stores_count'] === 0)->count(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get item availability',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Automatically assign stores to order items based on inventory
     * Uses smart algorithm to minimize number of stores
     * 
     * POST /api/orders/{orderId}/auto-assign-stores
     */
    public function autoAssignStores($orderId): JsonResponse
    {
        try {
            $order = Order::with('items.product')->findOrFail($orderId);

            if ($order->store_id !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is already assigned to a single store',
                ], 400);
            }

            DB::beginTransaction();

            $assignments = [];
            $unassignableItems = [];

            foreach ($order->items as $item) {
                // Find best store for this item (with highest stock)
                $bestStore = DB::table('product_batches')
                    ->join('stores', 'product_batches.store_id', '=', 'stores.id')
                    ->where('product_batches.product_id', $item->product_id)
                    ->where('product_batches.quantity', '>=', $item->quantity)
                    ->where('product_batches.availability', true)
                    ->whereNull('product_batches.deleted_at')
                    ->select(
                        'stores.id as store_id',
                        'stores.name as store_name',
                        DB::raw('SUM(product_batches.quantity) as total_available')
                    )
                    ->groupBy('stores.id', 'stores.name')
                    ->having('total_available', '>=', $item->quantity)
                    ->orderBy('total_available', 'desc')
                    ->first();

                if ($bestStore) {
                    // Assign this item to the store
                    $item->update(['store_id' => $bestStore->store_id]);
                    
                    $assignments[] = [
                        'order_item_id' => $item->id,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'assigned_store_id' => $bestStore->store_id,
                        'assigned_store_name' => $bestStore->store_name,
                        'available_quantity' => $bestStore->total_available,
                    ];
                } else {
                    $unassignableItems[] = [
                        'order_item_id' => $item->id,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'reason' => 'No store has sufficient stock',
                    ];
                }
            }

            if (!empty($unassignableItems)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Some items cannot be assigned due to insufficient stock',
                    'unassignable_items' => $unassignableItems,
                ], 400);
            }

            // Update order status
            $order->update([
                'status' => 'multi_store_assigned',
                'fulfillment_status' => 'pending_fulfillment',
                'metadata' => array_merge($order->metadata ?? [], [
                    'multi_store_assignment' => [
                        'assigned_at' => now()->toISOString(),
                        'assigned_by' => auth('api')->id(),
                        'type' => 'auto',
                        'stores_involved' => collect($assignments)->pluck('assigned_store_id')->unique()->values()->toArray(),
                    ],
                ]),
            ]);

            DB::commit();

            $uniqueStores = collect($assignments)->groupBy('assigned_store_id')->map(function($items, $storeId) {
                return [
                    'store_id' => $storeId,
                    'store_name' => $items->first()['assigned_store_name'],
                    'items_count' => $items->count(),
                    'items' => $items->pluck('product_name')->toArray(),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Items automatically assigned to stores based on inventory',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'stores_involved' => $uniqueStores,
                    'total_stores' => $uniqueStores->count(),
                    'assignments' => $assignments,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to auto-assign stores',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually assign specific items to specific stores
     * Gives admin full control over which store fulfills which item
     * 
     * POST /api/orders/{orderId}/assign-item-stores
     * Body: {
     *   "assignments": [
     *     {"order_item_id": 1, "store_id": 5},
     *     {"order_item_id": 2, "store_id": 7},
     *     {"order_item_id": 3, "store_id": 5}
     *   ]
     * }
     */
    public function assignItemStores(Request $request, $orderId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'assignments' => 'required|array|min:1',
                'assignments.*.order_item_id' => 'required|exists:order_items,id',
                'assignments.*.store_id' => 'required|exists:stores,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $order = Order::with('items')->findOrFail($orderId);

            DB::beginTransaction();

            $validationErrors = [];
            $successfulAssignments = [];

            foreach ($request->assignments as $assignment) {
                $orderItem = OrderItem::where('order_id', $order->id)
                    ->find($assignment['order_item_id']);

                if (!$orderItem) {
                    $validationErrors[] = [
                        'order_item_id' => $assignment['order_item_id'],
                        'error' => 'Order item not found in this order',
                    ];
                    continue;
                }

                // Check inventory availability at assigned store
                $availableQuantity = ProductBatch::where('product_id', $orderItem->product_id)
                    ->where('store_id', $assignment['store_id'])
                    ->where('quantity', '>', 0)
                    ->where('availability', true)
                    ->sum('quantity');

                if ($availableQuantity < $orderItem->quantity) {
                    $store = Store::find($assignment['store_id']);
                    $validationErrors[] = [
                        'order_item_id' => $assignment['order_item_id'],
                        'product_name' => $orderItem->product_name,
                        'store_name' => $store->name,
                        'required' => $orderItem->quantity,
                        'available' => $availableQuantity,
                        'error' => "Insufficient stock at {$store->name}",
                    ];
                    continue;
                }

                // Assign store to item
                $orderItem->update(['store_id' => $assignment['store_id']]);
                
                $store = Store::find($assignment['store_id']);
                $successfulAssignments[] = [
                    'order_item_id' => $orderItem->id,
                    'product_name' => $orderItem->product_name,
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                ];
            }

            if (!empty($validationErrors)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Some assignments failed due to insufficient inventory',
                    'errors' => $validationErrors,
                ], 400);
            }

            // Update order status
            $order->update([
                'status' => 'multi_store_assigned',
                'fulfillment_status' => 'pending_fulfillment',
                'processed_by' => auth('api')->id(),
                'metadata' => array_merge($order->metadata ?? [], [
                    'multi_store_assignment' => [
                        'assigned_at' => now()->toISOString(),
                        'assigned_by' => auth('api')->id(),
                        'type' => 'manual',
                        'stores_involved' => collect($successfulAssignments)->pluck('store_id')->unique()->values()->toArray(),
                    ],
                ]),
            ]);

            DB::commit();

            $storesSummary = collect($successfulAssignments)->groupBy('store_id')->map(function($items, $storeId) {
                return [
                    'store_id' => $storeId,
                    'store_name' => $items->first()['store_name'],
                    'items_count' => $items->count(),
                    'items' => $items->map(fn($i) => [
                        'product_name' => $i['product_name'],
                        'order_item_id' => $i['order_item_id'],
                    ])->toArray(),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Items successfully assigned to stores',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'stores_summary' => $storesSummary,
                    'total_stores' => $storesSummary->count(),
                    'assignments' => $successfulAssignments,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign item stores',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get orders that need multi-store assignment
     * Shows orders where items cannot be fulfilled from single store
     * 
     * GET /api/orders/requiring-multi-store
     */
    public function getOrdersRequiringMultiStore(Request $request): JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);

            // Get orders without store_id (unassigned)
            $orders = Order::whereNull('store_id')
                ->whereIn('order_type', ['social_commerce', 'ecommerce'])
                ->whereIn('status', ['pending', 'pending_assignment'])
                ->with(['customer', 'items.product'])
                ->orderBy('created_at', 'asc')
                ->paginate($perPage);

            $ordersData = [];

            foreach ($orders as $order) {
                $needsMultiStore = false;
                $canBeFulfilledFromSingleStore = false;
                $firstStoreWithAllItems = null;

                // Check if any single store has all items
                $allStores = Store::where('is_online', true)->get();
                
                foreach ($allStores as $store) {
                    $canFulfillAll = true;
                    
                    foreach ($order->items as $item) {
                        $available = ProductBatch::where('product_id', $item->product_id)
                            ->where('store_id', $store->id)
                            ->where('quantity', '>=', $item->quantity)
                            ->where('availability', true)
                            ->sum('quantity');
                        
                        if ($available < $item->quantity) {
                            $canFulfillAll = false;
                            break;
                        }
                    }
                    
                    if ($canFulfillAll) {
                        $canBeFulfilledFromSingleStore = true;
                        $firstStoreWithAllItems = [
                            'store_id' => $store->id,
                            'store_name' => $store->name,
                        ];
                        break;
                    }
                }

                if (!$canBeFulfilledFromSingleStore) {
                    // Check if items are available across multiple stores
                    $itemsAvailable = [];
                    foreach ($order->items as $item) {
                        $storesWithItem = DB::table('product_batches')
                            ->where('product_id', $item->product_id)
                            ->where('quantity', '>=', $item->quantity)
                            ->where('availability', true)
                            ->distinct('store_id')
                            ->count('store_id');
                        
                        $itemsAvailable[] = $storesWithItem > 0;
                    }
                    
                    $allItemsAvailableSomewhere = !in_array(false, $itemsAvailable);
                    $needsMultiStore = $allItemsAvailableSomewhere;
                }

                $ordersData[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer->name,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    'items_count' => $order->items->count(),
                    'total_amount' => $order->total_amount,
                    'can_fulfill_from_single_store' => $canBeFulfilledFromSingleStore,
                    'single_store_option' => $firstStoreWithAllItems,
                    'requires_multi_store' => $needsMultiStore,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $ordersData,
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'total_pages' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                    ],
                    'summary' => [
                        'total_orders' => count($ordersData),
                        'requires_multi_store' => collect($ordersData)->filter(fn($o) => $o['requires_multi_store'])->count(),
                        'can_use_single_store' => collect($ordersData)->filter(fn($o) => $o['can_fulfill_from_single_store'])->count(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get fulfillment tasks for a specific store (multi-store orders)
     * Shows which items from which orders this store needs to fulfill
     * 
     * GET /api/stores/{storeId}/multi-store-fulfillment-tasks
     */
    public function getStoreFulfillmentTasks($storeId): JsonResponse
    {
        try {
            $store = Store::findOrFail($storeId);

            // Get all order items assigned to this store that need fulfillment
            $orderItems = OrderItem::where('store_id', $storeId)
                ->whereHas('order', function($q) {
                    $q->where('fulfillment_status', 'pending_fulfillment')
                      ->whereIn('status', ['multi_store_assigned', 'pending', 'confirmed']);
                })
                ->with(['order.customer', 'product', 'batch'])
                ->get()
                ->groupBy('order_id');

            $fulfillmentTasks = [];

            foreach ($orderItems as $orderId => $items) {
                $order = $items->first()->order;
                
                $fulfillmentTasks[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => $order->customer->name,
                    'order_type' => $order->order_type,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    'items_for_this_store' => $items->map(fn($item) => [
                        'order_item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'product_sku' => $item->product_sku,
                        'quantity' => $item->quantity,
                        'batch_assigned' => $item->product_batch_id !== null,
                        'barcode_assigned' => $item->product_barcode_id !== null,
                    ])->toArray(),
                    'items_count' => $items->count(),
                    'is_partial_fulfillment' => $order->items->count() > $items->count(),
                    'other_stores_involved' => $order->items->where('store_id', '!=', $storeId)->pluck('store_id')->unique()->count(),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'fulfillment_tasks' => $fulfillmentTasks,
                    'summary' => [
                        'total_orders' => count($fulfillmentTasks),
                        'total_items' => collect($fulfillmentTasks)->sum('items_count'),
                        'full_orders' => collect($fulfillmentTasks)->filter(fn($t) => !$t['is_partial_fulfillment'])->count(),
                        'partial_orders' => collect($fulfillmentTasks)->filter(fn($t) => $t['is_partial_fulfillment'])->count(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get fulfillment tasks',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
