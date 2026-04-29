<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class PreOrderController extends Controller
{
    /**
     * Get all pre-orders (orders with out-of-stock items)
     * 
     * GET /api/pre-orders
     */
    public function index(Request $request): JsonResponse
    {
        $query = Order::with([
            'customer',
            'store',
            'items.product.batches' => function ($q) {
                $q->where('quantity', '>', 0);
            }
        ])->where('is_preorder', true);

        // Filter by stock availability
        if ($request->boolean('has_stock')) {
            // Only show pre-orders where ALL items now have stock
            $query->whereHas('items', function ($q) {
                $q->whereHas('product.batches', function ($batchQuery) {
                    $batchQuery->where('quantity', '>', 0);
                });
            }, '=', function ($subQuery) {
                $subQuery->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->selectRaw('COUNT(*)');
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        // Search by order number or customer
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        $formattedOrders = collect($orders->items())->map(function ($order) {
            return $this->formatPreOrder($order);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'orders' => $formattedOrders,
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'last_page' => $orders->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Get pre-order details with stock availability
     * 
     * GET /api/pre-orders/{id}
     */
    public function show($id): JsonResponse
    {
        $order = Order::with([
            'customer',
            'store',
            'items.product.batches' => function ($q) {
                $q->where('quantity', '>', 0);
            }
        ])->where('is_preorder', true)->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Pre-order not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatPreOrder($order),
        ]);
    }

    /**
     * Get pre-orders that now have stock available
     * 
     * GET /api/pre-orders/ready-to-fulfill
     */
    public function getReadyToFulfill(Request $request): JsonResponse
    {
        $orders = Order::with([
            'customer',
            'items.product.batches' => function ($q) {
                $q->where('quantity', '>', 0);
            }
        ])
        ->where('is_preorder', true)
        ->where('status', 'pending_assignment')
        ->get()
        ->filter(function ($order) {
            // Check if ALL items now have sufficient stock
            return $order->items->every(function ($item) {
                $availableStock = $item->product->batches
                    ->where('quantity', '>', 0)
                    ->sum('quantity');
                return $availableStock >= $item->quantity;
            });
        });

        $formattedOrders = $orders->map(function ($order) {
            return $this->formatPreOrder($order);
        });

        return response()->json([
            'success' => true,
            'data' => [
                'total_ready' => $formattedOrders->count(),
                'orders' => $formattedOrders->values(),
            ],
            'message' => "Found {$formattedOrders->count()} pre-orders ready to fulfill",
        ]);
    }

    /**
     * Mark pre-order as stock available and ready for store assignment
     * 
     * POST /api/pre-orders/{id}/mark-stock-available
     */
    public function markStockAvailable($id): JsonResponse
    {
        $order = Order::with('items.product.batches')
            ->where('is_preorder', true)
            ->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Pre-order not found',
            ], 404);
        }

        // Verify all items have stock
        $missingStock = [];
        foreach ($order->items as $item) {
            $availableStock = $item->product->batches
                ->where('quantity', '>', 0)
                ->sum('quantity');
            
            if ($availableStock < $item->quantity) {
                $missingStock[] = [
                    'product' => $item->product->name,
                    'required' => $item->quantity,
                    'available' => $availableStock,
                    'shortage' => $item->quantity - $availableStock,
                ];
            }
        }

        if (!empty($missingStock)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot mark as stock available. Some items are still out of stock.',
                'missing_stock' => $missingStock,
            ], 400);
        }

        // Update order
        $order->update([
            'stock_available_at' => now(),
            'preorder_notes' => ($order->preorder_notes ?? '') . ' | Stock confirmed available on ' . now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pre-order marked as stock available. Ready for store assignment.',
            'data' => $this->formatPreOrder($order->fresh(['items.product.batches', 'customer', 'store'])),
        ]);
    }

    /**
     * Get pre-order statistics
     * 
     * GET /api/pre-orders/statistics
     */
    public function getStatistics(): JsonResponse
    {
        $totalPreOrders = Order::where('is_preorder', true)->count();
        $pendingAssignment = Order::where('is_preorder', true)
            ->where('status', 'pending_assignment')
            ->count();
        
        $readyToFulfill = Order::with('items.product.batches')
            ->where('is_preorder', true)
            ->where('status', 'pending_assignment')
            ->whereNull('stock_available_at')
            ->get()
            ->filter(function ($order) {
                return $order->items->every(function ($item) {
                    $availableStock = $item->product->batches
                        ->where('quantity', '>', 0)
                        ->sum('quantity');
                    return $availableStock >= $item->quantity;
                });
            })
            ->count();

        $awaitingStock = $totalPreOrders - $readyToFulfill;

        $totalPreOrderValue = Order::where('is_preorder', true)
            ->sum('total_amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_preorders' => $totalPreOrders,
                'awaiting_stock' => $awaitingStock,
                'ready_to_fulfill' => $readyToFulfill,
                'pending_assignment' => $pendingAssignment,
                'total_value' => $totalPreOrderValue,
            ],
        ]);
    }

    /**
     * Get products that are frequently pre-ordered
     * 
     * GET /api/pre-orders/trending-products
     */
    public function getTrendingProducts(): JsonResponse
    {
        $trendingProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.is_preorder', true)
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(order_items.quantity) as total_preordered'),
                DB::raw('COUNT(DISTINCT orders.id) as order_count')
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_preordered')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $trendingProducts,
            'message' => 'Top 10 products by pre-order volume',
        ]);
    }

    /**
     * Format pre-order response
     */
    private function formatPreOrder($order): array
    {
        $itemsDetails = $order->items->map(function ($item) {
            $availableStock = $item->product->batches
                ->where('quantity', '>', 0)
                ->sum('quantity');
            
            $hasStock = $availableStock >= $item->quantity;

            return [
                'product_id' => $item->product_id,
                'product_name' => $item->product->name,
                'product_sku' => $item->product->sku,
                'quantity_ordered' => $item->quantity,
                'available_stock' => $availableStock,
                'has_sufficient_stock' => $hasStock,
                'stock_shortage' => !$hasStock ? ($item->quantity - $availableStock) : 0,
                'unit_price' => $item->unit_price,
                'total_amount' => $item->total_amount,
            ];
        });

        $allItemsInStock = $itemsDetails->every(function ($item) {
            return $item['has_sufficient_stock'];
        });

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'email' => $order->customer->email,
            ],
            'store' => $order->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
            ] : null,
            'is_preorder' => $order->is_preorder,
            'stock_available_at' => $order->stock_available_at,
            'all_items_in_stock' => $allItemsInStock,
            'preorder_notes' => $order->preorder_notes,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_method' => $order->payment_method,
            'total_amount' => $order->total_amount,
            'shipping_address' => $order->shipping_address,
            'items' => $itemsDetails,
            'items_count' => $order->items->count(),
            'created_at' => $order->created_at,
            'order_date' => $order->order_date,
        ];
    }
}
