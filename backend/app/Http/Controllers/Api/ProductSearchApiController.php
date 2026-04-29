<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductSearchRequest;
use App\Http\Requests\AdvancedSearchRequest;
use App\Http\Resources\ProductSearchResource;
use App\Http\Resources\SearchSuggestionResource;
use App\Services\AdvancedProductSearchService;
use App\Services\SearchSuggestionEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Product Search API Controller (v2)
 * 
 * Provides advanced product search functionality without authentication.
 * All endpoints are public and optimized for e-commerce frontend integration.
 */
class ProductSearchApiController extends Controller
{
    protected AdvancedProductSearchService $searchService;
    protected SearchSuggestionEngine $suggestionEngine;

    public function __construct(
        AdvancedProductSearchService $searchService,
        SearchSuggestionEngine $suggestionEngine
    ) {
        $this->searchService = $searchService;
        $this->suggestionEngine = $suggestionEngine;
    }

    /**
     * Main product search endpoint
     * 
     * GET /api/v2/search/products
     * 
     * @param ProductSearchRequest $request
     * @return JsonResponse
     */
    public function search(ProductSearchRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            
            $result = $this->searchService->search($params);

            return response()->json([
                'success' => true,
                'query' => $params['q'] ?? null,
                'total' => $result['total'],
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'last_page' => $result['last_page'],
                'data' => ProductSearchResource::collection($result['products']),
                'filters_applied' => [
                    'query' => $params['q'] ?? null,
                    'category_id' => $params['category_id'] ?? null,
                    'category_slug' => $params['category_slug'] ?? null,
                    'vendor_id' => $params['vendor_id'] ?? null,
                    'color' => $params['color'] ?? null,
                    'size' => $params['size'] ?? null,
                    'brand' => $params['brand'] ?? null,
                    'sort_by' => $params['sort_by'] ?? 'relevance',
                ],
                'search_time_ms' => $result['search_time_ms'],
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Get search suggestions for autocomplete
     * 
     * GET /api/v2/search/suggestions
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function suggestions(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'q' => 'required|string|min:1|max:200',
                'limit' => 'nullable|integer|min:1|max:15',
            ]);

            $query = $validated['q'];
            $limit = $validated['limit'] ?? 8;

            $startTime = microtime(true);
            
            $suggestions = $this->suggestionEngine->getSuggestions($query, $limit);

            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            return response()->json([
                'success' => true,
                'query' => $query,
                'suggestions' => SearchSuggestionResource::collection(collect($suggestions)),
                'response_time_ms' => $responseTime,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid request parameters.',
                    'details' => $e->errors(),
                ],
            ], 422);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Get available filter options
     * 
     * GET /api/v2/search/filters
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function filters(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'category_id' => 'nullable|integer|exists:categories,id',
                'q' => 'nullable|string|max:200',
            ]);

            $startTime = microtime(true);
            
            $filters = $this->searchService->getAvailableFilters($validated);

            $endTime = microtime(true);
            $responseTime = round(($endTime - $startTime) * 1000, 2);

            return response()->json([
                'success' => true,
                'filters' => $filters,
                'response_time_ms' => $responseTime,
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Advanced search with boolean logic
     * 
     * POST /api/v2/search/products/advanced
     * 
     * @param AdvancedSearchRequest $request
     * @return JsonResponse
     */
    public function advancedSearch(AdvancedSearchRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            
            $filters = $validated['filters'] ?? [];
            
            // Add query to filters if provided
            if (!empty($validated['query'])) {
                $filters['query'] = $validated['query'];
            }

            $sorting = $validated['sort'] ?? [];
            $perPage = $validated['per_page'] ?? 30;
            $page = $validated['page'] ?? 1;

            $result = $this->searchService->advancedSearch($filters, $sorting, $perPage, $page);

            return response()->json([
                'success' => true,
                'query' => $validated['query'] ?? null,
                'total' => $result['total'],
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'last_page' => $result['last_page'],
                'data' => ProductSearchResource::collection($result['products']),
                'filters_applied' => $filters,
                'sorting_applied' => $sorting,
                'search_time_ms' => $result['search_time_ms'],
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Get popular/trending searches
     * 
     * GET /api/v2/search/popular
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function popular(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:20',
            ]);

            $limit = $validated['limit'] ?? 10;

            $popular = $this->suggestionEngine->getPopularSearches($limit);

            return response()->json([
                'success' => true,
                'data' => SearchSuggestionResource::collection(collect($popular)),
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Get trending keywords
     * 
     * GET /api/v2/search/trending
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function trending(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:50',
            ]);

            $limit = $validated['limit'] ?? 20;

            $keywords = $this->suggestionEngine->getTrendingKeywords($limit);

            return response()->json([
                'success' => true,
                'keywords' => $keywords,
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Quick search (simplified endpoint for basic searches)
     * 
     * GET /api/v2/search/quick
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function quickSearch(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'q' => 'required|string|min:2|max:200',
                'limit' => 'nullable|integer|min:1|max:20',
            ]);

            $params = [
                'q' => $validated['q'],
                'per_page' => $validated['limit'] ?? 10,
                'page' => 1,
                'sort_by' => 'relevance',
            ];

            $result = $this->searchService->search($params);

            return response()->json([
                'success' => true,
                'query' => $validated['q'],
                'data' => ProductSearchResource::collection($result['products']),
                'total' => $result['total'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid request parameters.',
                    'details' => $e->errors(),
                ],
            ], 422);
        } catch (\Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Handle errors and return consistent error response
     */
    protected function handleError(\Exception $e): JsonResponse
    {
        // Log the error
        \Log::error('Search API Error: ' . $e->getMessage(), [
            'exception' => $e,
            'trace' => $e->getTraceAsString(),
        ]);

        // Return user-friendly error
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'SERVER_ERROR',
                'message' => 'An error occurred while processing your search. Please try again.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ],
        ], 500);
    }
}
