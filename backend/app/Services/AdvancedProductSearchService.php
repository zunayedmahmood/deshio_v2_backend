<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use App\Models\Field;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Advanced Product Search Service
 * 
 * Provides comprehensive product search functionality with:
 * - Multi-field text search
 * - Category filtering (with subcategories)
 * - Custom field filtering (color, size, brand)
 * - Vendor filtering
 * - Relevance-based ranking
 * - Performance optimization via caching
 */
class AdvancedProductSearchService
{
    protected SearchRelevanceScorer $scorer;

    public function __construct(SearchRelevanceScorer $scorer)
    {
        $this->scorer = $scorer;
    }

    /**
     * Main search method
     */
    public function search(array $params): array
    {
        $startTime = microtime(true);

        // Build the query
        $query = $this->buildBaseQuery($params);

        // Apply filters
        $query = $this->applyFilters($query, $params);

        // Apply text search if query provided
        if (!empty($params['q'])) {
            $query = $this->applyTextSearch($query, $params['q']);
        }

        // Get total count before pagination
        $total = $query->count();

        // Apply sorting
        $query = $this->applySorting($query, $params['sort_by'] ?? 'relevance', $params['q'] ?? '');

        // Apply pagination with validation
        $perPage = isset($params['per_page']) ? max(1, min((int)$params['per_page'], 100)) : 20;
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $offset = ($page - 1) * $perPage;

        // Execute query with eager loading
        $products = $query->with([
            'category:id,title,slug,parent_id',
            'vendor:id,name',
            'images' => function($q) {
                $q->where('is_active', true)
                  ->where('is_primary', true)
                  ->select('id', 'product_id', 'image_path', 'alt_text');
            },
            'productFields.field:id,title,type'
        ])
        ->offset($offset)
        ->limit($perPage)
        ->get();

        // Calculate relevance scores if text search was performed
        if (!empty($params['q'])) {
            $searchContext = [
                'category_id' => $params['category_id'] ?? null,
            ];
            $products = $this->scorer->scoreAndRankResults($products, $params['q'], $searchContext);
            
            // Add match type
            $products = $products->map(function ($product) use ($params) {
                $product->match_type = $this->scorer->determineMatchType(
                    $product->name ?? '', 
                    $params['q']
                );
                return $product;
            });
        }

        $endTime = microtime(true);
        $searchTime = round(($endTime - $startTime) * 1000, 2);

        return [
            'products' => $products,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
            'search_time_ms' => $searchTime,
        ];
    }

    /**
     * Build base query with common conditions
     */
    protected function buildBaseQuery(array $params)
    {
        $query = Product::query()->where('is_archived', false);

        return $query;
    }

    /**
     * Apply all filters to the query
     */
    protected function applyFilters($query, array $params)
    {
        // Category filter (validate it's numeric)
        if (!empty($params['category_id'])) {
            // Handle single or array of category IDs
            $categoryIdInput = $params['category_id'];
            
            // Validate and filter numeric values only
            if (is_array($categoryIdInput)) {
                $categoryIdInput = array_filter($categoryIdInput, function($id) {
                    return is_numeric($id) && (int)$id > 0;
                });
                
                if (!empty($categoryIdInput)) {
                    $categoryIds = [];
                    foreach ($categoryIdInput as $catId) {
                        $ids = $this->getCategoryIds((int)$catId, $params['include_subcategories'] ?? true);
                        $categoryIds = array_merge($categoryIds, $ids);
                    }
                    $categoryIds = array_unique($categoryIds);
                    $query->whereIn('category_id', $categoryIds);
                }
            } elseif (is_numeric($categoryIdInput) && (int)$categoryIdInput > 0) {
                $categoryIds = $this->getCategoryIds((int)$categoryIdInput, $params['include_subcategories'] ?? true);
                $query->whereIn('category_id', $categoryIds);
            }
            // If invalid, silently ignore (will return all results or apply other filters)
        }

        // Category slug filter (alternative to ID)
        if (!empty($params['category_slug'])) {
            $category = Category::where('slug', $params['category_slug'])->first();
            if ($category) {
                $categoryIds = $this->getCategoryIds($category->id, $params['include_subcategories'] ?? true);
                $query->whereIn('category_id', $categoryIds);
            }
        }

        // Vendor filter (validate numeric)
        if (!empty($params['vendor_id'])) {
            if (is_array($params['vendor_id'])) {
                $vendorIds = array_filter($params['vendor_id'], function($id) {
                    return is_numeric($id) && (int)$id > 0;
                });
                
                if (!empty($vendorIds)) {
                    $query->whereIn('vendor_id', array_map('intval', $vendorIds));
                }
            } elseif (is_numeric($params['vendor_id']) && (int)$params['vendor_id'] > 0) {
                $query->where('vendor_id', (int)$params['vendor_id']);
            }
        }

        // Custom field filters
        $query = $this->applyCustomFieldFilters($query, $params);

        return $query;
    }

    /**
     * Apply custom field filters (color, size, brand)
     */
    protected function applyCustomFieldFilters($query, array $params)
    {
        $fieldMappings = [
            'color' => 1,  // Field ID for Color
            'size' => 2,   // Field ID for Size
            'brand' => 3,  // Field ID for Brand
        ];

        foreach ($fieldMappings as $paramKey => $fieldId) {
            if (!empty($params[$paramKey])) {
                $values = is_array($params[$paramKey]) ? $params[$paramKey] : [$params[$paramKey]];
                
                $query->whereHas('productFields', function($q) use ($fieldId, $values) {
                    $q->where('field_id', $fieldId)
                      ->where(function($subQ) use ($values) {
                          foreach ($values as $value) {
                              $subQ->orWhere('value', 'LIKE', '%' . $value . '%');
                          }
                      });
                });
            }
        }

        return $query;
    }

    /**
     * Apply text search across multiple fields
     */
    protected function applyTextSearch($query, string $searchQuery)
    {
        $searchQuery = trim($searchQuery);
        
        if (empty($searchQuery)) {
            return $query;
        }

        $terms = $this->extractSearchTerms($searchQuery);

        $query->where(function($q) use ($terms, $searchQuery) {
            foreach ($terms as $term) {
                // Name matches
                $q->orWhere('name', 'LIKE', $term)                    // Exact
                  ->orWhere('name', 'LIKE', $term . '%')             // Prefix
                  ->orWhere('name', 'LIKE', '% ' . $term . '%')      // Word boundary
                  ->orWhere('name', 'LIKE', '%' . $term . '%');      // Contains

                // SKU matches - exact and partial
                $q->orWhere('sku', '=', $term)                       // Exact match
                  ->orWhere('sku', 'LIKE', $term . '%')
                  ->orWhere('sku', 'LIKE', '%' . $term . '%');

                // Description matches (lower priority)
                if (strlen($term) > 3) {
                    $q->orWhere('description', 'LIKE', '%' . $term . '%');
                }
            }

            // Category name search
            $q->orWhereHas('category', function($catQ) use ($terms) {
                $catQ->where(function($subQ) use ($terms) {
                    foreach ($terms as $term) {
                        $subQ->orWhere('title', 'LIKE', $term . '%')
                             ->orWhere('title', 'LIKE', '%' . $term . '%');
                    }
                });
            });

            // Custom field search
            $q->orWhereHas('productFields', function($fieldQ) use ($terms) {
                $fieldQ->where(function($subQ) use ($terms) {
                    foreach ($terms as $term) {
                        $subQ->orWhere('value', 'LIKE', '%' . $term . '%');
                    }
                });
            });
        });

        return $query;
    }

    /**
     * Extract search terms from query
     */
    protected function extractSearchTerms(string $query): array
    {
        // Clean the query
        $query = mb_strtolower(trim($query), 'UTF-8');
        
        // Split into words
        $words = preg_split('/\s+/', $query);
        
        // Filter out very short words and duplicates
        $terms = array_unique(array_filter($words, function($word) {
            return strlen($word) >= 2;
        }));

        // Also include the full query
        array_unshift($terms, $query);

        return $terms;
    }

    /**
     * Get category IDs including subcategories if requested
     */
    protected function getCategoryIds(int $categoryId, bool $includeSubcategories = true): array
    {
        $cacheKey = "category_tree_{$categoryId}_{$includeSubcategories}";
        
        return Cache::remember($cacheKey, 1800, function() use ($categoryId, $includeSubcategories) {
            $ids = [$categoryId];
            
            if ($includeSubcategories) {
                $ids = array_merge($ids, $this->getSubcategoryIds($categoryId));
            }
            
            return $ids;
        });
    }

    /**
     * Recursively get all subcategory IDs
     */
    protected function getSubcategoryIds(int $parentId): array
    {
        $subcategories = Category::where('parent_id', $parentId)->pluck('id')->toArray();
        
        $allIds = $subcategories;
        
        foreach ($subcategories as $subcatId) {
            $allIds = array_merge($allIds, $this->getSubcategoryIds($subcatId));
        }
        
        return $allIds;
    }

    /**
     * Apply sorting to the query
     */
    protected function applySorting($query, string $sortBy, string $searchQuery)
    {
        switch ($sortBy) {
            case 'name':
                return $query->orderBy('name', 'asc');
            
            case 'name_desc':
                return $query->orderBy('name', 'desc');
            
            case 'newest':
                return $query->orderBy('created_at', 'desc');
            
            case 'oldest':
                return $query->orderBy('created_at', 'asc');
            
            case 'updated':
                return $query->orderBy('updated_at', 'desc');
            
            case 'relevance':
            default:
                // Relevance sorting is handled after query execution
                // For now, sort by created_at as secondary
                return $query->orderBy('created_at', 'desc');
        }
    }

    /**
     * Get available filter options
     */
    public function getAvailableFilters(array $params = []): array
    {
        $cacheKey = 'search_filters_' . md5(json_encode($params));
        
        return Cache::remember($cacheKey, 600, function() use ($params) {
            // Get categories with product counts
            $categories = Category::withCount(['products' => function($q) {
                $q->where('is_archived', false);
            }])
            ->where('is_active', true)
            ->orderBy('order')
            ->orderBy('title')
            ->get()
            ->map(function($cat) {
                return [
                    'id' => $cat->id,
                    'title' => $cat->title,
                    'slug' => $cat->slug,
                    'parent_id' => $cat->parent_id,
                    'product_count' => $cat->products_count,
                ];
            });

            // Build category tree
            $categoryTree = $this->buildCategoryTree($categories);

            // Get unique field values
            $fields = $this->getUniqueFieldValues();

            // Get vendors
            $vendors = DB::table('vendors')
                ->select('vendors.id', 'vendors.name', DB::raw('COUNT(products.id) as product_count'))
                ->join('products', 'vendors.id', '=', 'products.vendor_id')
                ->where('products.is_archived', false)
                ->groupBy('vendors.id', 'vendors.name')
                ->orderBy('vendors.name')
                ->get();

            return [
                'categories' => $categoryTree,
                'fields' => $fields,
                'vendors' => $vendors,
            ];
        });
    }

    /**
     * Build hierarchical category tree
     */
    protected function buildCategoryTree($categories, $parentId = null): array
    {
        $tree = [];

        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $children = $this->buildCategoryTree($categories, $category['id']);
                if (!empty($children)) {
                    $category['subcategories'] = $children;
                }
                $tree[] = $category;
            }
        }

        return $tree;
    }

    /**
     * Get unique values for custom fields
     */
    protected function getUniqueFieldValues(): array
    {
        $fieldValues = [];

        $fields = Field::whereIn('id', [1, 2, 3])->get(); // Color, Size, Brand

        foreach ($fields as $field) {
            $values = DB::table('product_fields')
                ->join('products', 'product_fields.product_id', '=', 'products.id')
                ->where('product_fields.field_id', $field->id)
                ->where('products.is_archived', false)
                ->whereNotNull('product_fields.value')
                ->where('product_fields.value', '!=', '')
                ->distinct()
                ->pluck('product_fields.value')
                ->filter()
                ->sort()
                ->values()
                ->toArray();

            $fieldKey = strtolower($field->title);
            $fieldValues[$fieldKey] = array_slice($values, 0, 100); // Limit to 100 values
        }

        return $fieldValues;
    }

    /**
     * Advanced search with boolean logic
     */
    public function advancedSearch(array $filters, array $sorting = [], int $perPage = 30, int $page = 1): array
    {
        $startTime = microtime(true);

        $query = Product::query()->where('is_archived', false);

        // Apply MUST filters (AND logic)
        if (!empty($filters['must'])) {
            if (!empty($filters['must']['category_ids'])) {
                $query->whereIn('category_id', $filters['must']['category_ids']);
            }
            if (!empty($filters['must']['vendor_ids'])) {
                $query->whereIn('vendor_id', $filters['must']['vendor_ids']);
            }
        }

        // Apply SHOULD filters (OR logic)
        if (!empty($filters['should'])) {
            $query->where(function($q) use ($filters) {
                if (!empty($filters['should']['colors'])) {
                    $q->orWhereHas('productFields', function($fieldQ) use ($filters) {
                        $fieldQ->where('field_id', 1)
                               ->whereIn('value', $filters['should']['colors']);
                    });
                }
                if (!empty($filters['should']['sizes'])) {
                    $q->orWhereHas('productFields', function($fieldQ) use ($filters) {
                        $fieldQ->where('field_id', 2)
                               ->whereIn('value', $filters['should']['sizes']);
                    });
                }
            });
        }

        // Apply MUST_NOT filters (NOT logic)
        if (!empty($filters['must_not'])) {
            if (isset($filters['must_not']['is_archived'])) {
                $query->where('is_archived', $filters['must_not']['is_archived']);
            }
        }

        // Apply text query if present
        if (!empty($filters['query'])) {
            $query = $this->applyTextSearch($query, $filters['query']);
        }

        $total = $query->count();

        // Apply sorting
        if (!empty($sorting)) {
            foreach ($sorting as $sort) {
                $field = $sort['field'] ?? 'created_at';
                $order = $sort['order'] ?? 'desc';
                
                if ($field !== 'relevance') {
                    $query->orderBy($field, $order);
                }
            }
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Pagination
        $offset = ($page - 1) * $perPage;
        
        $products = $query->with([
            'category:id,title,slug',
            'vendor:id,name',
            'images' => function($q) {
                $q->where('is_active', true)
                  ->where('is_primary', true)
                  ->select('id', 'product_id', 'image_path', 'alt_text');
            },
            'productFields.field'
        ])
        ->offset($offset)
        ->limit($perPage)
        ->get();

        $endTime = microtime(true);
        $searchTime = round(($endTime - $startTime) * 1000, 2);

        return [
            'products' => $products,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
            'search_time_ms' => $searchTime,
        ];
    }
}
