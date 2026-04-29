<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Models\ProductBarcode;
use App\Models\ReservedProduct;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use App\Traits\ProductImageFallback;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EcommerceCatalogController extends Controller
{
    use ProductImageFallback;
    use DatabaseAgnosticSearch;
    /**
     * Get products for e-commerce (public endpoint)
     *
     * Supports: category (slug or id), price range, search (name/sku/category/color/size),
     * stock filter, sort (price_asc|price_desc|newest|name), pagination, SKU grouping.
     *
     * Architecture — two-step approach to avoid MySQL ONLY_FULL_GROUP_BY errors:
     *   Step 1: buildFilterQuery() — raw DB::table query, explicit columns only (no SELECT *),
     *           every non-aggregate column in SELECT is also in GROUP BY.
     *   Step 2: Eloquent with(['images','category','batches']) on the resolved IDs/base_names.
     */
    public function getProducts(Request $request)
    {
        try {
            $perPage    = max(1, min((int) $request->get('per_page', 30), 200));
            $isGrouped  = $request->boolean('group_by_sku', true);
            $categorySlug = $request->get('category_slug') ?? $request->get('slug');
            $categoryId   = $request->get('category_id');
            $minPrice   = $request->get('min_price');
            $maxPrice   = $request->get('max_price');
            $sortBy     = $request->get('sort_by', 'created_at');
            $sortOrder  = $request->get('sort_order', 'desc');
            $search     = $request->get('search') ?? $request->get('q');
            $inStock    = $request->get('in_stock', 'true');

            // Resolve category IDs (self + descendants)
            $categoryIds = null;
            if ($categorySlug || $categoryId) {
                $catModel = $categoryId
                    ? Category::find((int) $categoryId)
                    : Category::where('slug', $categorySlug)->first();

                if (!$catModel) {
                    return $this->emptyResponse($request, $perPage, $categorySlug, $categoryId, $minPrice, $maxPrice, $search, $sortBy, $isGrouped);
                }
                $categoryIds = $this->collectCategoryAndDescendantIds($catModel);
            }

            return $isGrouped
                ? $this->getGroupedProducts($request, $perPage, $categoryIds, $minPrice, $maxPrice, $sortBy, $search, $inStock, $categorySlug, $categoryId)
                : $this->getFlatProducts($request, $perPage, $categoryIds, $minPrice, $maxPrice, $sortBy, $sortOrder, $search, $inStock, $categorySlug, $categoryId);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching products: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Step 1: Build per-product filter query (one row per products.id).
     *
     * KEY DESIGN — safe under MySQL ONLY_FULL_GROUP_BY:
     *  - Never SELECT * from a joined table.
     *  - Every non-aggregate column in SELECT is listed in GROUP BY.
     *  - Price/stock filters use HAVING (they depend on aggregates).
     *  - Text search uses correlated WHERE EXISTS sub-selects to avoid
     *    joining more tables that would force more GROUP BY columns.
     *
     * Returned columns: id, base_name, category_id, created_at,
     *                   min_batch_price, total_qty
     */
        private function buildFilterQuery(
        ?array  $categoryIds,
        ?string $minPrice,
        ?string $maxPrice,
        string  $sortBy,
        ?string $search,
        string  $inStock
    ) {
        $q = DB::table('products')
            ->leftJoin('product_batches', function ($join) {
                $join->on('products.id', '=', 'product_batches.product_id')
                     ->where('product_batches.is_active', true)
                     ->where('product_batches.availability', true);
            })
            ->whereNull('products.deleted_at')
            ->where('products.is_archived', false)
            ->select([
                'products.id',
                'products.base_name',
                'products.created_at',
                DB::raw('MIN(product_batches.sell_price) AS min_batch_price'),
                // We keep category_id in select/groupby because it's often needed for further filtering/grouping
                'products.category_id', 
            ])
            ->groupBy('products.id', 'products.base_name', 'products.created_at', 'products.category_id');

        if ($categoryIds !== null) {
            $q->whereIn('products.category_id', $categoryIds);
        }

        if ($search) {
            $terms = explode(' ', $search);
            $q->where(function ($sq) use ($terms) {
                foreach ($terms as $term) {
                    $term = trim($term);
                    if (empty($term)) continue;
                    $like = '%' . addslashes($term) . '%';
                    
                    $sq->where(function ($wordQ) use ($like) {
                        $wordQ->where('products.name',              'like', $like)
                           ->orWhere('products.base_name',       'like', $like)
                           ->orWhere('products.sku',             'like', $like)
                           ->orWhere('products.variation_suffix','like', $like)
                           ->orWhereExists(function ($sub) use ($like) {
                               $sub->select(DB::raw(1))
                                   ->from('categories')
                                   ->whereColumn('categories.id', 'products.category_id')
                                   ->where('categories.title', 'like', $like);
                           })
                           ->orWhereExists(function ($sub) use ($like) {
                               $sub->select(DB::raw(1))
                                   ->from('product_fields')
                                   ->join('fields', 'fields.id', '=', 'product_fields.field_id')
                                   ->whereColumn('product_fields.product_id', 'products.id')
                                   ->where('product_fields.value', 'like', $like)
                                   ->whereIn('fields.title', ['Color', 'Size', 'Colour']);
                           });
                    });
                }
            });
        }

        // Apply price and stock filters first (in HAVING)
        if ($inStock === 'true' || $inStock === true) {
            $q->havingRaw('COALESCE(SUM(product_batches.quantity), 0) > 0');
        } elseif ($inStock === 'false' || $inStock === false) {
            $q->havingRaw('COALESCE(SUM(product_batches.quantity), 0) = 0');
        }

        if ($minPrice !== null && $minPrice !== '') {
            $q->havingRaw('MIN(product_batches.sell_price) >= ?', [(float) $minPrice]);
        }
        if ($maxPrice !== null && $maxPrice !== '') {
            $q->havingRaw('MIN(product_batches.sell_price) <= ?', [(float) $maxPrice]);
        }

        return $q;
    }

    /**
     * Grouped listing: groups by base_name ("mother products"), paginates groups,
     * then fetches full Eloquent data for the page's base_names.
     *
     * The outer GROUP BY base_name subquery is ONLY_FULL_GROUP_BY-safe because it
     * selects only base_name plus aggregates — no raw product columns leak through.
     */
        private function getGroupedProducts(
        Request $request,
        int     $perPage,
        ?array  $categoryIds,
        ?string $minPrice,
        ?string $maxPrice,
        string  $sortBy,
        ?string $search,
        string  $inStock,
        ?string $categorySlug,
        $categoryId
    ) {
        $page  = max(1, (int) $request->get('page', 1));

        // Step 1: Build the inner query that applies all filters and joins.
        $baseQ = $this->buildFilterQuery($categoryIds, $minPrice, $maxPrice, $sortBy, $search, $inStock);

        // Subquery wrap: group by base_name. NO select * here.
        $groupQ = DB::table(DB::raw("({$baseQ->toSql()}) AS pq"))
            ->mergeBindings($baseQ)
            ->select([
                'base_name',
                DB::raw('MIN(min_batch_price) AS group_min_price'),
                DB::raw('MAX(created_at)      AS latest_created_at'),
            ])
            ->groupBy('base_name');

        // Sorting groups
        if ($sortBy === 'price_asc') {
            $groupQ->orderBy('group_min_price', 'asc');
        } elseif ($sortBy === 'price_desc') {
            $groupQ->orderBy('group_min_price', 'desc');
        } elseif ($sortBy === 'name') {
            $groupQ->orderBy('base_name', 'asc');
        } else {
            $groupQ->orderBy('latest_created_at', 'desc');
        }

        // Count groups safely
        $totalGroupsRaw = DB::select("select count(*) as aggregate from (" . $groupQ->toSql() . ") AS gq", $groupQ->getBindings());
        $totalGroups = $totalGroupsRaw[0]->aggregate ?? 0;

        // Paginate groups
        $pagedGroups = $groupQ->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $baseNames   = $pagedGroups->pluck('base_name')->filter()->values();

        if ($baseNames->isEmpty()) {
            return $this->emptyResponse($request, $perPage, $categorySlug, $categoryId, $minPrice, $maxPrice, $search, $sortBy, true);
        }

        // Step 2: Load Eloquent models only for the filtered and paginated results.
        $allVariants = Product::with(['images', 'category', 'batches.store'])
            ->whereIn('base_name', $baseNames)
            ->where('is_archived', false)
            ->whereNull('deleted_at')
            ->get();
        $variantsByBaseName = $allVariants->groupBy('base_name');

        $orderedResult = [];
        foreach ($baseNames as $baseName) {
            $group = $variantsByBaseName->get($baseName);
            if (!$group || $group->isEmpty()) continue;

            // Sort variants within group for deterministic main product
            $mainProduct = $group->sortBy(function ($p) {
                $price = $p->batches->where('is_active', true)->where('availability', true)->where('quantity', '>', 0)->min('sell_price');
                return $price ?? PHP_INT_MAX;
            })->first() ?? $group->first();

            $groupMin = $group->min(fn($p) => $p->batches->where('is_active', true)->where('availability', true)->min('sell_price'));
            $groupMax = $group->max(fn($p) => $p->batches->where('is_active', true)->where('availability', true)->max('sell_price'));

            $formattedVariants = $group->values()->map(fn($p) => $this->formatProductForApi($p))->all();
            $formattedMain     = $this->formatProductForApi($mainProduct, (float) $groupMin);

            $totalAvailable = array_sum(array_column($formattedVariants, 'available_inventory'));
            $totalReserved  = array_sum(array_column($formattedVariants, 'reserved_inventory'));

            $orderedResult[] = [
                'id'               => $mainProduct->id,
                'name'             => $mainProduct->name,
                'base_name'        => $mainProduct->base_name,
                'variation_suffix' => $mainProduct->variation_suffix,
                'sku'              => $mainProduct->sku,
                'description'      => $mainProduct->description,
                'category'         => $mainProduct->category,
                'images'           => $mainProduct->images,
                'variants_count'   => $group->count(),
                'min_price'        => $groupMin,
                'max_price'        => $groupMax,
                'total_available'  => $totalAvailable,
                'total_reserved'   => $totalReserved,
                'variants'         => $formattedVariants,
                'main_variant'     => $formattedMain,
            ];
        }

        $lastPage = max(1, (int) ceil($totalGroups / $perPage));

        return response()->json([
            'success' => true,
            'data'    => [
                'grouped_products' => $orderedResult,
                'products'         => array_column($orderedResult, 'main_variant'),
                'pagination'       => [
                    'current_page'   => $page,
                    'last_page'      => $lastPage,
                    'per_page'       => $perPage,
                    'total'          => $totalGroups,
                    'has_more_pages' => $page < $lastPage,
                ],
                'filters_applied' => [
                    'category_slug' => $categorySlug,
                    'category_id'   => $categoryId,
                    'min_price'     => $minPrice,
                    'max_price'     => $maxPrice,
                    'search'        => $search,
                    'in_stock'      => $inStock,
                    'sort_by'       => $sortBy,
                ],
            ],
        ]);
    }

    /**
     * Flat (non-grouped) product listing.
     */
        private function getFlatProducts(
        Request $request,
        int     $perPage,
        ?array  $categoryIds,
        ?string $minPrice,
        ?string $maxPrice,
        string  $sortBy,
        string  $sortOrder,
        ?string $search,
        string  $inStock,
        ?string $categorySlug,
        $categoryId
    ) {
        $page  = max(1, (int) $request->get('page', 1));
        $baseQ = $this->buildFilterQuery($categoryIds, $minPrice, $maxPrice, $sortBy, $search, $inStock);

        if ($sortBy === 'price_asc') {
            $baseQ->orderBy('min_batch_price', 'asc');
        } elseif ($sortBy === 'price_desc') {
            $baseQ->orderBy('min_batch_price', 'desc');
        } elseif ($sortBy === 'name') {
            $baseQ->orderBy('products.name', $sortOrder === 'asc' ? 'asc' : 'desc');
        } else {
            $baseQ->orderBy('products.created_at', 'desc');
        }

        // Count safely
        $totalRaw = DB::select("select count(*) as aggregate from (" . $baseQ->toSql() . ") AS pq", $baseQ->getBindings());
        $total    = $totalRaw[0]->aggregate ?? 0;

        $rows       = $baseQ->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $productIds = $rows->pluck('id');

        $products = Product::with(['images', 'category', 'batches.store'])
            ->whereIn('id', $productIds)
            ->whereNull('deleted_at')
            ->get()
            ->sortBy(fn($p) => $productIds->search($p->id))
            ->values()
            ->map(fn($p) => $this->formatProductForApi($p));

        $lastPage = max(1, (int) ceil($total / $perPage));

        return response()->json([
            'success' => true,
            'data'    => [
                'products'   => $products,
                'pagination' => [
                    'current_page'   => $page,
                    'last_page'      => $lastPage,
                    'per_page'       => $perPage,
                    'total'          => $total,
                    'has_more_pages' => $page < $lastPage,
                ],
                'filters_applied' => [
                    'category_slug' => $categorySlug,
                    'category_id'   => $categoryId,
                    'min_price'     => $minPrice,
                    'max_price'     => $maxPrice,
                    'search'        => $search,
                    'in_stock'      => $inStock,
                    'sort_by'       => $sortBy,
                ],
            ],
        ]);
    }


    /**
     * Format a single Eloquent Product model into a frontend-compatible array.
     * Crucially adds `selling_price` and `stock_quantity` derived from batches,
     * since the products table has no selling_price column — prices live in product_batches.
     */
    private function formatProductForApi(Product $product, ?float $groupMinPrice = null): array
    {
        $activeBatches = $product->batches->where('is_active', true)->where('availability', true);

        // Selling price = lowest price from in-stock batches; fallback to any active batch
        $inStockBatches = $activeBatches->where('quantity', '>', 0)->sortBy('sell_price');
        $cheapestBatch  = $inStockBatches->first() ?? $activeBatches->sortBy('sell_price')->first();

        $sellingPrice  = $cheapestBatch ? (float) $cheapestBatch->sell_price : ($groupMinPrice ?? 0);
        $totalStock    = (int) $activeBatches->sum('quantity');

        // available_inventory = total - reserved (from reserved_products table)
        $reservedRow = \App\Models\ReservedProduct::where('product_id', $product->id)->first();
        $reservedInventory  = $reservedRow ? (int) $reservedRow->reserved_inventory : 0;
        $availableInventory = $reservedRow ? (int) $reservedRow->available_inventory : $totalStock;

        return [
            'id'                  => $product->id,
            'name'                => $product->name,
            'base_name'           => $product->base_name,
            'variation_suffix'    => $product->variation_suffix,
            'sku'                 => $product->sku,
            'description'         => $product->description,
            'selling_price'       => $sellingPrice,   // REQUIRED by frontend normalizeProduct()
            'price'               => $sellingPrice,   // alias
            'stock_quantity'      => $totalStock,
            'total_stock'         => $totalStock,
            'available_inventory' => $availableInventory,
            'reserved_inventory'  => $reservedInventory,
            'in_stock'            => $availableInventory > 0,
            'category'            => $product->category,
            'images'              => $product->images,
            'batches'             => $product->batches,
        ];
    }

    /**
     * Consistent empty response (no products / category not found).
     */
    private function emptyResponse($request, $perPage, $slug, $id, $min, $max, $search, $sort, bool $grouped = false)
    {
        $data = [
            'products'   => [],
            'pagination' => [
                'current_page'   => (int) $request->get('page', 1),
                'last_page'      => 1,
                'per_page'       => $perPage,
                'total'          => 0,
                'has_more_pages' => false,
            ],
            'filters_applied' => [
                'category_slug' => $slug,
                'category_id'   => $id,
                'min_price'     => $min,
                'max_price'     => $max,
                'search'        => $search,
                'sort_by'       => $sort,
            ],
        ];
        if ($grouped) {
            $data['grouped_products'] = [];
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Get single product details (public endpoint)
     */
    public function getProduct(Request $request, $identifier)
    {
        try {
            // Find product by ID
            $product = Product::with(['images', 'category', 'barcodes', 'batches' => function ($q) {
                    $q->orderBy('sell_price', 'asc');
                }])
                ->where('is_archived', false)
                ->where('id', $identifier)
                ->firstOrFail();

            // Related Essentials logic:
            // 1. Get up to 20 random products from the same category (one per SKU group)
            $relatedProductIds = DB::table('products')
                ->where('category_id', $product->category_id)
                ->where('base_name', '!=', $product->base_name)
                ->where('is_archived', false)
                ->whereNull('deleted_at')
                ->whereExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('product_batches')
                        ->whereColumn('product_batches.product_id', 'products.id')
                        ->where('product_batches.quantity', '>', 0)
                        ->where('product_batches.is_active', true)
                        ->where('product_batches.availability', true);
                })
                ->select(DB::raw('MIN(id) as id'))
                ->groupBy('base_name')
                ->inRandomOrder()
                ->take(20)
                ->pluck('id');

            // 2. If we have fewer than 20, fill the rest with latest products from OTHER categories
            if ($relatedProductIds->count() < 20) {
                $fillCount = 20 - $relatedProductIds->count();
                $fillIds = DB::table('products')
                    ->where('category_id', '!=', $product->category_id) // "no duplicates from this category"
                    ->where('is_archived', false)
                    ->whereNull('deleted_at')
                    ->whereExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('product_batches')
                            ->whereColumn('product_batches.product_id', 'products.id')
                            ->where('product_batches.quantity', '>', 0)
                            ->where('product_batches.is_active', true)
                            ->where('product_batches.availability', true);
                    })
                    ->select(DB::raw('MIN(id) as id'))
                    ->groupBy('base_name')
                    ->orderBy(DB::raw('MAX(created_at)'), 'desc') // Latest products
                    ->take($fillCount)
                    ->pluck('id');
                
                $relatedProductIds = $relatedProductIds->concat($fillIds);
            }

            $relatedProducts = Product::with(['images', 'batches' => function ($q) {
                    $q->orderBy('sell_price', 'asc');
                }])
                ->whereIn('id', $relatedProductIds)
                ->get();

            // Get variants (same base_name, different variation_suffix)
            $variants = collect();
            if ($product->base_name) {
                $variants = Product::with(['images', 'batches' => function ($q) {
                        $q->orderBy('sell_price', 'asc');
                    }])
                    ->where('base_name', $product->base_name)
                    ->where('id', '!=', $product->id)
                    ->where('is_archived', false)
                    ->get()
                    ->map(function ($variant) {
                        $variantStock = $variant->batches->sum('quantity');
                        $variantAvailableBatches = $variant->batches->where('quantity', '>', 0);
                        $variantLowestBatch = $variantAvailableBatches->sortBy('sell_price')->first();
                        $variantReserved = \App\Models\ReservedProduct::where('product_id', $variant->id)->first();
                        $variantAvailableInventory = $variantReserved
                            ? (int) $variantReserved->available_inventory
                            : $variantStock;

                        return [
                            'id' => $variant->id,
                            'name' => $variant->name,
                            'variation_suffix' => $variant->variation_suffix,
                            'sku' => $variant->sku,
                            'selling_price' => $variantLowestBatch ? $variantLowestBatch->sell_price : null,
                            'cost_price' => $variantLowestBatch ? $variantLowestBatch->cost_price : null,
                            'stock_quantity' => $variantStock,
                            'available_inventory' => $variantAvailableInventory,
                            'reserved_inventory' => $variantReserved ? (int) $variantReserved->reserved_inventory : 0,
                            'in_stock' => $variantAvailableInventory > 0,
                            'images' => $variant->images->where('is_active', true)->map(function ($image) {
                                return [
                                    'id' => $image->id,
                                    'url' => $image->image_url,
                                    'is_primary' => $image->is_primary,
                                    'alt_text' => $image->alt_text,
                                ];
                            }),
                        ];
                    });
            }

            $lowestBatch = $product->batches->sortBy('sell_price')->first();
            $totalStock = $product->batches->sum('quantity');
            $mainReserved = \App\Models\ReservedProduct::where('product_id', $product->id)->first();
            $availableInventory = $mainReserved
                ? (int) $mainReserved->available_inventory
                : $totalStock;

            // ✅ Merge core SKU images + variant image (primary) so details page always has images.
            $mergedImages = $this->mergedActiveImages($product, ['id','url','alt_text','is_primary','sort_order']);
            
            $response = [
                'success' => true,
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'base_name' => $product->base_name,
                        'variation_suffix' => $product->variation_suffix,
                        'brand' => $product->brand,
                        'sku' => $product->sku,
                        'description' => $product->description,
                        'selling_price' => $lowestBatch ? $lowestBatch->sell_price : 0,
                        'cost_price' => $lowestBatch ? $lowestBatch->cost_price : 0,
                        'stock_quantity' => $totalStock,
                        'available_inventory' => $availableInventory,
                        'reserved_inventory' => $mainReserved ? (int) $mainReserved->reserved_inventory : 0,
                        'in_stock' => $availableInventory > 0,
                        'has_variants' => $variants->count() > 0,
                        'variants_count' => $variants->count(),
                        'variants' => $variants,
                        // primary-first, merged across SKU group
                        'images' => $mergedImages,
                        'category' => $product->category ? [
                            'id' => $product->category->id,
                            'name' => $product->category->title,
                        ] : null,
                        'vendor' => $product->vendor ? [
                            'id' => $product->vendor->id,
                            'name' => $product->vendor->business_name,
                        ] : null,
                        'created_at' => $product->created_at,
                        'updated_at' => $product->updated_at,
                    ],
                    'related_products' => $relatedProducts->map(function ($product) {
                        $lowestBatch = $product->batches->sortBy('sell_price')->first();
                        $totalStock = $product->batches->sum('quantity');
                        
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'brand' => $product->brand,
                            'sku' => $product->sku,
                            'selling_price' => $lowestBatch ? $lowestBatch->sell_price : 0,
                            'images' => $product->images->where('is_active', true)->take(1),
                            'in_stock' => $totalStock > 0,
                        ];
                    }),
                ],
            ];

            // Always provide batches, but conditionally hide store_id (for branch availability control)
            $response['data']['product']['batches'] = $product->batches->map(function ($batch) use ($request) {
                $batchData = $batch->toArray();
                if (!$request->boolean('include_availability', true)) {
                    unset($batchData['store_id']);
                    unset($batchData['store']); // Also unset eager-loaded store relation if any
                }
                return $batchData;
            });

            return response()->json($response);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }
    }

    /**
     * Get categories for e-commerce (public endpoint)
     */
    public function getCategories(Request $request)
    {
        try {
            $cacheKey = 'ecommerce_categories_tree';
            $categoriesTree = Cache::remember($cacheKey, 3600, function () {
                $categories = Category::with(['children' => function($q) {
                        $q->where('is_active', true)->orderBy('order', 'asc');
                    }])
                    ->where('is_active', true)
                    ->whereNull('parent_id')
                    ->orderBy('order', 'asc')
                    ->get();

                return $this->transformCategoriesTree($categories);
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'categories' => $categoriesTree,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get categories: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Internal recursive helper to transform category tree
     */
    private function transformCategoriesTree($categories)
    {
        return $categories->map(function ($category) {
            $data = [
                'id' => $category->id,
                'name' => $category->title,
                'slug' => $category->slug,
                'description' => $category->description,
                'image_url' => $category->image_url,
                'product_count' => $category->products()->count(),
            ];

            if ($category->children && $category->children->count() > 0) {
                $data['children'] = $this->transformCategoriesTree($category->children);
            } else {
                $data['children'] = [];
            }

            return $data;
        });
    }

    /**
     * Get featured products (public endpoint)
     * Note: Since products table doesn't have is_featured, returning newest products with stock
     */
    public function getFeaturedProducts(Request $request)
    {
        try {
            $limit = min($request->get('limit', 8), 20);

            $cacheKey = "featured_products_{$limit}";
            $products = Cache::remember($cacheKey, 1800, function () use ($limit) {
                return Product::with(['images', 'category', 'batches' => function ($q) {
                        $q->orderBy('sell_price', 'asc');
                    }])
                    ->where('is_archived', false)
                    ->whereHas('batches', function ($q) {
                        $q->where('quantity', '>', 0);
                    })
                    ->orderBy('created_at', 'desc')
                    ->take($limit)
                    ->get();
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'featured_products' => $products->map(function ($product) {
                        $lowestBatch = $product->batches->sortBy('sell_price')->first();
                        $totalStock = $product->batches->sum('quantity');
                        
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'sku' => $product->sku,
                            'selling_price' => $lowestBatch ? $lowestBatch->sell_price : 0,
                            'images' => $product->images->where('is_active', true)->take(2)->map(function ($image) {
                                return [
                                    'id' => $image->id,
                                    'url' => $image->image_url,
                                    'alt_text' => $image->alt_text,
                                    'is_primary' => $image->is_primary,
                                ];
                            }),
                            'category' => $product->category ? [
                                'name' => $product->category->title,
                            ] : null,
                            'in_stock' => $totalStock > 0,
                        ];
                    }),
                    'total_featured' => $products->count(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get featured products: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Product search with suggestions (public endpoint)
     */
    public function searchProducts(Request $request)
    {
        try {
            $searchQuery = $request->get('q');
            $perPage = max(1, min((int) $request->get('per_page', 30), 200));

            if (!$searchQuery || strlen($searchQuery) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Search query must be at least 2 characters',
                ], 400);
            }

            $products = Product::with(['images', 'category', 'batches' => function ($q) {
                    $q->orderBy('sell_price', 'asc');
                }])
                ->where('is_archived', false)
                ->whereHas('batches', function ($q) {
                    $q->where('quantity', '>', 0);
                })
                ->where(function ($query) use ($searchQuery) {
                    $this->whereAnyLike($query, ['name', 'sku'], $searchQuery);
                });

            // Add relevance ordering
            $this->searchWithRelevance($products, ['name', 'sku'], $searchQuery, 'name');
            
            $products = $products->paginate($perPage);

            // Get search suggestions
            $suggestions = Product::where('is_archived', false)
                ->where(function ($query) use ($searchQuery) {
                    $this->whereLike($query, 'name', $searchQuery, 'start');
                })
                ->pluck('name')
                ->take(5);

            $transformedProducts = collect($products->items())->map(function ($product) {
                $lowestBatch = $product->batches->sortBy('sell_price')->first();
                $totalStock = $product->batches->sum('quantity');
                
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'brand' => $product->brand,
                    'sku' => $product->sku,
                    'selling_price' => $lowestBatch ? $lowestBatch->sell_price : 0,
                    'images' => $product->images->where('is_active', true)->take(1),
                    'category' => $product->category->title ?? null,
                    'in_stock' => $totalStock > 0,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'products' => $transformedProducts,
                    'suggestions' => $suggestions,
                    'search_query' => $searchQuery,
                    'pagination' => [
                        'current_page' => $products->currentPage(),
                        'last_page' => $products->lastPage(),
                        'per_page' => $products->perPage(),
                        'total' => $products->total(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get price range for filtering (public endpoint)
     */
    public function getPriceRange()
    {
        try {
            $cacheKey = 'product_price_range';
            $priceRange = Cache::remember($cacheKey, 3600, function () {
                $minPrice = \App\Models\ProductBatch::where('quantity', '>', 0)
                    ->min('sell_price');

                $maxPrice = \App\Models\ProductBatch::where('quantity', '>', 0)
                    ->max('sell_price');

                return [
                    'min_price' => $minPrice ?? 0,
                    'max_price' => $maxPrice ?? 0,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $priceRange,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get price range: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get new arrivals (public endpoint)
     */
    public function getNewArrivals(Request $request)
    {
        try {
            $limit = min($request->get('limit', 8), 20);
            $days = $request->get('days', 30); // Products added in last 30 days

            $products = Product::with(['images', 'category', 'batches' => function ($q) {
                    $q->orderBy('sell_price', 'asc');
                }])
                ->where('is_archived', false)
                ->whereHas('batches', function ($q) {
                    $q->where('quantity', '>', 0);
                })
                ->where('created_at', '>=', now()->subDays($days))
                ->orderBy('created_at', 'desc')
                ->take($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'new_arrivals' => $products->map(function ($product) {
                        $lowestBatch = $product->batches->sortBy('sell_price')->first();
                        
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'brand' => $product->brand,
                            'sku' => $product->sku,
                            'selling_price' => $lowestBatch ? $lowestBatch->sell_price : 0,
                            'images' => $product->images->where('is_active', true)->take(2)->map(function ($image) {
                                return [
                                    'id' => $image->id,
                                    'url' => $image->image_url,
                                    'alt_text' => $image->alt_text,
                                    'is_primary' => $image->is_primary,
                                ];
                            }),
                            'category' => $product->category->title ?? null,
                            'added_days_ago' => $product->created_at->diffInDays(now()),
                        ];
                    }),
                    'total_new_arrivals' => $products->count(),
                    'days_range' => $days,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get new arrivals: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get suggested products based on sales (public endpoint)
     * Returns top 5 best-selling products
     */
    public function getSuggestedProducts(Request $request)
    {
        try {
            $limit = min($request->get('limit', 5), 20);

            $cacheKey = "suggested_products_{$limit}";
            $products = Cache::remember($cacheKey, 1800, function () use ($limit) {
                // Get top products by total quantity sold
                $topProductIds = \DB::table('order_items')
                    ->select('product_id', \DB::raw('SUM(quantity) as total_sold'))
                    ->whereNotNull('product_id')
                    ->groupBy('product_id')
                    ->orderByDesc('total_sold')
                    ->limit($limit)
                    ->pluck('product_id');

                if ($topProductIds->isEmpty()) {
                    // Fallback to newest products if no sales data
                    return Product::with(['images', 'category', 'batches' => function ($q) {
                            $q->orderBy('sell_price', 'asc');
                        }])
                        ->where('is_archived', false)
                        ->whereHas('batches', function ($q) {
                            $q->where('quantity', '>', 0);
                        })
                        ->orderBy('created_at', 'desc')
                        ->take($limit)
                        ->get();
                }

                // Get products with their sales data preserved in order
                return Product::with(['images', 'category', 'batches' => function ($q) {
                        $q->orderBy('sell_price', 'asc');
                    }])
                    ->where('is_archived', false)
                    ->whereHas('batches', function ($q) {
                        $q->where('quantity', '>', 0);
                    })
                    ->whereIn('id', $topProductIds)
                    ->get()
                    ->sortBy(function ($product) use ($topProductIds) {
                        return array_search($product->id, $topProductIds->toArray());
                    })
                    ->values();
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'suggested_products' => $products->map(function ($product) {
                        $lowestBatch = $product->batches->sortBy('sell_price')->first();
                        $totalStock = $product->batches->sum('quantity');
                        
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                            'brand' => $product->brand,
                            'sku' => $product->sku,
                            'selling_price' => $lowestBatch ? $lowestBatch->sell_price : 0,
                            'images' => $product->images->where('is_active', true)->take(2)->map(function ($image) {
                                return [
                                    'id' => $image->id,
                                    'url' => $image->image_url,
                                    'alt_text' => $image->alt_text,
                                    'is_primary' => $image->is_primary,
                                ];
                            }),
                            'category' => $product->category ? [
                                'name' => $product->category->title,
                            ] : null,
                            'in_stock' => $totalStock > 0,
                        ];
                    }),
                    'total_suggested' => $products->count(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get suggested products: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Enhanced search for products across name, category, subcategories, color and size
     */
    private function applyEnhancedSearch($query, $search)
    {
        $query->where(function ($q) use ($search) {
            // Name and SKU
            $q->where('products.name', 'like', "%{$search}%")
              ->orWhere('products.base_name', 'like', "%{$search}%")
              ->orWhere('products.sku', 'like', "%{$search}%")
              
              // Category & Subcategories
              ->orWhereHas('category', function ($catQ) use ($search) {
                  $catQ->where('title', 'like', "%{$search}%");
              })
              
              // Color and Size (stored in variation_suffix or productFields)
              ->orWhere('products.variation_suffix', 'like', "%{$search}%")
              ->orWhereHas('productFields', function ($fieldQ) use ($search) {
                  $fieldQ->where('value', 'like', "%{$search}%") // 'value' is the column, 'parsed_value' is the accessor
                         ->whereIn('field_id', function($subQ) {
                            $subQ->select('id')->from('fields')
                                 ->whereIn('title', ['Color', 'Size', 'Colour'])
                                 ->orWhereIn('slug', ['color', 'size', 'colour']);
                         });
              });
        });
    }

    /**
     * Collect the given category id plus all descendant category ids.
     *
     * This project stores a materialized path in `categories.path` where each row
     * keeps ancestor ids separated by "/". Example:
     * - root: path = null
     * - child of 5: path = "5"
     * - grandchild of 5: path = "5/12"
     *
     * So descendants of id=5 are rows whose path equals "5" OR starts with "5/"
     * OR ends with "/5" OR contains "/5/".
     */
    /**
     * Public endpoint to find stock details by barcode.
     * 
     * GET /api/catalog/find-stock/{barcode}
     */
    public function findStockByBarcode(Request $request, $barcode)
    {
        try {
            // 1. Find the barcode record
            $barcodeRecord = ProductBarcode::where('barcode', $barcode)
                ->where('is_active', true)
                ->first();

            if (!$barcodeRecord) {
                return response()->json([
                    'success' => false,
                    'message' => "Barcode {$barcode} not found or inactive.",
                ], 404);
            }

            // 2. Load the main product with all necessary relations
            $product = Product::with([
                'images', 
                'category', 
                'batches.store' => function($q) {
                    $q->where('is_active', true);
                }
            ])
            ->where('id', $barcodeRecord->product_id)
            ->where('is_archived', false)
            ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => "Associated product not found or archived.",
                ], 404);
            }

            // 3. Stock Calculations
            $activeBatches = $product->batches->where('is_active', true)->where('availability', true);
            $totalPhysicalStock = (int) $activeBatches->sum('quantity');
            
            // Reserved stock logic
            $reservedRow = ReservedProduct::where('product_id', $product->id)->first();
            $reservedInventory = $reservedRow ? (int) $reservedRow->reserved_inventory : 0;
            $availableInventory = $totalPhysicalStock - $reservedInventory;

            // 4. Load Variants (SKU group)
            $variants = collect();
            if ($product->sku) {
                $variants = Product::with(['images', 'batches' => function($q) {
                        $q->where('is_active', true)->where('availability', true);
                    }])
                    ->where('sku', $product->sku)
                    ->where('is_archived', false)
                    ->get()
                    ->map(function ($variant) {
                        $vStock = (int) $variant->batches->sum('quantity');
                        $vReserved = ReservedProduct::where('product_id', $variant->id)->value('reserved_inventory') ?? 0;
                        return [
                            'id' => $variant->id,
                            'name' => $variant->name,
                            'variation_suffix' => $variant->variation_suffix,
                            'sku' => $variant->sku,
                            'stock_quantity' => $vStock,
                            'available_inventory' => $vStock - $vReserved,
                            'in_stock' => $vStock > 0,
                            'primary_image' => $variant->images->where('is_active', true)->where('is_primary', true)->first()?->image_url 
                                            ?? $variant->images->where('is_active', true)->first()?->image_url,
                        ];
                    });
            }

            // 5. Image Merging
            $mergedImages = $this->mergedActiveImages($product);

            // 6. Branch-wise breakdown
            $branchStock = $product->batches
                ->where('is_active', true)
                ->where('availability', true)
                ->filter(fn($batch) => $batch->store !== null)
                ->groupBy('store_id')
                ->map(function ($storeBatches) {
                    $store = $storeBatches->first()->store;
                    return [
                        'store_id' => $store->id,
                        'store_name' => $store->name,
                        'store_address' => $store->address,
                        'quantity' => $storeBatches->sum('quantity'),
                    ];
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'description' => $product->description,
                    'category' => $product->category ? $product->category->title : null,
                    'images' => $mergedImages,
                    'inventory' => [
                        'physical_stock' => $totalPhysicalStock,
                        'reserved_stock' => $reservedInventory,
                        'available_stock' => max(0, $availableInventory),
                    ],
                    'branch_stock' => $branchStock,
                    'variants' => $variants,
                    'scanned_barcode' => $barcode,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Error finding stock: " . $e->getMessage(),
            ], 500);
        }
    }

    private function collectCategoryAndDescendantIds(Category $category): array
    {
        $id = (int) $category->id;

        // Start with self.
        $ids = collect([$id]);

        // Fetch descendants in one query using the materialized path.
        // Note: path does not include the category's own id, only ancestors.
        $descendantIds = Category::query()
            ->where('path', (string) $id)
            ->orWhere('path', 'like', $id . '/%')
            ->orWhere('path', 'like', '%/' . $id)
            ->orWhere('path', 'like', '%/' . $id . '/%')
            ->pluck('id');

        return $ids->merge($descendantIds)->unique()->values()->all();
    }
}