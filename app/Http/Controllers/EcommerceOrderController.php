<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ReservedProduct;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Raziul\Sslcommerz\Facades\Sslcommerz;
use Carbon\Carbon;

class EcommerceOrderController extends Controller
{
    use DatabaseAgnosticSearch;
    public function __construct()
    {
        $this->middleware('auth:customer')->except(['show', 'track']);
    }

    /**
     * Get customer orders with pagination and filters
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $perPage = $request->query('per_page', 15);
            $status = $request->query('status');
            $search = $request->query('search');
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');

            $query = Order::where('customer_id', $customerId)
                ->with(['items.product', 'customer'])
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($status) {
                $query->where('status', $status);
            }

            if ($search) {
                $query->where(function($q) use ($search) {
                    $this->whereLike($q, 'order_number', $search);
                    $q->orWhereHas('items.product', function($pq) use ($search) {
                        $this->whereLike($pq, 'name', $search);
                    });
                });
            }

            if ($dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            }

            $orders = $query->paginate($perPage);

            // Add order summary for each order
            foreach ($orders as $order) {
                $order->summary = [
                    'total_items' => $order->items->sum('quantity'),
                    'total_amount' => $order->total_amount,
                    'status_label' => ucfirst(str_replace('_', ' ', $order->status)),
                    'days_since_order' => $order->created_at->diffInDays(now()),
                    'can_cancel' => $this->canCancelOrder($order),
                    'can_return' => $this->canReturnOrder($order),
                ];
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
                        'from' => $orders->firstItem(),
                        'to' => $orders->lastItem(),
                    ],
                    'filters' => [
                        'status' => $status,
                        'search' => $search,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific order details
     */
    public function show($orderNumber): JsonResponse
    {
        try {
            // Public access: find by order number without customer_id check
            $order = Order::where('order_number', $orderNumber)
                ->with([
                    'items.product.images',
                    'customer',
                    'store',
                    'payments'
                ])
                ->firstOrFail();

            // Add calculated fields
            $order->summary = [
                'subtotal' => $order->items->sum(function($item) {
                    return $item->unit_price * $item->quantity;
                }),
                'total_items' => $order->items->sum('quantity'),
                'total_amount' => $order->total_amount,
                'status_label' => ucfirst(str_replace('_', ' ', $order->status)),
                'can_cancel' => $this->canCancelOrder($order),
                'can_return' => $this->canReturnOrder($order),
                'tracking_available' => !empty($order->tracking_number),
            ];

            // Add delivery address (already cast to array in model)
            $order->delivery_address = $order->shipping_address ?? null;
            $order->billing_address = $order->billing_address ?? null;

            return response()->json([
                'success' => true,
                'data' => ['order' => $order],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Create order from cart
     */
    public function createFromCart(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'payment_method' => 'required|string|in:cash,card,bank_transfer,digital_wallet,cod,sslcommerz',
                'shipping_address_id' => 'required|exists:customer_addresses,id',
                'billing_address_id' => 'nullable|exists:customer_addresses,id',
                'notes' => 'nullable|string|max:500',
                'coupon_code' => 'nullable|string',
                'delivery_preference' => 'nullable|in:standard,express,scheduled',
                'scheduled_delivery_date' => 'nullable|date|after:today',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();

            // Get cart items
            $cartItems = Cart::where('customer_id', $customerId)
                ->where('status', 'active')
                ->with('product')
                ->get();

            if ($cartItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart is empty',
                ], 400);
            }

            // Validate all products still exist and are available
            foreach ($cartItems as $cartItem) {
                if (!$cartItem->product) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Some products in your cart are no longer available',
                    ], 400);
                }
            }

            // Validate addresses
            $shippingAddress = CustomerAddress::forCustomer($customerId)
                ->findOrFail($request->shipping_address_id);
            
            $billingAddress = $request->billing_address_id 
                ? CustomerAddress::forCustomer($customerId)->findOrFail($request->billing_address_id)
                : $shippingAddress;

            DB::beginTransaction();

            try {
                // IMPORTANT: eCommerce orders MUST have stock available
                // Pre-orders are ONLY allowed from dedicated pre-order panel
                // Validate stock availability for all cart items
                $outOfStockItems = [];
                foreach ($cartItems as $cartItem) {
                    $reservedRecord = ReservedProduct::where('product_id', $cartItem->product_id)->lockForUpdate()->first();
                    $availableStock = $reservedRecord ? $reservedRecord->available_inventory : 0;
                    
                    if ($availableStock < $cartItem->quantity) {
                        $outOfStockItems[] = [
                            'product_name' => $cartItem->product->name,
                            'requested' => $cartItem->quantity,
                            'available' => $availableStock,
                        ];
                    }
                }

                // Reject order if any item is out of stock
                if (!empty($outOfStockItems)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient stock for some items in your cart',
                        'out_of_stock_items' => $outOfStockItems,
                    ], 400);
                }

                // Calculate totals using unit_price from cart
                $subtotal = 0;
                $taxAmount = 0;
                
                foreach ($cartItems as $item) {
                    $itemTotal = $item->unit_price * $item->quantity;
                    $subtotal += $itemTotal;
                    
                    // Extract tax from inclusive price using batch tax_percentage
                    $batch = ProductBatch::where('product_id', $item->product_id)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    $taxPercentage = $batch ? ($batch->tax_percentage ?? 0) : 0;
                    $itemTax = $taxPercentage > 0 
                        ? round($itemTotal - ($itemTotal / (1 + ($taxPercentage / 100))), 2)
                        : 0;
                    $taxAmount += $itemTax;
                }

                $deliveryCharge = $this->calculateDeliveryCharge($shippingAddress);
                
                // Apply coupon discount if provided
                $discountAmount = 0;
                if ($request->coupon_code) {
                    $discountAmount = $this->applyCoupon($request->coupon_code, $subtotal);
                }
                
                // Tax is already extracted from item prices (inclusive)
                $totalAmount = $subtotal + $deliveryCharge - $discountAmount;

                // Create order - NO STORE ASSIGNED YET
                // Note: is_preorder is always FALSE for eCommerce orders
                // Pre-orders only allowed from dedicated pre-order panel
                $order = Order::create([
                    'customer_id' => $customerId,
                    'store_id' => null, // Will be assigned later by employee
                    'order_type' => 'ecommerce',
                    'is_preorder' => false, // eCommerce orders are NOT pre-orders
                    'preorder_notes' => null,
                    'status' => 'pending_assignment', // Waiting for store assignment
                    'fulfillment_status' => 'pending_fulfillment', // Mark for fulfillment workflow
                    'payment_status' => in_array($request->payment_method, ['cod', 'cash']) ? 'pending' : 'unpaid',
                    'payment_method' => $request->payment_method,
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'shipping_amount' => $deliveryCharge,
                    'total_amount' => $totalAmount,
                    'shipping_address' => $shippingAddress->toArray(),
                    'billing_address' => $billingAddress->toArray(),
                    'notes' => $request->notes,
                    'metadata' => [
                        'delivery_preference' => $request->delivery_preference ?? 'standard',
                        'scheduled_delivery_date' => $request->scheduled_delivery_date,
                        'coupon_code' => $request->coupon_code,
                    ],
                ]);

                // Create order items without batch/barcode (will be assigned during fulfillment)
                foreach ($cartItems as $cartItem) {
                    // Calculate tax for this item
                    $batch = ProductBatch::where('product_id', $cartItem->product_id)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    $taxPercentage = $batch ? ($batch->tax_percentage ?? 0) : 0;
                    $itemTotal = $cartItem->unit_price * $cartItem->quantity;
                    $itemTax = $taxPercentage > 0 
                        ? round($itemTotal - ($itemTotal / (1 + ($taxPercentage / 100))), 2)
                        : 0;
                    
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $cartItem->product_id,
                        'product_name' => $cartItem->product->name,
                        'product_sku' => $cartItem->product->sku,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $cartItem->unit_price,
                        'tax_amount' => $itemTax,
                        'discount_amount' => 0,
                        'total_amount' => $itemTotal,
                        'notes' => $cartItem->notes,
                    ]);
                }

                // Clear cart
                Cart::where('customer_id', $customerId)
                    ->where('status', 'active')
                    ->delete();

                // Handle payment based on method
                if ($request->payment_method === 'sslcommerz') {
                    // Initiate SSLCommerz payment
                    $transactionId = 'TXN-' . $order->id . '-' . time();
                    $paymentNumber = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
                    
                    // Create pending payment record with ALL required fields
                    OrderPayment::create([
                        'order_id' => $order->id,
                        'payment_method_id' => null, // SSLCommerz doesn't use payment_methods table (nullable)
                        'customer_id' => $customerId, // REQUIRED
                        'store_id' => null, // Nullable for ecommerce orders
                        'amount' => $totalAmount,
                        'fee_amount' => 0, // No fee for now
                        'net_amount' => $totalAmount,
                        'status' => 'pending',
                        'payment_number' => $paymentNumber, // REQUIRED unique field
                        'transaction_reference' => $transactionId,
                        'external_reference' => null, // Will be updated from SSLCommerz callback
                        'metadata' => [
                            'payment_method' => 'sslcommerz',
                            'order_number' => $order->order_number,
                        ],
                    ]);

                    $customer = Customer::find($customerId);
                    
                    $response = Sslcommerz::setOrder($totalAmount, $transactionId, 'Order #' . $order->order_number)
                        ->setCustomer($customer->name, $customer->email, $customer->phone ?? '01700000000')
                        ->setShippingInfo($cartItems->sum('quantity'), $shippingAddress->full_address ?? 'N/A')
                        ->makePayment(['value_a' => $order->id]); // Pass order ID as additional data

                    DB::commit();

                    if ($response->success()) {
                        return response()->json([
                            'success' => true,
                            'message' => 'Order created. Redirecting to payment gateway.',
                            'data' => [
                                'order' => $order,
                                'payment_url' => $response->gatewayPageURL(),
                                'transaction_id' => $transactionId,
                            ],
                        ], 201);
                    } else {
                        return response()->json([
                            'success' => false,
                            'message' => 'Failed to initiate payment gateway',
                            'error' => $response->failedReason(),
                        ], 500);
                    }
                }

                DB::commit();

                // Load relationships for response (for COD and other methods)
                $order->load(['items.product.images', 'customer']);

                return response()->json([
                    'success' => true,
                    'message' => 'Order placed successfully. An employee will assign it to a store shortly.',
                    'data' => [
                        'order' => $order,
                        'order_summary' => [
                            'order_number' => $order->order_number,
                            'total_items' => $order->items->sum('quantity'),
                            'subtotal' => $order->subtotal,
                            'tax' => $order->tax_amount,
                            'shipping' => $order->shipping_amount,
                            'discount' => $order->discount_amount,
                            'total_amount' => $order->total_amount,
                            'payment_method' => $order->payment_method,
                            'status' => 'pending_assignment',
                            'status_description' => 'Your order is being processed and will be assigned to a store based on inventory availability.',
                        ],
                    ],
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel order
     */
    public function cancel($orderNumber): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            
            $order = Order::where('customer_id', $customerId)
                ->where('order_number', $orderNumber)
                ->with('items.product')
                ->firstOrFail();

            if (!$this->canCancelOrder($order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot be cancelled at this time',
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Update order status
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Customer cancellation',
                ]);

                // OrderObserver handles reservation release on cancellation

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Order cancelled successfully',
                    'data' => ['order' => $order],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update order details (customer-side, limited fields)
     * 
     * PUT/PATCH /api/customer/orders/{orderNumber}
     * 
     * Customers can only update:
     * - Shipping address (before fulfillment)
     * - Notes/delivery instructions
     * 
     * Cannot update after order is fulfilled/shipped
     */
    public function update(Request $request, $orderNumber): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            
            $order = Order::where('customer_id', $customerId)
                ->where('order_number', $orderNumber)
                ->firstOrFail();

            // Only allow updates for pending/confirmed/assigned orders
            if (!in_array($order->status, ['pending', 'confirmed', 'assigned_to_store', 'picking'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update order in current status: ' . $order->status,
                    'hint' => 'Orders can only be updated before fulfillment begins',
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'shipping_address' => 'nullable|array',
                'shipping_address.address_line1' => 'required_with:shipping_address|string',
                'shipping_address.address_line2' => 'nullable|string',
                'shipping_address.city' => 'required_with:shipping_address|string',
                'shipping_address.state' => 'nullable|string',
                'shipping_address.postal_code' => 'nullable|string',
                'shipping_address.country' => 'required_with:shipping_address|string',
                'notes' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            // Update shipping address
            if ($request->has('shipping_address')) {
                $order->shipping_address = json_encode($request->shipping_address);
            }

            // Update notes
            if ($request->has('notes')) {
                $order->notes = $request->notes;
            }

            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $order->load(['items.product', 'customer']),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track order
     */
    public function track($orderNumber): JsonResponse
    {
        try {
            // Public access: find by order number without customer_id check
            $order = Order::where('order_number', $orderNumber)
                ->firstOrFail();

            $trackingSteps = $this->getTrackingSteps($order);

            return response()->json([
                'success' => true,
                'data' => [
                    'order' => $order,
                    'tracking' => [
                        'current_status' => $order->status,
                        'tracking_number' => $order->tracking_number,
                        'estimated_delivery' => $this->getEstimatedDelivery($order),
                        'steps' => $trackingSteps,
                        'last_updated' => $order->updated_at,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Get order statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();

            $stats = [
                'total_orders' => Order::where('customer_id', $customerId)->count(),
                'completed_orders' => Order::where('customer_id', $customerId)->where('status', 'completed')->count(),
                'pending_orders' => Order::where('customer_id', $customerId)->whereIn('status', ['pending', 'processing', 'shipped'])->count(),
                'cancelled_orders' => Order::where('customer_id', $customerId)->where('status', 'cancelled')->count(),
                'total_spent' => Order::where('customer_id', $customerId)
                    ->where('status', 'completed')
                    ->sum('total_amount'),
                'average_order_value' => Order::where('customer_id', $customerId)
                    ->where('status', 'completed')
                    ->avg('total_amount'),
                'last_order_date' => Order::where('customer_id', $customerId)
                    ->latest()
                    ->value('created_at'),
            ];

            // Recent orders
            $recentOrders = Order::where('customer_id', $customerId)
                ->with('items.product')
                ->latest()
                ->take(5)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => $stats,
                    'recent_orders' => $recentOrders,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Helper methods

    private function generateOrderNumber(): string
    {
        $prefix = 'ORD';
        $timestamp = now()->format('ymd');
        $random = str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        return "{$prefix}-{$timestamp}-{$random}";
    }

    private function calculateDeliveryCharge(CustomerAddress $address): float
    {
        // Simple delivery charge calculation
        $city = strtolower($address->city);
        
        if (str_contains($city, 'dhaka')) {
            return 60.00; // Dhaka delivery
        } elseif (in_array($city, ['chittagong', 'sylhet', 'rajshahi', 'khulna', 'chattogram'])) {
            return 120.00; // Major cities
        } else {
            return 150.00; // Other areas
        }
    }

    private function applyCoupon(string $couponCode, float $subtotal): float
    {
        // Simple coupon system - in real app, this would check database
        $coupons = [
            'WELCOME10' => ['type' => 'percentage', 'value' => 10, 'min_amount' => 1000],
            'SAVE50' => ['type' => 'fixed', 'value' => 50, 'min_amount' => 500],
            'NEWUSER' => ['type' => 'percentage', 'value' => 15, 'min_amount' => 2000],
        ];

        if (!isset($coupons[$couponCode])) {
            return 0;
        }

        $coupon = $coupons[$couponCode];
        
        if ($subtotal < $coupon['min_amount']) {
            return 0;
        }

        if ($coupon['type'] === 'percentage') {
            return ($subtotal * $coupon['value']) / 100;
        } else {
            return $coupon['value'];
        }
    }

    private function getEstimatedDelivery(Order $order): ?string
    {
        if ($order->scheduled_delivery_date) {
            return $order->scheduled_delivery_date;
        }

        // shipping_address is cast to array in the Order model
        $shippingAddress = $order->shipping_address ?? [];
        $city = strtolower($shippingAddress['city'] ?? '');
        
        $days = str_contains($city, 'dhaka') ? 2 : 4;
        
        if ($order->delivery_preference === 'express') {
            $days = max(1, $days - 1);
        }

        return now()->addDays($days)->format('Y-m-d');
    }

    private function canCancelOrder(Order $order): bool
    {
        return in_array($order->status, ['pending', 'processing']) && 
               $order->created_at->diffInHours(now()) <= 24;
    }

    private function canReturnOrder(Order $order): bool
    {
        return $order->status === 'completed' && 
               $order->updated_at->diffInDays(now()) <= 7;
    }

    private function getTrackingSteps(Order $order): array
    {
        $steps = [
            ['status' => 'pending', 'label' => 'Order Placed', 'completed' => true, 'date' => $order->created_at],
            ['status' => 'processing', 'label' => 'Order Processing', 'completed' => false, 'date' => null],
            ['status' => 'shipped', 'label' => 'Order Shipped', 'completed' => false, 'date' => null],
            ['status' => 'delivered', 'label' => 'Order Delivered', 'completed' => false, 'date' => null],
        ];

        foreach ($steps as &$step) {
            if ($order->status === $step['status'] || 
                ($order->status === 'completed' && $step['status'] === 'delivered')) {
                $step['completed'] = true;
                $step['date'] = $order->updated_at;
                break;
            } elseif ($step['completed']) {
                continue;
            } else {
                break;
            }
        }

        if ($order->status === 'cancelled') {
            $steps[] = ['status' => 'cancelled', 'label' => 'Order Cancelled', 'completed' => true, 'date' => $order->cancelled_at ?? $order->updated_at];
        }

        return $steps;
    }
}