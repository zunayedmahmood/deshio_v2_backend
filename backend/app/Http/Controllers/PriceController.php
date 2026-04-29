<?php

namespace App\Http\Controllers;

use App\Models\ProductPriceOverride;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class PriceController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductPriceOverride::with(['product', 'store', 'creator', 'approver']);

        if ($request->has('product_id')) {
            $query->byProduct($request->product_id);
        }

        if ($request->has('store_id')) {
            $query->byStore($request->store_id);
        }

        if ($request->has('reason')) {
            $query->byReason($request->reason);
        }

        if ($request->has('is_active')) {
            $isActive = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        if ($request->has('status')) {
            switch ($request->status) {
                case 'active':
                    $query->active();
                    break;
                case 'expired':
                    $query->expired();
                    break;
                case 'pending':
                    $query->pending();
                    break;
            }
        }

        if ($request->has('approved')) {
            $approved = filter_var($request->approved, FILTER_VALIDATE_BOOLEAN);
            $query = $approved ? $query->approved() : $query->unapproved();
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        $overrides = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $overrides]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'price' => 'required|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
            'reason' => 'nullable|in:promotion,clearance,bulk_discount,seasonal,market_adjustment,error_correction,other',
            'description' => 'nullable|string',
            'store_id' => 'nullable|exists:stores,id',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['created_by'] = auth()->id();

        // Set original price from product if not provided
        if (empty($data['original_price'])) {
            $product = Product::findOrFail($data['product_id']);
            $data['original_price'] = $product->selling_price;
        }

        $priceOverride = ProductPriceOverride::create($data);

        return response()->json([
            'success' => true,
            'data' => $priceOverride->load(['product', 'store', 'creator']),
            'message' => 'Price override created successfully'
        ], 201);
    }

    public function show($id)
    {
        $priceOverride = ProductPriceOverride::with(['product', 'store', 'creator', 'approver'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $priceOverride]);
    }

    public function update(Request $request, $id)
    {
        $priceOverride = ProductPriceOverride::findOrFail($id);

        if ($priceOverride->isApproved()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update approved price override'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'price' => 'sometimes|required|numeric|min:0',
            'original_price' => 'nullable|numeric|min:0',
            'reason' => 'nullable|in:promotion,clearance,bulk_discount,seasonal,market_adjustment,error_correction,other',
            'description' => 'nullable|string',
            'store_id' => 'nullable|exists:stores,id',
            'starts_at' => 'sometimes|required|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $priceOverride->update($validator->validated());

        return response()->json([
            'success' => true,
            'data' => $priceOverride->load(['product', 'store', 'creator']),
            'message' => 'Price override updated successfully'
        ]);
    }

    public function destroy($id)
    {
        $priceOverride = ProductPriceOverride::findOrFail($id);

        if ($priceOverride->isCurrentlyActive()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete currently active price override'
            ], 422);
        }

        $priceOverride->delete();

        return response()->json(['success' => true, 'message' => 'Price override deleted successfully']);
    }

    public function approve($id)
    {
        $priceOverride = ProductPriceOverride::findOrFail($id);

        if ($priceOverride->isApproved()) {
            return response()->json([
                'success' => false,
                'message' => 'Price override already approved'
            ], 422);
        }

        $priceOverride->update([
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $priceOverride->load(['product', 'store', 'creator', 'approver']),
            'message' => 'Price override approved successfully'
        ]);
    }

    public function bulkUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'required|exists:products,id',
            'price_adjustment_type' => 'required|in:percentage,fixed',
            'price_adjustment_value' => 'required|numeric',
            'reason' => 'nullable|in:promotion,clearance,bulk_discount,seasonal,market_adjustment,error_correction,other',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'store_id' => 'nullable|exists:stores,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $created = [];
        foreach ($request->product_ids as $productId) {
            $product = Product::findOrFail($productId);
            $originalPrice = $product->selling_price;

            $newPrice = $request->price_adjustment_type === 'percentage'
                ? $originalPrice * (1 - ($request->price_adjustment_value / 100))
                : $originalPrice - $request->price_adjustment_value;

            $newPrice = max(0, $newPrice);

            $priceOverride = ProductPriceOverride::create([
                'product_id' => $productId,
                'price' => $newPrice,
                'original_price' => $originalPrice,
                'reason' => $request->reason ?? 'bulk_discount',
                'description' => $request->description ?? "Bulk price update",
                'store_id' => $request->store_id,
                'starts_at' => $request->starts_at,
                'ends_at' => $request->ends_at,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            $created[] = $priceOverride;
        }

        return response()->json([
            'success' => true,
            'data' => $created,
            'message' => count($created) . ' price overrides created successfully'
        ], 201);
    }

    public function getPriceHistory($productId, Request $request)
    {
        $product = Product::findOrFail($productId);

        $query = $product->priceOverrides()->with(['store', 'creator', 'approver']);

        $query->orderBy('starts_at', 'desc');

        $perPage = $request->get('per_page', 15);
        $history = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $history]);
    }

    public function getActivePrice($productId, Request $request)
    {
        $storeId = $request->get('store_id');

        $query = ProductPriceOverride::byProduct($productId)->active();

        if ($storeId) {
            $query->byStore($storeId);
        }

        $activeOverride = $query->first();

        if ($activeOverride) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_override' => true,
                    'override' => $activeOverride,
                    'current_price' => $activeOverride->price,
                    'original_price' => $activeOverride->original_price,
                    'discount_percentage' => $activeOverride->calculateDiscountPercentage(),
                ]
            ]);
        }

        $product = Product::findOrFail($productId);

        return response()->json([
            'success' => true,
            'data' => [
                'has_override' => false,
                'current_price' => $product->selling_price,
            ]
        ]);
    }

    public function getStatistics(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth());
        $dateTo = $request->get('date_to', now()->endOfMonth());

        $query = ProductPriceOverride::whereBetween('created_at', [$dateFrom, $dateTo]);

        $stats = [
            'total' => (clone $query)->count(),
            'active' => ProductPriceOverride::active()->count(),
            'expired' => ProductPriceOverride::expired()->count(),
            'pending' => ProductPriceOverride::pending()->count(),
            'approved' => (clone $query)->approved()->count(),
            'unapproved' => (clone $query)->unapproved()->count(),
            'by_reason' => ProductPriceOverride::select('reason', DB::raw('count(*) as count'))
                ->groupBy('reason')
                ->pluck('count', 'reason'),
            'total_discount_given' => (clone $query)->approved()->sum(DB::raw('original_price - price')),
        ];

        return response()->json(['success' => true, 'data' => $stats]);
    }
}
