<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use App\Models\Customer;
use App\Models\Order;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PromotionController extends Controller
{
    use DatabaseAgnosticSearch;
    public function index(Request $request)
    {
        $query = Promotion::with(['createdBy', 'usages']);

        // Filter by type
        if ($request->has('type')) {
            $query->byType($request->type);
        }

        // Filter by status
        if ($request->has('is_active')) {
            $isActive = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        // Filter by public/private
        if ($request->has('is_public')) {
            $isPublic = filter_var($request->is_public, FILTER_VALIDATE_BOOLEAN);
            $query->where('is_public', $isPublic);
        }

        // Filter valid promotions only
        if ($request->has('valid_only') && filter_var($request->valid_only, FILTER_VALIDATE_BOOLEAN)) {
            $query->valid();
        }

        // Search by code or name
        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['code', 'name'], $search);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $promotions = $query->paginate($perPage);

        // Add computed attributes
        foreach ($promotions as $promotion) {
            $promotion->current_status = $promotion->status;
            $promotion->remaining_usage = $promotion->getRemainingUsage();
        }

        return response()->json(['success' => true, 'data' => $promotions]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'nullable|string|unique:promotions,code|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:percentage,fixed,buy_x_get_y,free_shipping',
            'discount_value' => 'required_unless:type,buy_x_get_y,free_shipping|numeric|min:0',
            'buy_quantity' => 'required_if:type,buy_x_get_y|integer|min:1',
            'get_quantity' => 'required_if:type,buy_x_get_y|integer|min:1',
            'minimum_purchase' => 'nullable|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'applicable_products' => 'nullable|array',
            'applicable_categories' => 'nullable|array',
            'applicable_customers' => 'nullable|array',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_per_customer' => 'nullable|integer|min:1',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_automatic' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        
        // Generate code if not provided
        if (empty($data['code'])) {
            $data['code'] = $this->generateUniqueCode();
        }

        $data['created_by'] = auth()->id();

        $promotion = Promotion::create($data);

        return response()->json([
            'success' => true,
            'data' => $promotion->load('createdBy'),
            'message' => 'Promotion created successfully'
        ], 201);
    }

    public function show($id)
    {
        $promotion = Promotion::with(['createdBy', 'usages.customer', 'usages.order'])->findOrFail($id);

        $promotion->current_status = $promotion->status;
        $promotion->remaining_usage = $promotion->getRemainingUsage();

        return response()->json(['success' => true, 'data' => $promotion]);
    }

    public function update(Request $request, $id)
    {
        $promotion = Promotion::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|required|string|max:50|unique:promotions,code,' . $id,
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|required|in:percentage,fixed,buy_x_get_y,free_shipping',
            'discount_value' => 'sometimes|required_unless:type,buy_x_get_y,free_shipping|numeric|min:0',
            'buy_quantity' => 'required_if:type,buy_x_get_y|integer|min:1',
            'get_quantity' => 'required_if:type,buy_x_get_y|integer|min:1',
            'minimum_purchase' => 'nullable|numeric|min:0',
            'maximum_discount' => 'nullable|numeric|min:0',
            'applicable_products' => 'nullable|array',
            'applicable_categories' => 'nullable|array',
            'applicable_customers' => 'nullable|array',
            'usage_limit' => 'nullable|integer|min:1',
            'usage_per_customer' => 'nullable|integer|min:1',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'nullable|date|after:start_date',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_automatic' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $promotion->update($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $promotion->load('createdBy'),
            'message' => 'Promotion updated successfully'
        ]);
    }

    public function destroy($id)
    {
        $promotion = Promotion::findOrFail($id);

        // Check if promotion has been used
        if ($promotion->usage_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete promotion that has been used. Consider deactivating it instead.'
            ], 422);
        }

        $promotion->delete();

        return response()->json(['success' => true, 'message' => 'Promotion deleted successfully']);
    }

    public function activate($id)
    {
        $promotion = Promotion::findOrFail($id);

        if ($promotion->is_active) {
            return response()->json(['success' => false, 'message' => 'Promotion is already active'], 422);
        }

        $promotion->is_active = true;
        $promotion->save();

        return response()->json(['success' => true, 'data' => $promotion, 'message' => 'Promotion activated successfully']);
    }

    public function deactivate($id)
    {
        $promotion = Promotion::findOrFail($id);

        if (!$promotion->is_active) {
            return response()->json(['success' => false, 'message' => 'Promotion is already inactive'], 422);
        }

        $promotion->is_active = false;
        $promotion->save();

        return response()->json(['success' => true, 'data' => $promotion, 'message' => 'Promotion deactivated successfully']);
    }

    /**
     * PUBLIC: Get all currently active public promotions for the storefront.
     * No authentication required.
     */
    public function getActivePublic()
    {
        $promotions = Promotion::where('is_active', true)
            ->where('is_public', true)
            ->where('start_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('usage_limit')
                  ->orWhereColumn('usage_count', '<', 'usage_limit');
            })
            ->get([
                'id', 'name', 'description', 'type', 'discount_value', 'code',
                'minimum_purchase', 'maximum_discount',
                'applicable_products', 'applicable_categories',
                'start_date', 'end_date', 'is_automatic',
            ]);

        return response()->json(['success' => true, 'data' => $promotions]);
    }

    /**
     * PUBLIC: Validate a private coupon code with full cart context.
     * Supports guest customers (customer_id = null).
     * Returns structured error codes for all 25 documented scenarios.
     */
    public function validateCoupon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code'               => 'required|string',
            'customer_id'        => 'nullable|integer',
            'cart_subtotal'      => 'required|numeric|min:0',
            'cart_items'         => 'nullable|array',
            'cart_items.*.product_id'   => 'required_with:cart_items|integer',
            'cart_items.*.category_id'  => 'nullable|integer',
            'cart_items.*.quantity'     => 'required_with:cart_items|integer|min:1',
            'cart_items.*.unit_price'   => 'required_with:cart_items|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $promotion = Promotion::where('code', $request->code)->first();

        // Scenario 14, 15: Invalid code
        if (!$promotion) {
            return response()->json([
                'success'    => false,
                'error_code' => 'INVALID_CODE',
                'message'    => 'Invalid coupon code. Please check and try again.',
            ], 404);
        }

        // Scenario 15: Not yet started
        if ($promotion->start_date > now()) {
            return response()->json([
                'success'    => false,
                'error_code' => 'PROMOTION_NOT_STARTED',
                'message'    => "This coupon isn't valid yet. Check back soon!",
            ], 422);
        }

        // Scenario 14: Expired
        if ($promotion->end_date && $promotion->end_date < now()) {
            return response()->json([
                'success'    => false,
                'error_code' => 'PROMOTION_EXPIRED',
                'message'    => 'This coupon has expired.',
            ], 422);
        }

        // Scenario 22: Inactive
        if (!$promotion->is_active) {
            return response()->json([
                'success'    => false,
                'error_code' => 'PROMOTION_INACTIVE',
                'message'    => 'This coupon is no longer active.',
            ], 422);
        }

        // Scenario 12: Global usage limit
        if ($promotion->usage_limit && $promotion->usage_count >= $promotion->usage_limit) {
            return response()->json([
                'success'    => false,
                'error_code' => 'PROMOTION_LIMIT_REACHED',
                'message'    => 'Sorry, this coupon has reached its usage limit.',
            ], 422);
        }

        $customerId = $request->customer_id;
        $cartSubtotal = $request->cart_subtotal;
        $cartItems = $request->cart_items ?? [];

        // Scenario 17: Guest + customer-scoped coupon
        if (!$customerId) {
            if (!empty($promotion->applicable_customers) || $promotion->usage_per_customer) {
                return response()->json([
                    'success'    => false,
                    'error_code' => 'LOGIN_REQUIRED',
                    'message'    => 'Please log in to use this coupon.',
                ], 422);
            }
        }

        if ($customerId) {
            $customer = Customer::find($customerId);
            if (!$customer) {
                return response()->json([
                    'success'    => false,
                    'error_code' => 'INVALID_CODE',
                    'message'    => 'Invalid coupon code. Please check and try again.',
                ], 422);
            }

            // Scenario 18: Customer not in applicable_customers
            if (!empty($promotion->applicable_customers) && !in_array($customerId, $promotion->applicable_customers)) {
                return response()->json([
                    'success'    => false,
                    'error_code' => 'CUSTOMER_NOT_ELIGIBLE',
                    'message'    => "This coupon isn't available for your account.",
                ], 422);
            }

            // Scenario 13: Per-customer usage limit
            if ($promotion->usage_per_customer) {
                $used = $promotion->usages()->where('customer_id', $customerId)->count();
                if ($used >= $promotion->usage_per_customer) {
                    return response()->json([
                        'success'    => false,
                        'error_code' => 'CUSTOMER_LIMIT_REACHED',
                        'message'    => "You've already used this coupon.",
                    ], 422);
                }
            }
        }

        // Scenario 5, 8, 19: Minimum purchase not met
        if ($promotion->minimum_purchase && $cartSubtotal < $promotion->minimum_purchase) {
            return response()->json([
                'success'    => false,
                'error_code' => 'MINIMUM_NOT_MET',
                'message'    => "This coupon requires a minimum order of ৳{$promotion->minimum_purchase}.",
                'minimum_purchase' => $promotion->minimum_purchase,
            ], 422);
        }

        // Scope check: determine which cart items are eligible
        $eligibleItems = $cartItems;
        $hasScope = !empty($promotion->applicable_products) || !empty($promotion->applicable_categories);

        if ($hasScope && !empty($cartItems)) {
            $eligibleItems = array_filter($cartItems, function ($item) use ($promotion) {
                if (!empty($promotion->applicable_products) && in_array($item['product_id'], $promotion->applicable_products)) {
                    return true;
                }
                if (!empty($promotion->applicable_categories) && isset($item['category_id'])
                    && in_array($item['category_id'], $promotion->applicable_categories)) {
                    return true;
                }
                return false;
            });
        }

        // Scenario 11: Valid code but no eligible items
        if ($hasScope && empty($eligibleItems)) {
            return response()->json([
                'success'    => false,
                'error_code' => 'NO_ELIGIBLE_ITEMS',
                'message'    => "This coupon doesn't apply to any items in your cart.",
            ], 422);
        }

        // Calculate discount on eligible subtotal
        if ($hasScope && !empty($eligibleItems)) {
            $eligibleSubtotal = array_sum(array_map(
                fn($item) => $item['unit_price'] * $item['quantity'],
                $eligibleItems
            ));
            $base = $eligibleSubtotal;
        } else {
            $base = $cartSubtotal;
        }

        $calculatedAmount = 0;
        if ($promotion->type === 'percentage') {
            $calculatedAmount = ($base * $promotion->discount_value) / 100;
        } elseif ($promotion->type === 'fixed') {
            $calculatedAmount = $promotion->discount_value;
        }

        // Scenario 20: Cannot exceed total
        $calculatedAmount = min($calculatedAmount, $cartSubtotal);

        // Scenario 16: Cap
        $capped = false;
        $appliedAmount = $calculatedAmount;
        if ($promotion->maximum_discount && $calculatedAmount > $promotion->maximum_discount) {
            $appliedAmount = $promotion->maximum_discount;
            $capped = true;
        }

        $categoryName = null;
        if (!empty($promotion->applicable_categories)) {
            $categoryName = implode(', ', $promotion->applicable_categories);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'promotion'         => $promotion,
                'calculated_amount' => round($calculatedAmount, 2),
                'applied_amount'    => round($appliedAmount, 2),
                'capped'            => $capped,
                'max_discount'      => $promotion->maximum_discount,
                'eligible_product_ids' => array_column(array_values($eligibleItems), 'product_id'),
                'category_scope_name'  => $categoryName,
                'message'           => 'Coupon applied successfully.',
            ]
        ]);
    }

    public function validateCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'customer_id' => 'required|exists:customers,id',
            'order_total' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $promotion = Promotion::where('code', $request->code)->first();

        if (!$promotion) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid promotion code',
            ], 404);
        }

        $customer = Customer::findOrFail($request->customer_id);
        $orderTotal = $request->get('order_total', 0);

        $eligibility = $promotion->canBeUsedBy($customer, $orderTotal);

        if (!$eligibility['can_use']) {
            return response()->json([
                'success' => false,
                'message' => 'Promotion cannot be used',
                'errors' => $eligibility['errors']
            ], 422);
        }

        $discount = $promotion->calculateDiscount($orderTotal);

        return response()->json([
            'success' => true,
            'data' => [
                'promotion' => $promotion,
                'discount_amount' => $discount,
                'final_total' => max(0, $orderTotal - $discount),
                'message' => 'Promotion code is valid',
            ]
        ]);
    }

    public function applyToOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'promotion_code' => 'required|string',
            'order_id' => 'required|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $promotion = Promotion::where('code', $request->promotion_code)->first();

        if (!$promotion) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid promotion code'
            ], 404);
        }

        $order = Order::with('customer', 'items')->findOrFail($request->order_id);

        $eligibility = $promotion->canBeUsedBy($order->customer, $order->total_amount);

        if (!$eligibility['can_use']) {
            return response()->json([
                'success' => false,
                'message' => 'Promotion cannot be applied',
                'errors' => $eligibility['errors']
            ], 422);
        }

        $items = [];
        foreach ($order->items as $item) {
            $items[] = [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ];
        }

        $discount = $promotion->calculateDiscount($order->total_amount, $items);

        // Record usage
        $usage = $promotion->recordUsage($order, $order->customer, $discount);

        return response()->json([
            'success' => true,
            'data' => [
                'usage' => $usage,
                'discount_amount' => $discount,
                'message' => 'Promotion applied successfully',
            ]
        ]);
    }

    public function getStatistics(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth());
        $dateTo = $request->get('date_to', now()->endOfMonth());

        $query = Promotion::query();

        $stats = [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->active()->count(),
            'valid' => (clone $query)->valid()->count(),
            'public' => (clone $query)->public()->count(),
            'by_type' => [
                'percentage' => (clone $query)->byType('percentage')->count(),
                'fixed' => (clone $query)->byType('fixed')->count(),
                'buy_x_get_y' => (clone $query)->byType('buy_x_get_y')->count(),
                'free_shipping' => (clone $query)->byType('free_shipping')->count(),
            ],
            'total_usage' => Promotion::sum('usage_count'),
            'total_discount_given' => \App\Models\PromotionUsage::whereBetween('used_at', [$dateFrom, $dateTo])
                ->sum('discount_amount'),
        ];

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function getUsageHistory($id, Request $request)
    {
        $promotion = Promotion::findOrFail($id);

        $query = $promotion->usages()->with(['customer', 'order']);

        // Filter by date range
        if ($request->has('date_from') && $request->has('date_to')) {
            $query->whereBetween('used_at', [$request->date_from, $request->date_to]);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $query->orderBy('used_at', 'desc');

        $perPage = $request->get('per_page', 15);
        $usages = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $usages]);
    }

    public function duplicate($id)
    {
        $promotion = Promotion::findOrFail($id);

        $newPromotion = $promotion->replicate();
        $newPromotion->code = $this->generateUniqueCode();
        $newPromotion->name = $promotion->name . ' (Copy)';
        $newPromotion->usage_count = 0;
        $newPromotion->is_active = false;
        $newPromotion->created_by = auth()->id();
        $newPromotion->save();

        return response()->json([
            'success' => true,
            'data' => $newPromotion->load('createdBy'),
            'message' => 'Promotion duplicated successfully'
        ], 201);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Promotion::where('code', $code)->exists());

        return $code;
    }
}

