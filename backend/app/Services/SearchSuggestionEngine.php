<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Search Suggestion Engine
 * 
 * Provides intelligent autocomplete suggestions from multiple sources:
 * - Categories (highest priority)
 * - Product names
 * - Brand names
 * - Common keywords
 */
class SearchSuggestionEngine
{
    // Suggestion type priorities
    const PRIORITY_CATEGORY = 10;
    const PRIORITY_PRODUCT = 8;
    const PRIORITY_BRAND = 7;
    const PRIORITY_KEYWORD = 5;

    /**
     * Get search suggestions for a partial query
     */
    public function getSuggestions(string $query, int $limit = 8): array
    {
        $query = mb_strtolower(trim($query), 'UTF-8');
        
        if (strlen($query) < 1) {
            return [];
        }

        $suggestions = [];

        // Get suggestions from each source
        $suggestions = array_merge($suggestions, $this->getCategorySuggestions($query));
        $suggestions = array_merge($suggestions, $this->getProductSuggestions($query));
        $suggestions = array_merge($suggestions, $this->getBrandSuggestions($query));

        // Calculate relevance and deduplicate
        $suggestions = $this->processAndRankSuggestions($suggestions, $query);

        // Limit results
        return array_slice($suggestions, 0, $limit);
    }

    /**
     * Get category-based suggestions
     */
    protected function getCategorySuggestions(string $query): array
    {
        $cacheKey = "suggestions_categories_{$query}";
        
        return Cache::remember($cacheKey, 1800, function() use ($query) {
            $categories = Category::where('is_active', true)
                ->where('title', 'LIKE', $query . '%')
                ->orWhere('title', 'LIKE', '% ' . $query . '%')
                ->limit(5)
                ->get();

            $suggestions = [];
            foreach ($categories as $category) {
                $productCount = Product::where('category_id', $category->id)
                    ->where('is_archived', false)
                    ->count();

                $suggestions[] = [
                    'text' => $category->title,
                    'type' => 'category',
                    'count' => $productCount,
                    'priority' => self::PRIORITY_CATEGORY,
                    'meta' => [
                        'id' => $category->id,
                        'slug' => $category->slug,
                    ],
                ];
            }

            return $suggestions;
        });
    }

    /**
     * Get product name suggestions
     */
    protected function getProductSuggestions(string $query): array
    {
        $products = Product::where('is_archived', false)
            ->where(function($q) use ($query) {
                $q->where('name', 'LIKE', $query . '%')
                  ->orWhere('name', 'LIKE', '% ' . $query . '%');
            })
            ->select('name')
            ->groupBy('name')
            ->limit(5)
            ->get();

        $suggestions = [];
        foreach ($products as $product) {
            // Count how many products have this name (for variations)
            $count = Product::where('name', $product->name)
                ->where('is_archived', false)
                ->count();

            $suggestions[] = [
                'text' => $product->name,
                'type' => 'product',
                'count' => $count,
                'priority' => self::PRIORITY_PRODUCT,
            ];
        }

        return $suggestions;
    }

    /**
     * Get brand name suggestions
     */
    protected function getBrandSuggestions(string $query): array
    {
        $cacheKey = "suggestions_brands_{$query}";
        
        return Cache::remember($cacheKey, 1800, function() use ($query) {
            // Get brands from product_fields (field_id = 3 for Brand)
            $brands = DB::table('product_fields')
                ->join('products', 'product_fields.product_id', '=', 'products.id')
                ->where('product_fields.field_id', 3)
                ->where('products.is_archived', false)
                ->where('product_fields.value', 'LIKE', $query . '%')
                ->select('product_fields.value', DB::raw('COUNT(*) as count'))
                ->groupBy('product_fields.value')
                ->orderBy('count', 'desc')
                ->limit(5)
                ->get();

            $suggestions = [];
            foreach ($brands as $brand) {
                $suggestions[] = [
                    'text' => $brand->value,
                    'type' => 'brand',
                    'count' => $brand->count,
                    'priority' => self::PRIORITY_BRAND,
                ];
            }

            return $suggestions;
        });
    }

    /**
     * Process and rank suggestions by relevance
     */
    protected function processAndRankSuggestions(array $suggestions, string $query): array
    {
        // Calculate relevance score for each suggestion
        foreach ($suggestions as &$suggestion) {
            $similarity = $this->calculateRelevance($suggestion['text'], $query);
            $suggestion['relevance'] = ($suggestion['priority'] * 10) + $similarity;
        }

        // Remove duplicates (same text)
        $unique = [];
        $seen = [];
        
        foreach ($suggestions as $suggestion) {
            $key = mb_strtolower($suggestion['text'], 'UTF-8');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $suggestion;
            }
        }

        // Sort by relevance score
        usort($unique, function($a, $b) {
            return $b['relevance'] <=> $a['relevance'];
        });

        return $unique;
    }

    /**
     * Calculate relevance between query and suggestion
     */
    protected function calculateRelevance(string $text, string $query): float
    {
        $text = mb_strtolower($text, 'UTF-8');
        $query = mb_strtolower($query, 'UTF-8');

        // Exact match
        if ($text === $query) {
            return 100;
        }

        // Starts with query
        if (str_starts_with($text, $query)) {
            return 90;
        }

        // Word boundary match
        if (preg_match('/\b' . preg_quote($query, '/') . '/i', $text)) {
            return 70;
        }

        // Contains query
        if (str_contains($text, $query)) {
            return 50;
        }

        // Similar text
        similar_text($query, $text, $percent);
        return $percent / 2;
    }

    /**
     * Get popular searches (for trending/recommended)
     */
    public function getPopularSearches(int $limit = 10): array
    {
        $cacheKey = 'popular_searches';
        
        return Cache::remember($cacheKey, 3600, function() use ($limit) {
            // Get most popular categories
            $popularCategories = Category::withCount(['products' => function($q) {
                $q->where('is_archived', false);
            }])
            ->where('is_active', true)
            ->orderBy('products_count', 'desc')
            ->limit($limit)
            ->get()
            ->map(function($cat) {
                return [
                    'text' => $cat->title,
                    'type' => 'category',
                    'count' => $cat->products_count,
                ];
            })
            ->toArray();

            return $popularCategories;
        });
    }

    /**
     * Get trending keywords based on product names
     */
    public function getTrendingKeywords(int $limit = 20): array
    {
        $cacheKey = 'trending_keywords';
        
        return Cache::remember($cacheKey, 7200, function() use ($limit) {
            // Extract common words from recent products
            $products = Product::where('is_archived', false)
                ->orderBy('created_at', 'desc')
                ->limit(500)
                ->pluck('name');

            $keywords = [];
            foreach ($products as $name) {
                $words = preg_split('/\s+/', mb_strtolower($name, 'UTF-8'));
                foreach ($words as $word) {
                    // Filter out very short words and common words
                    if (strlen($word) >= 3 && !$this->isCommonWord($word)) {
                        if (!isset($keywords[$word])) {
                            $keywords[$word] = 0;
                        }
                        $keywords[$word]++;
                    }
                }
            }

            // Sort by frequency
            arsort($keywords);

            // Return top keywords
            return array_slice(array_keys($keywords), 0, $limit);
        });
    }

    /**
     * Check if word is too common to be useful
     */
    protected function isCommonWord(string $word): bool
    {
        $commonWords = [
            'the', 'and', 'for', 'with', 'new', 'from', 'shoes', 'shoe',
            'men', 'women', 'kids', 'size', 'color', 'brand', 'style',
        ];

        return in_array($word, $commonWords);
    }

    /**
     * Clear suggestion caches
     */
    public function clearCache(): void
    {
        Cache::forget('popular_searches');
        Cache::forget('trending_keywords');
        
        // Clear pattern-based caches
        $patterns = ['suggestions_categories_*', 'suggestions_brands_*'];
        foreach ($patterns as $pattern) {
            // Note: This is simplified. In production, use proper cache tagging
            Cache::flush();
        }
    }
}
