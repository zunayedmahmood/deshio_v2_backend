<?php

namespace App\Http\Controllers;

use App\Models\ProductVariant;
use App\Models\Product;
use App\Models\VariantOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProductVariantController extends Controller
{
    public function index($productId, Request $request)
    {
        $product = Product::findOrFail($productId);
        
        $query = $product->variants()->with('product');
        
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }
        
        $variants = $query->orderBy('is_default', 'desc')->orderBy('created_at')->get();
        
        return response()->json(['success' => true, 'data' => $variants]);
    }

    public function store($productId, Request $request)
    {
        $product = Product::findOrFail($productId);
        
        $validator = Validator::make($request->all(), [
            'attributes' => 'required|array',
            'sku' => 'required|string|unique:product_variants,sku',
            'barcode' => 'nullable|string|unique:product_variants,barcode',
            'price_adjustment' => 'nullable|numeric',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'reorder_point' => 'nullable|integer|min:0',
            'image_url' => 'nullable|url',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['product_id'] = $productId;
        
        if ($data['is_default'] ?? false) {
            $product->variants()->update(['is_default' => false]);
        }

        $variant = ProductVariant::create($data);

        return response()->json(['success' => true, 'data' => $variant->load('product'), 'message' => 'Variant created successfully'], 201);
    }

    public function show($productId, $variantId)
    {
        $variant = ProductVariant::where('product_id', $productId)->findOrFail($variantId);
        
        return response()->json(['success' => true, 'data' => $variant->load('product')]);
    }

    public function update(Request $request, $productId, $variantId)
    {
        $variant = ProductVariant::where('product_id', $productId)->findOrFail($variantId);
        
        $validator = Validator::make($request->all(), [
            'attributes' => 'sometimes|required|array',
            'sku' => 'sometimes|required|string|unique:product_variants,sku,' . $variantId,
            'barcode' => 'nullable|string|unique:product_variants,barcode,' . $variantId,
            'price_adjustment' => 'nullable|numeric',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|integer|min:0',
            'reorder_point' => 'nullable|integer|min:0',
            'image_url' => 'nullable|url',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        
        if (($data['is_default'] ?? false) && !$variant->is_default) {
            ProductVariant::where('product_id', $productId)->update(['is_default' => false]);
        }

        $variant->update($data);

        return response()->json(['success' => true, 'data' => $variant->load('product'), 'message' => 'Variant updated successfully']);
    }

    public function destroy($productId, $variantId)
    {
        $variant = ProductVariant::where('product_id', $productId)->findOrFail($variantId);
        
        if ($variant->is_default) {
            return response()->json(['success' => false, 'message' => 'Cannot delete default variant'], 422);
        }
        
        $variant->delete();
        
        return response()->json(['success' => true, 'message' => 'Variant deleted successfully']);
    }

    public function generateMatrix(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);
        
        $validator = Validator::make($request->all(), [
            'attributes' => 'required|array|min:1',
            'base_price_adjustment' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $attributes = $request->attributes;
        $combinations = $this->generateCombinations($attributes);
        
        $created = [];
        foreach ($combinations as $combination) {
            $sku = $product->sku . '-' . implode('-', array_map(function($val) {
                return strtoupper(substr($val, 0, 2));
            }, array_values($combination)));
            
            $exists = ProductVariant::where('product_id', $productId)
                ->where('attributes', json_encode($combination))
                ->exists();
            
            if (!$exists) {
                $variant = ProductVariant::create([
                    'product_id' => $productId,
                    'sku' => $sku,
                    'attributes' => $combination,
                    'price_adjustment' => $request->base_price_adjustment ?? 0,
                    'stock_quantity' => 0,
                ]);
                
                $created[] = $variant;
            }
        }

        return response()->json(['success' => true, 'data' => $created, 'message' => count($created) . ' variants generated'], 201);
    }

    public function getOptions(Request $request)
    {
        $query = VariantOption::query();
        
        if ($request->has('name')) {
            $query->byName($request->name);
        }
        
        if ($request->has('type')) {
            $query->byType($request->type);
        }
        
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }
        
        $options = $query->orderBy('name')->orderBy('sort_order')->get();
        $grouped = $options->groupBy('name');
        
        return response()->json(['success' => true, 'data' => $grouped]);
    }

    public function storeOption(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'value' => 'required|string|max:100',
            'type' => 'required|in:text,color,image',
            'display_value' => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $option = VariantOption::create($validator->validated());

        return response()->json(['success' => true, 'data' => $option, 'message' => 'Variant option created successfully'], 201);
    }

    public function getStatistics($productId)
    {
        $product = Product::findOrFail($productId);
        
        $stats = [
            'total_variants' => $product->variants()->count(),
            'active_variants' => $product->variants()->active()->count(),
            'low_stock_variants' => $product->variants()->lowStock()->count(),
            'total_stock' => $product->variants()->sum('stock_quantity'),
            'total_reserved' => $product->variants()->sum('reserved_quantity'),
            'available_stock' => $product->variants()->sum(DB::raw('stock_quantity - reserved_quantity')),
        ];

        return response()->json(['success' => true, 'data' => $stats]);
    }

    private function generateCombinations($arrays, $i = 0)
    {
        if (!isset($arrays[$i])) {
            return [[]];
        }
        
        $tmp = $this->generateCombinations($arrays, $i + 1);
        $result = [];
        
        $key = key(array_slice($arrays, $i, 1, true));
        $values = $arrays[$key];
        
        foreach ($values as $value) {
            foreach ($tmp as $t) {
                $result[] = array_merge([$key => $value], $t);
            }
        }
        
        return $result;
    }
}
