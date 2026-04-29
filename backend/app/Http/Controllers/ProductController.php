<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Field;
use App\Models\ProductField;
use App\Models\Category;
use App\Models\Vendor;
use App\Traits\DatabaseAgnosticSearch;
use App\Traits\ProductImageFallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use DatabaseAgnosticSearch;
    use ProductImageFallback;
    /**
     * Get all products with filters and custom fields
     */
    public function index(Request $request)
    {
        $query = Product::select('products.*')->with(['category', 'vendor', 'productFields.field', 'images' => function($q) {
            $q->where('is_active', true)->orderBy('is_primary', 'desc')->orderBy('sort_order');
        }]);

        // Filters
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->has('is_archived')) {
            $query->where('is_archived', $request->boolean('is_archived'));
        } else {
            $query->where('is_archived', false); // Default to active products
        }

        if ($request->has('search')) {
            $search = $request->search;
            $terms = explode(' ', $search);
            
            $query->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $term = trim($term);
                    if (empty($term)) continue;
                    
                    $q->where(function ($subQ) use ($term) {
                        $this->whereAnyLike($subQ, ['name', 'sku'], $term);
                    });
                }
            });
        }

        // Price range filter
        if ($request->has('min_price') || $request->has('max_price')) {
            $query->whereHas('batches', function($q) use ($request) {
                $q->where('is_active', true)
                  ->where('availability', true);
                
                if ($request->has('min_price')) {
                    $q->where('sell_price', '>=', (float) $request->min_price);
                }
                if ($request->has('max_price')) {
                    $q->where('sell_price', '<=', (float) $request->max_price);
                }
            });
        }

        // Stock status filter (supports both stock_status and in_stock)
        $stockStatus = $request->get('stock_status', $request->get('in_stock'));
        if ($stockStatus && $stockStatus !== 'all') {
            if ($stockStatus === 'in_stock' || $stockStatus === 'true' || $stockStatus === true) {
                $query->whereHas('batches', function($q) {
                    $q->where('is_active', true)
                      ->where('availability', true)
                      ->where('stock_qty', '>', 0);
                });
            } elseif ($stockStatus === 'not_in_stock' || $stockStatus === 'false' || $stockStatus === false) {
                $query->whereDoesntHave('batches', function($q) {
                    $q->where('is_active', true)
                      ->where('availability', true)
                      ->where('stock_qty', '>', 0);
                });
            }
        }

        // Search by custom field value
        if ($request->has('field_search')) {
            $fieldId = $request->input('field_id');
            $fieldValue = $request->input('field_search');
            
            $query->whereHas('productFields', function($q) use ($fieldId, $fieldValue) {
                if ($fieldId) {
                    $q->where('field_id', $fieldId);
                }
                $this->whereLike($q, 'value', $fieldValue);
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $perPage = $request->get('per_page', 15);
        
        $allowedSortFields = ['name', 'sku', 'created_at', 'updated_at', 'price'];

        // Handle Grouping by SKU
        if ($request->boolean('group_by_sku', true)) {
            // Step 1: Get the list of SKUs that match the filters
            // We use a subquery approach to ensure pagination works on groups, not individual products
            $filteredSkuQuery = clone $query;
            
            // If sorting by price, we need to join batches to the subquery
            if ($sortBy === 'price') {
                $filteredSkuQuery->addSelect([
                    'min_price' => \App\Models\ProductBatch::select('sell_price')
                        ->whereColumn('product_id', 'products.id')
                        ->where('is_active', true)
                        ->where('availability', true)
                        ->orderBy('sell_price', 'asc')
                        ->limit(1)
                ]);
            }

            // Create a sub-select to get unique SKUs and their representative (latest created product)
            $subQuery = DB::table('products')
                ->whereIn('id', function($q) use ($filteredSkuQuery) {
                    $q->select('id')->fromSub($filteredSkuQuery, 'sq');
                })
                ->select('sku', DB::raw('MAX(id) as representative_id'))
                ->groupBy('sku');

            // Apply sorting to the groups
            if ($sortBy === 'name') {
                // For name, we pick the MAX name in the group (usually consistent)
                $subQuery->addSelect(DB::raw('MAX(name) as sort_name'))->orderBy('sort_name', $sortDirection);
            } elseif ($sortBy === 'sku') {
                $subQuery->orderBy('sku', $sortDirection);
            } elseif ($sortBy === 'price') {
                // This is slightly complex, ideally we sort by the MIN price within the SKU group
                $subQuery->addSelect(DB::raw('(SELECT MIN(sell_price) FROM product_batches WHERE product_id IN (SELECT id FROM products p2 WHERE p2.sku = products.sku)) as min_group_price'))
                         ->orderBy('min_group_price', $sortDirection);
            } else {
                // Default to created_at
                $subQuery->addSelect(DB::raw('MAX(created_at) as latest_created'))->orderBy('latest_created', $sortDirection);
            }

            $pagedGroups = $subQuery->paginate($perPage);
            $representativeIds = collect($pagedGroups->items())->pluck('representative_id')->filter()->values();
            $skus = collect($pagedGroups->items())->pluck('sku')->filter()->values();

            // Load full models for the representatives
            $products = Product::with(['category', 'vendor', 'productFields.field', 'images' => function($q) {
                $q->where('is_active', true)->orderBy('is_primary', 'desc')->orderBy('sort_order');
            }])
            ->whereIn('id', $representativeIds)
            ->get();

            // Sort products to match the pagedGroups order
            $products = $products->sortBy(function($product) use ($representativeIds) {
                return $representativeIds->search($product->id);
            })->values();

            // Load variants for these SKUs
            $allVariants = Product::with(['productFields.field', 'images' => function($q) {
                $q->where('is_active', true)->orderBy('is_primary', 'desc')->orderBy('sort_order');
            }])
            ->whereIn('sku', $skus)
            ->whereNotIn('id', $representativeIds)
            ->where('is_archived', false) // Only active variants by default
            ->get()
            ->groupBy('sku');

            foreach ($products as $product) {
                $product->variants = $allVariants->get($product->sku, collect());
                $product->has_variants = $product->variants->isNotEmpty();
                $product->variants_count = $product->variants->count() + 1;
                $product->custom_fields = $this->formatCustomFields($product);
                
                foreach ($product->variants as $variant) {
                    $variant->custom_fields = $this->formatCustomFields($variant);
                }
            }

            return response()->json([
                'success' => true,
                'data' => $pagedGroups->setCollection($products)
            ]);
        }

        // --- Flat (non-grouped) fallback logic ---
        
        if ($sortBy === 'price') {
            $query->addSelect([
                'min_price' => \App\Models\ProductBatch::select('sell_price')
                    ->whereColumn('product_id', 'products.id')
                    ->where('is_active', true)
                    ->where('availability', true)
                    ->orderBy('sell_price', 'asc')
                    ->limit(1)
            ])->orderBy('min_price', $sortDirection);
        } elseif (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        $products = $query->paginate($perPage);

        // Transform to include formatted custom fields
        foreach ($products as $product) {
            $product->custom_fields = $this->formatCustomFields($product);
            $product->has_variants = false;
            $product->variants = [];
            $product->variants_count = 1;
        }

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Get single product with all details
     */
    public function show($id)
    {
        $product = Product::with([
            'category',
            'vendor',
            'productFields.field',
            'images',
            'barcodes',
            'batches.store',
            'priceOverrides'
        ])->findOrFail($id);

        $product->custom_fields = $this->formatCustomFields($product);
        
        // Include inventory summary
        $product->inventory_summary = [
            'total_quantity' => $product->getTotalInventory(),
            'available_batches' => $product->availableBatches()->count(),
            'lowest_price' => $product->getLowestBatchPrice(),
            'highest_price' => $product->getHighestBatchPrice(),
            'average_price' => $product->getAverageBatchPrice(),
        ];

        // ✅ Image fallback/merge for variations that don't have their own images.
        // Frontend can prefer display_images for rendering galleries.
        $product->display_images = $this->mergedActiveImages($product, [
            'id',
            'product_id',
            'image_path',
            'alt_text',
            'is_primary',
            'is_active',
            'sort_order',
        ]);

        return response()->json([
            'success' => true,
            'data' => $product
        ]);
    }

    /**
     * Create product with custom fields
     * 
     * Supports "common edit" feature:
     * - base_name: Core product name (e.g., "saree")
     * - variation_suffix: Variation identifier (e.g., "-red-30")
     * - name: Auto-computed as base_name + variation_suffix
     * 
     * If only 'name' is provided (backward compatible), it becomes base_name with empty suffix.
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'brand' => 'nullable|string|max:255',
            'sku' => 'nullable|string|max:255', // Optional - auto-generated if not provided (9-digit unique number)
            'name' => 'required_without:base_name|string|max:255',
            'base_name' => 'required_without:name|string|max:255',
            'variation_suffix' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'custom_fields' => 'nullable|array',
            'custom_fields.*.field_id' => 'required|exists:fields,id|distinct', // Prevent duplicate field_ids
            'custom_fields.*.value' => 'nullable',
        ]);

        DB::beginTransaction();
        try {
            // Determine base_name and variation_suffix
            $baseName = $validated['base_name'] ?? $validated['name'];
            $variationSuffix = $validated['variation_suffix'] ?? '';
            $displayName = $baseName . $variationSuffix;

            // Create product (SKU auto-generated in model boot if not provided)
            $product = Product::create([
                'category_id' => $validated['category_id'],
                'vendor_id' => $validated['vendor_id'] ?? null,
                'brand' => $validated['brand'] ?? null,
                'sku' => $validated['sku'] ?? null, // Will be auto-generated if null
                'name' => $displayName,
                'base_name' => $baseName,
                'variation_suffix' => $variationSuffix,
                'description' => $validated['description'] ?? null,
                'is_archived' => false,
            ]);

            // Add custom fields if provided
            if (isset($validated['custom_fields'])) {
                foreach ($validated['custom_fields'] as $fieldData) {
                    $field = Field::findOrFail($fieldData['field_id']);
                    
                    // Validate field value against field type
                    $this->validateFieldValue($field, $fieldData['value'] ?? null);
                    
                    // Use updateOrCreate to handle potential duplicates in request
                    ProductField::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'field_id' => $field->id,
                        ],
                        [
                            'value' => $this->formatFieldValue($field, $fieldData['value'] ?? null),
                        ]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product->load('productFields.field', 'category', 'vendor')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update product
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'category_id' => 'sometimes|exists:categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'brand' => 'nullable|string|max:255',
            'sku' => 'sometimes|string', // SKU not unique - supports variations
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'custom_fields' => 'nullable|array',
            'custom_fields.*.field_id' => 'required|exists:fields,id|distinct', // Prevent duplicate field_ids
            'custom_fields.*.value' => 'nullable',
        ]);

        DB::beginTransaction();
        try {
            // Update basic product info
            $product->update([
                'category_id' => $validated['category_id'] ?? $product->category_id,
                'vendor_id' => $validated['vendor_id'] ?? $product->vendor_id,
                'brand' => $validated['brand'] ?? $product->brand,
                'sku' => $validated['sku'] ?? $product->sku,
                'name' => $validated['name'] ?? $product->name,
                'description' => $validated['description'] ?? $product->description,
            ]);

            // Update custom fields if provided
            if (isset($validated['custom_fields'])) {
                foreach ($validated['custom_fields'] as $fieldData) {
                    $field = Field::findOrFail($fieldData['field_id']);
                    
                    // Validate field value
                    $this->validateFieldValue($field, $fieldData['value'] ?? null);
                    
                    ProductField::updateOrCreate(
                        [
                            'product_id' => $product->id,
                            'field_id' => $field->id,
                        ],
                        [
                            'value' => $this->formatFieldValue($field, $fieldData['value'] ?? null),
                        ]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product->load('productFields.field', 'category', 'vendor')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update specific custom field
     */
    public function updateCustomField(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'field_id' => 'required|exists:fields,id',
            'value' => 'nullable',
        ]);

        $field = Field::findOrFail($validated['field_id']);
        
        // Validate field value
        $this->validateFieldValue($field, $validated['value'] ?? null);

        ProductField::updateOrCreate(
            [
                'product_id' => $product->id,
                'field_id' => $field->id,
            ],
            [
                'value' => $this->formatFieldValue($field, $validated['value'] ?? null),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Custom field updated successfully',
            'data' => $product->load('productFields.field')
        ]);
    }

    /**
     * Remove custom field from product
     */
    public function removeCustomField($id, $fieldId)
    {
        $product = Product::findOrFail($id);
        
        $deleted = ProductField::where('product_id', $product->id)
            ->where('field_id', $fieldId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Custom field not found on this product'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Custom field removed successfully'
        ]);
    }

    /**
     * Archive product (soft delete)
     */
    public function archive($id)
    {
        $product = Product::findOrFail($id);
        $product->is_archived = true;
        $product->save();

        return response()->json([
            'success' => true,
            'message' => 'Product archived successfully'
        ]);
    }

    /**
     * Restore archived product
     */
    public function restore($id)
    {
        $product = Product::findOrFail($id);
        $product->is_archived = false;
        $product->save();

        return response()->json([
            'success' => true,
            'message' => 'Product restored successfully',
            'data' => $product
        ]);
    }

    /**
     * Permanently delete product
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        
        // Check if product has batches or orders
        if ($product->batches()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete product with existing batches. Archive it instead.'
            ], 422);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    /**
     * Get available fields for products
     */
    public function getAvailableFields()
    {
        $fields = Field::active()
            ->ordered()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $fields
        ]);
    }

    /**
     * Bulk update products
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
            'action' => 'required|in:archive,restore,update_category,update_vendor',
            'category_id' => 'required_if:action,update_category|exists:categories,id',
            'vendor_id' => 'required_if:action,update_vendor|exists:vendors,id',
        ]);

        DB::beginTransaction();
        try {
            $count = 0;

            switch ($validated['action']) {
                case 'archive':
                    $count = Product::whereIn('id', $validated['product_ids'])
                        ->update(['is_archived' => true]);
                    $message = "Archived {$count} products";
                    break;

                case 'restore':
                    $count = Product::whereIn('id', $validated['product_ids'])
                        ->update(['is_archived' => false]);
                    $message = "Restored {$count} products";
                    break;

                case 'update_category':
                    $count = Product::whereIn('id', $validated['product_ids'])
                        ->update(['category_id' => $validated['category_id']]);
                    $message = "Updated category for {$count} products";
                    break;

                case 'update_vendor':
                    $count = Product::whereIn('id', $validated['product_ids'])
                        ->update(['vendor_id' => $validated['vendor_id']]);
                    $message = "Updated vendor for {$count} products";
                    break;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Bulk update failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update common info (base_name) for all products in a SKU group.
     * 
     * This is the "magic" common edit feature:
     * - Changing base_name from "saree" to "sharee"
     * - Automatically updates all products with same SKU
     * - saree-red-30 → sharee-red-30
     * - saree-green-40 → sharee-green-40
     * 
     * @param Request $request
     * @param int $id Product ID (any product in the SKU group)
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateCommonInfo(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'base_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'sometimes|exists:categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'brand' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $sku = $product->sku;
            $newBaseName = $validated['base_name'];
            
            // Build update data for all products in SKU group
            $updateData = [
                'base_name' => $newBaseName,
            ];

            // Add optional common fields if provided
            if (isset($validated['description'])) {
                $updateData['description'] = $validated['description'];
            }
            if (isset($validated['category_id'])) {
                $updateData['category_id'] = $validated['category_id'];
            }
            if (array_key_exists('vendor_id', $validated)) {
                $updateData['vendor_id'] = $validated['vendor_id'];
            }
            if (isset($validated['brand'])) {
                $updateData['brand'] = $validated['brand'];
            }

            // Update all products in SKU group
            // Use raw SQL to concatenate base_name + variation_suffix for name
            $count = Product::where('sku', $sku)->count();
            
            Product::where('sku', $sku)->update($updateData);
            
            // Update the display name (name = base_name + variation_suffix)
            DB::statement("
                UPDATE products 
                SET name = CONCAT(?, COALESCE(variation_suffix, ''))
                WHERE sku = ?
            ", [$newBaseName, $sku]);

            DB::commit();

            // Return the updated products in the SKU group
            $updatedProducts = Product::where('sku', $sku)
                ->with(['category', 'vendor', 'productFields.field'])
                ->get();

            return response()->json([
                'success' => true,
                'message' => "Updated common info for {$count} product(s) in SKU group '{$sku}'",
                'data' => [
                    'sku' => $sku,
                    'new_base_name' => $newBaseName,
                    'products_updated' => $count,
                    'products' => $updatedProducts
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update common info: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all products in a SKU group (variations of same product)
     * 
     * @param int $id Product ID (any product in the group)
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSkuGroup($id)
    {
        $product = Product::findOrFail($id);
        
        $products = Product::where('sku', $product->sku)
            ->with(['category', 'vendor', 'productFields.field', 'images'])
            ->orderBy('variation_suffix')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'sku' => $product->sku,
                'base_name' => $product->base_name,
                'total_variations' => $products->count(),
                'products' => $products
            ]
        ]);
    }

    /**
     * Get product statistics
     */
    public function getStatistics(Request $request)
    {
        $query = Product::query();

        // Date filter
        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('created_at', [$request->from_date, $request->to_date]);
        }

        $stats = [
            'total_products' => Product::count(),
            'active_products' => Product::where('is_archived', false)->count(),
            'archived_products' => Product::where('is_archived', true)->count(),
            'by_category' => Product::where('is_archived', false)
                ->with('category:id,name')
                ->get()
                ->groupBy('category_id')
                ->map(function($group) {
                    return [
                        'category' => $group->first()->category->name ?? 'Uncategorized',
                        'count' => $group->count()
                    ];
                })
                ->values(),
            'by_vendor' => Product::where('is_archived', false)
                ->with('vendor:id,name')
                ->get()
                ->groupBy('vendor_id')
                ->map(function($group) {
                    return [
                        'vendor' => $group->first()->vendor->name ?? 'Unknown',
                        'count' => $group->count()
                    ];
                })
                ->values(),
            'recently_added' => Product::where('is_archived', false)
                ->with('category', 'vendor')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(),
            'total_inventory_value' => $this->calculateInventoryValue(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Search products by custom field value
     */
    public function searchByCustomField(Request $request)
    {
        $validated = $request->validate([
            'field_id' => 'required|exists:fields,id',
            'value' => 'required',
            'operator' => 'nullable|in:=,like,>,<,>=,<=',
        ]);

        $operator = $validated['operator'] ?? 'like';
        $value = $operator === 'like' ? "%{$validated['value']}%" : $validated['value'];

        $products = Product::whereHas('productFields', function($q) use ($validated, $operator, $value) {
            $q->where('field_id', $validated['field_id'])
              ->where('value', $operator, $value);
        })
        ->with(['category', 'vendor', 'productFields.field', 'images' => function($q) {
            $q->where('is_active', true)->orderBy('is_primary', 'desc')->orderBy('sort_order');
        }])
        ->where('is_archived', false)
        ->paginate($request->get('per_page', 15));

        foreach ($products as $product) {
            $product->custom_fields = $this->formatCustomFields($product);
        }

        return response()->json([
            'success' => true,
            'data' => $products
        ]);
    }

    /**
     * Helper: Format custom fields for display
     */
    private function formatCustomFields($product)
    {
        return $product->productFields->map(function($productField) {
            return [
                'field_id' => $productField->field_id,
                'field_title' => $productField->field->title,
                'field_type' => $productField->field->type,
                'value' => $productField->parsed_value,
                'raw_value' => $productField->value,
            ];
        });
    }

    /**
     * Helper: Validate field value based on field type
     */
    private function validateFieldValue($field, $value)
    {
        // Required field check
        if ($field->is_required && empty($value)) {
            throw new \InvalidArgumentException("Field '{$field->title}' is required");
        }

        // Skip validation if value is null/empty and field is not required
        if (empty($value) && !$field->is_required) {
            return;
        }

        // Type-specific validation
        switch ($field->type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException("Invalid email format for field '{$field->title}'");
                }
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    throw new \InvalidArgumentException("Invalid URL format for field '{$field->title}'");
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    throw new \InvalidArgumentException("Field '{$field->title}' must be a number");
                }
                break;

            case 'date':
                if (!strtotime($value)) {
                    throw new \InvalidArgumentException("Invalid date format for field '{$field->title}'");
                }
                break;

            case 'select':
            case 'radio':
                if ($field->hasOptions() && !in_array($value, $field->options)) {
                    throw new \InvalidArgumentException("Invalid option for field '{$field->title}'");
                }
                break;

            case 'checkbox':
                if ($field->hasOptions()) {
                    $values = is_array($value) ? $value : json_decode($value, true);
                    foreach ($values as $val) {
                        if (!in_array($val, $field->options)) {
                            throw new \InvalidArgumentException("Invalid option '{$val}' for field '{$field->title}'");
                        }
                    }
                }
                break;
        }
    }

    /**
     * Helper: Format field value for storage
     */
    private function formatFieldValue($field, $value)
    {
        if (empty($value)) {
            return null;
        }

        switch ($field->type) {
            case 'boolean':
                return $value ? 'true' : 'false';

            case 'json':
            case 'checkbox':
                return is_array($value) ? json_encode($value) : $value;

            case 'number':
                return (string) $value;

            default:
                return (string) $value;
        }
    }

    /**
     * Helper: Calculate total inventory value
     */
    private function calculateInventoryValue()
    {
        return DB::table('product_batches')
            ->join('products', 'product_batches.product_id', '=', 'products.id')
            ->where('products.is_archived', false)
            ->where('product_batches.quantity', '>', 0)
            ->selectRaw('SUM(product_batches.quantity * product_batches.cost_price) as total_value')
            ->value('total_value') ?? 0;
    }

    /**
     * Force delete product with all related data
     * ⚠️ ADMIN ONLY - This permanently deletes product and ALL inventory
     * 
     * DELETE /api/employee/products/{id}/force-delete
     */
    public function forceDelete($id)
    {
        $user = request()->user();
        if (!$user || ($user->role && $user->role->slug !== 'super-admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Super Admin access required.'
            ], 403);
        }

        $product = Product::withTrashed()->find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        DB::beginTransaction();
        try {
            $deletionSummary = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'deleted_at' => now()->toISOString(),
            ];

            // Check for purchase orders (these have RESTRICT constraint)
            // We can't delete purchase order items, so we'll fail if they exist
            $purchaseOrderItems = DB::table('purchase_order_items')
                ->where('product_id', $product->id)
                ->count();
            
            if ($purchaseOrderItems > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete product: {$purchaseOrderItems} purchase order item(s) reference this product. Delete purchase orders first or use archive feature instead."
                ], 422);
            }

            // 1. Delete product movements (linked via batches/barcodes)
            $movements = DB::table('product_movements')
                ->whereIn('product_batch_id', function($query) use ($product) {
                    $query->select('id')
                        ->from('product_batches')
                        ->where('product_id', $product->id);
                })
                ->orWhereIn('product_barcode_id', function($query) use ($product) {
                    $query->select('id')
                        ->from('product_barcodes')
                        ->where('product_id', $product->id);
                })
                ->delete();
            $deletionSummary['movements_deleted'] = $movements;

            // 2. Delete dispatch item barcodes (pivot table)
            $dispatchItemBarcodes = DB::table('product_dispatch_item_barcodes')
                ->whereIn('product_barcode_id', function($query) use ($product) {
                    $query->select('id')
                        ->from('product_barcodes')
                        ->where('product_id', $product->id);
                })
                ->delete();
            $deletionSummary['dispatch_item_barcodes_deleted'] = $dispatchItemBarcodes;

            // 3. Delete defective products
            $defectiveProducts = DB::table('defective_products')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['defective_products_deleted'] = $defectiveProducts;

            // 4. Delete inventory rebalancings
            $rebalancings = DB::table('inventory_rebalancings')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['rebalancings_deleted'] = $rebalancings;

            // 5. Delete master inventories
            $masterInventories = DB::table('master_inventories')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['master_inventories_deleted'] = $masterInventories;

            // 6. Delete cart items
            $cartItems = DB::table('carts')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['cart_items_deleted'] = $cartItems;

            // 7. Delete wishlist items
            $wishlistItems = DB::table('wishlists')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['wishlist_items_deleted'] = $wishlistItems;

            // 8. Delete collection products (pivot)
            $collectionProducts = DB::table('collection_products')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['collection_products_deleted'] = $collectionProducts;

            // 9. Delete ad campaign products
            $adCampaignProducts = DB::table('ad_campaign_products')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['ad_campaign_products_deleted'] = $adCampaignProducts;

            // 10. Delete order items (will cascade delete via database)
            $orderItems = DB::table('order_items')
                ->where('product_id', $product->id)
                ->count();
            $deletionSummary['order_items_affected'] = $orderItems;

            // 11. Delete product batches (this will cascade to dispatch items)
            $batches = DB::table('product_batches')
                ->where('product_id', $product->id)
                ->count();
            DB::table('product_batches')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['batches_deleted'] = $batches;

            // 12. Delete product barcodes
            $barcodes = DB::table('product_barcodes')
                ->where('product_id', $product->id)
                ->count();
            DB::table('product_barcodes')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['barcodes_deleted'] = $barcodes;

            // 13. Delete product images
            $images = DB::table('product_images')
                ->where('product_id', $product->id)
                ->count();
            DB::table('product_images')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['images_deleted'] = $images;

            // 14. Delete product fields
            $fields = DB::table('product_fields')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['fields_deleted'] = $fields;

            // 15. Delete product price overrides
            $priceOverrides = DB::table('product_price_overrides')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['price_overrides_deleted'] = $priceOverrides;

            // 16. Delete product variants
            $variants = DB::table('product_variants')
                ->where('product_id', $product->id)
                ->delete();
            $deletionSummary['variants_deleted'] = $variants;

            // 17. Finally, force delete the product itself (even if soft deleted)
            $product->forceDelete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product and all related data permanently deleted',
                'data' => $deletionSummary
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product: ' . $e->getMessage()
            ], 500);
        }
    }
}