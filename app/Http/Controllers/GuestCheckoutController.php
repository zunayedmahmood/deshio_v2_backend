<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Promotion;
use App\Models\ReservedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Raziul\Sslcommerz\Facades\Sslcommerz;

class GuestCheckoutController extends Controller
{
    /**
     * Guest checkout - No authentication required
     * Customer identified by phone number only
     */
    public function checkout(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone' => 'required|string|regex:/^[0-9+\-\s()]+$/|min:10|max:20',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|exists:products,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.variant_options' => 'nullable|array',
                'payment_method' => 'required|string|in:cod,sslcommerz,cash',
                
                // Delivery address (embedded)
                'delivery_address.full_name' => 'required|string|max:255',
                'delivery_address.phone' => 'nullable|string|max:20',
                'delivery_address.address_line_1' => 'required|string|max:255',
                'delivery_address.address_line_2' => 'nullable|string|max:255',
                'delivery_address.city' => 'required|string|max:100',
                'delivery_address.state' => 'nullable|string|max:100',
                'delivery_address.postal_code' => 'required|string|max:20',
                'delivery_address.country' => 'nullable|string|max:100',
                
                // Optional customer info
                'customer_name' => 'nullable|string|max:255',
                'customer_email' => 'nullable|email|max:255',
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

            try {
                // Step 1: Find or create customer by phone
                $customer = Customer::findOrCreateByPhone($request->phone, [
                    'name' => $request->customer_name,
                    'email' => $request->customer_email,
                    'address' => $request->input('delivery_address.address_line_1'),
                    'city' => $request->input('delivery_address.city'),
                    'state' => $request->input('delivery_address.state'),
                    'postal_code' => $request->input('delivery_address.postal_code'),
                    'country' => $request->input('delivery_address.country', 'Bangladesh'),
                ]);

                // Step 2: Validate products and calculate totals
                $subtotal = 0;
                $taxAmount = 0;
                $orderItems = [];
                $outOfStockItems = [];

                foreach ($request->items as $item) {
                    $product = Product::find($item['product_id']);
                    
                    if (!$product) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Product with ID {$item['product_id']} not found",
                        ], 404);
                    }

                    // Get latest batch for price and stock validation
                    $inStockBatch = ProductBatch::where('product_id', $product->id)
                        ->where('quantity', '>', 0)
                        ->orderBy('created_at', 'desc')
                        ->first();
                    
                    // Query ReservedProduct table for available inventory and lock for update
                    $reservedRecord = ReservedProduct::where('product_id', $product->id)->lockForUpdate()->first();
                    $totalAvailableStock = $reservedRecord ? $reservedRecord->available_inventory : 0;
                    
                    // IMPORTANT: Guest checkout (eCommerce) MUST have stock available
                    // Reject if insufficient stock
                    if ($totalAvailableStock < $item['quantity']) {
                        $outOfStockItems[] = [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'requested' => $item['quantity'],
                            'available' => $totalAvailableStock,
                        ];
                    }
                    
                    // If no in-stock batch at all, use latest batch for reference price
                    $anyBatch = ProductBatch::where('product_id', $product->id)
                        ->orderBy('created_at', 'desc')
                        ->first();

                    $baseUnitPrice = $inStockBatch ? $inStockBatch->sell_price : ($anyBatch ? $anyBatch->sell_price : null);
                    
                    // If price is not available (no batches), reject order
                    if ($baseUnitPrice === null) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Product {$product->name} has no price information available",
                        ], 400);
                    }

                    // Recalculate campaign-aware storefront price on the backend.
                    // Guest checkout payloads only contain product_id + quantity, so never trust
                    // a browser/localStorage price for the final order value.
                    $pricing = $this->getCampaignAwareUnitPrice($product, (float) $baseUnitPrice);
                    $unitPrice = $pricing['unit_price'];
                    
                    $itemTotal = round($unitPrice * $item['quantity'], 2);
                    
                    // Extract tax from inclusive price using category/batch tax_percentage
                    // Priority: Category tax > Batch tax
                    $batch = $inStockBatch ?? $anyBatch;
                    $taxPercentage = $batch ? (float) ($batch->tax_percentage ?? 0) : 0;
                    $itemTax = $taxPercentage > 0 
                        ? round($itemTotal - ($itemTotal / (1 + ($taxPercentage / 100))), 2)
                        : 0;
                    
                    $subtotal += $itemTotal;
                    $taxAmount += $itemTax;

                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'quantity' => $item['quantity'],
                        'unit_price' => $unitPrice,
                        'tax_amount' => $itemTax,
                        'total_amount' => $itemTotal,
                        'variant_options' => $item['variant_options'] ?? null,
                        'campaign_pricing' => $pricing['campaign_pricing'],
                    ];
                }

                // Reject order if any item is out of stock
                if (!empty($outOfStockItems)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient stock for some items',
                        'out_of_stock_items' => $outOfStockItems,
                    ], 400);
                }

                // Step 3: Calculate charges
                $deliveryCharge = $this->calculateDeliveryCharge($request->input('delivery_address.city'));
                // Tax is already extracted from item prices (inclusive)
                $totalAmount = $subtotal + $deliveryCharge;

                // Step 4: Prepare delivery address
                $deliveryAddress = [
                    'full_name' => $request->input('delivery_address.full_name'),
                    'phone' => $request->input('delivery_address.phone', $request->phone),
                    'address_line_1' => $request->input('delivery_address.address_line_1'),
                    'address_line_2' => $request->input('delivery_address.address_line_2'),
                    'city' => $request->input('delivery_address.city'),
                    'state' => $request->input('delivery_address.state'),
                    'postal_code' => $request->input('delivery_address.postal_code'),
                    'country' => $request->input('delivery_address.country', 'Bangladesh'),
                    'full_address' => sprintf(
                        '%s, %s, %s, %s - %s, %s',
                        $request->input('delivery_address.address_line_1'),
                        $request->input('delivery_address.address_line_2') ?? '',
                        $request->input('delivery_address.city'),
                        $request->input('delivery_address.state') ?? '',
                        $request->input('delivery_address.postal_code'),
                        $request->input('delivery_address.country', 'Bangladesh')
                    ),
                ];

                // Step 5: Create order
                // Note: is_preorder is always FALSE for guest checkout (eCommerce)
                // Pre-orders only allowed from dedicated pre-order panel
                $order = Order::create([
                    'customer_id' => $customer->id,
                    'store_id' => null, // Will be assigned by employee
                    'order_type' => 'ecommerce',
                    'is_preorder' => false, // eCommerce orders are NOT pre-orders
                    'preorder_notes' => null,
                    'status' => 'pending_assignment',
                    'payment_status' => $request->payment_method === 'cod' ? 'pending' : 'unpaid',
                    'payment_method' => $request->payment_method,
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => 0,
                    'shipping_amount' => $deliveryCharge,
                    'total_amount' => $totalAmount,
                    'shipping_address' => $deliveryAddress,
                    'billing_address' => $deliveryAddress, // Same as shipping for guest checkout
                    'notes' => $request->notes,
                    'metadata' => [
                        'checkout_type' => 'guest',
                        'customer_phone' => $request->phone,
                        'customer_provided_name' => $request->customer_name,
                        'campaign_pricing_applied' => collect($orderItems)->contains(fn ($item) => !empty($item['campaign_pricing'])),
                    ],
                ]);

                // Step 6: Create order items
                foreach ($orderItems as $itemData) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $itemData['product_id'],
                        'product_name' => $itemData['product_name'],
                        'product_sku' => $itemData['product_sku'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'tax_amount' => 0,
                        'discount_amount' => 0,
                        'total_amount' => $itemData['total_amount'],
                        'product_options' => array_filter([
                            'variant_options' => $itemData['variant_options'] ?? null,
                            'campaign_pricing' => $itemData['campaign_pricing'] ?? null,
                        ]),
                    ]);

                    // Note: Reservation is automatically handled by OrderItemObserver 
                    // since the order starts with 'pending_assignment' status.
                }

                // Step 7: Handle payment method
                if ($request->payment_method === 'sslcommerz') {
                    $transactionId = 'TXN-' . $order->id . '-' . time();
                    $paymentNumber = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -8));
                    
                    // Create pending payment record
                    OrderPayment::create([
                        'order_id' => $order->id,
                        'payment_method_id' => null, // gateway (nullable)
                        'customer_id' => $customer->id, // REQUIRED by schema
                        'store_id' => null, // ecommerce orders are assigned later
                        'amount' => $totalAmount,
                        'fee_amount' => 0,
                        'net_amount' => $totalAmount,
                        'status' => 'pending',
                        'payment_number' => $paymentNumber,
                        'transaction_reference' => $transactionId,
                        'metadata' => [
                            'payment_method' => 'sslcommerz',
                            'checkout_type' => 'guest',
                            'order_number' => $order->order_number,
                        ],
                    ]);

                    $response = Sslcommerz::setOrder($totalAmount, $transactionId, 'Order #' . $order->order_number)
                        ->setCustomer(
                            $customer->name,
                            $customer->email ?? $customer->phone . '@guest.local',
                            $customer->phone
                        )
                        ->setShippingInfo(
                            count($orderItems),
                            $deliveryAddress['full_address']
                        )
                        ->makePayment(['value_a' => $order->id]);

                    DB::commit();

                    if ($response->success()) {
                        return response()->json([
                            'success' => true,
                            'message' => 'Order created. Redirecting to payment gateway.',
                            'data' => [
                                'order_number' => $order->order_number,
                                'order_id' => $order->id,
                                'customer_id' => $customer->id,
                                'customer_phone' => $customer->phone,
                                'payment_url' => $response->gatewayPageURL(),
                                'transaction_id' => $transactionId,
                                'total_amount' => $totalAmount,
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

                // COD or Cash - Order created successfully
                return response()->json([
                    'success' => true,
                    'message' => 'Order placed successfully!',
                    'data' => [
                        'order' => [
                            'order_number' => $order->order_number,
                            'order_id' => $order->id,
                            'status' => $order->status,
                            'payment_method' => $order->payment_method,
                            'payment_status' => $order->payment_status,
                            'total_amount' => $order->total_amount,
                        ],
                        'customer' => [
                            'id' => $customer->id,
                            'phone' => $customer->phone,
                            'name' => $customer->name,
                            'email' => $customer->email,
                        ],
                        'delivery_address' => $deliveryAddress,
                        'order_summary' => [
                            'total_items' => count($orderItems),
                            'subtotal' => $subtotal,
                            'tax' => $taxAmount,
                            'shipping' => $deliveryCharge,
                            'total_amount' => $totalAmount,
                        ],
                        'message_to_customer' => 'Thank you for your order! We will contact you at ' . $customer->phone . ' for confirmation.',
                    ],
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve the current storefront selling price for a guest checkout item.
     *
     * The e-commerce UI applies active public percentage campaigns client-side for display,
     * but guest checkout submits only product_id and quantity. This backend calculation is
     * the final source of truth for order item selling_price/unit_price.
     */
    private function getCampaignAwareUnitPrice(Product $product, float $baseUnitPrice): array
    {
        $baseUnitPrice = round(max(0, $baseUnitPrice), 2);

        if ($baseUnitPrice <= 0) {
            return [
                'unit_price' => $baseUnitPrice,
                'campaign_pricing' => null,
            ];
        }

        $promotion = $this->getBestStorefrontPercentagePromotion($product);

        if (!$promotion) {
            return [
                'unit_price' => $baseUnitPrice,
                'campaign_pricing' => null,
            ];
        }

        $discountPercent = max(0, (float) $promotion->discount_value);

        if ($discountPercent <= 0) {
            return [
                'unit_price' => $baseUnitPrice,
                'campaign_pricing' => null,
            ];
        }

        $discountAmount = round($baseUnitPrice * ($discountPercent / 100), 2);
        $campaignUnitPrice = round(max(0, $baseUnitPrice - $discountAmount), 2);

        return [
            'unit_price' => $campaignUnitPrice,
            'campaign_pricing' => [
                'promotion_id' => $promotion->id,
                'promotion_name' => $promotion->name,
                'promotion_code' => $promotion->code,
                'discount_percent' => $discountPercent,
                'original_unit_price' => $baseUnitPrice,
                'campaign_unit_price' => $campaignUnitPrice,
                'discount_amount_per_unit' => round($baseUnitPrice - $campaignUnitPrice, 2),
            ],
        ];
    }

    /**
     * Match the storefront campaign rule used by PromotionContext:
     * active public percentage promotion, product/category scoped or sitewide,
     * with the highest percentage winning.
     */
    private function getBestStorefrontPercentagePromotion(Product $product): ?Promotion
    {
        return Promotion::where('is_active', true)
            ->where('is_public', true)
            ->where('type', 'percentage')
            ->where('start_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('usage_limit')
                  ->orWhereColumn('usage_count', '<', 'usage_limit');
            })
            ->get()
            ->filter(fn (Promotion $promotion) => $this->promotionAppliesToProduct($promotion, $product))
            ->sortByDesc(fn (Promotion $promotion) => (float) $promotion->discount_value)
            ->first();
    }

    private function promotionAppliesToProduct(Promotion $promotion, Product $product): bool
    {
        $productIds = $this->normalizePromotionIdList($promotion->applicable_products);
        $categoryIds = $this->normalizePromotionIdList($promotion->applicable_categories);

        $hasProductScope = !empty($productIds);
        $hasCategoryScope = !empty($categoryIds);

        if (!$hasProductScope && !$hasCategoryScope) {
            return true;
        }

        if ($hasProductScope && in_array((int) $product->id, $productIds, true)) {
            return true;
        }

        return $hasCategoryScope
            && $product->category_id !== null
            && in_array((int) $product->category_id, $categoryIds, true);
    }

    private function normalizePromotionIdList($ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $normalized = [];

        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }

            if (is_numeric($id)) {
                $normalized[] = (int) $id;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Calculate delivery charge based on city
     */
    private function calculateDeliveryCharge(string $city): float
    {
        $city = strtolower(trim($city));
        
        // Inside Dhaka
        if (in_array($city, ['dhaka', 'ঢাকা'])) {
            return 60.00;
        }
        
        // Major cities
        if (in_array($city, ['chittagong', 'chattogram', 'চট্টগ্রাম', 'sylhet', 'সিলেট', 'rajshahi', 'রাজশাহী'])) {
            return 100.00;
        }
        
        // Other areas
        return 120.00;
    }

    /**
     * Get order status by phone (for guests to track orders)
     */
    public function getOrdersByPhone(Request $request): JsonResponse
    {
        // Clean phone number BEFORE validation to handle various formats:
        // - "mobile no 01712345678"
        // - "+8801712345678" 
        // - "017-123-45678"
        // - Bengali text prefixes
        $cleanPhone = preg_replace('/[^0-9+]/', '', $request->phone);
        
        // Remove international prefix +88 for Bangladesh numbers
        $cleanPhone = preg_replace('/^\+88/', '', $cleanPhone);
        
        $validator = Validator::make(['phone' => $cleanPhone], [
            'phone' => 'required|string|min:10|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        $customer = Customer::where('phone', $cleanPhone)->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'No orders found for this phone number',
            ], 404);
        }

        $orders = Order::where('customer_id', $customer->id)
            ->with(['items.product'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'order_number' => $order->order_number,
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'payment_method' => $order->payment_method,
                    'payment_status' => $order->payment_status,
                    'total_amount' => $order->total_amount,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                    'items_count' => $order->items->count(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => [
                    'phone' => $customer->phone,
                    'name' => $customer->name,
                ],
                'orders' => $orders,
                'total_orders' => $orders->count(),
            ],
        ]);
    }
}
