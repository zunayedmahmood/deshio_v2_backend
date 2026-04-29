<?php

namespace App\Http\Controllers;

use App\Models\ProductBatch;
use App\Models\ReservedProduct;
use App\Models\Product;
use App\Models\Store;
use App\Models\MasterInventory;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * Get global inventory overview across all stores
     */
    public function getGlobalInventory(Request $request)
    {
        try {
            $query = ProductBatch::whereHas('product', function($q) {
                    $q->where('is_archived', false);
                })
                ->with(['product', 'store'])
                ->where('quantity', '>', 0);

            // Filter by product
            if ($request->has('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            // Filter by category
            if ($request->has('category_id')) {
                $categoryId = $request->category_id;
                $category = \App\Models\Category::find($categoryId);
                if ($category) {
                    $categoryIds = $category->descendants()->pluck('id')->push($category->id)->toArray();
                    $query->whereHas('product', function($q) use ($categoryIds) {
                        $q->whereIn('category_id', $categoryIds);
                    });
                }
            }

            // Filter by store
            if ($request->has('store_id')) {
                $query->where('store_id', $request->store_id);
            }

            // Filter by low stock
            if ($request->has('low_stock') && $request->low_stock == true) {
                $query->whereColumn('quantity', '<=', 'reorder_level');
            }

            // Group by product and aggregate across stores
            $inventory = $query->get()
                ->groupBy('product_id')
                ->map(function ($batches, $productId) {
                    $product = $batches->first()->product;
                    $totalQuantity = $batches->sum('quantity');
                    
                    // Get store-wise breakdown
                    $storeBreakdown = $batches->groupBy('store_id')->map(function ($storeBatches) {
                        $store = $storeBatches->first()->store;
                        return [
                            'store_id' => $store->id,
                            'store_name' => $store->name,
                            'store_code' => $store->store_code,
                            'store_address' => $store->address,
                            'quantity' => $storeBatches->sum('quantity'),
                            'batches_count' => $storeBatches->count(),
                        ];
                    })->values();

                    $reservedRecord = \App\Models\ReservedProduct::where('product_id', $productId)->first();
                    $availableQuantity = $reservedRecord ? max(0, $reservedRecord->available_inventory) : $totalQuantity;
                    $reservedQuantity = $reservedRecord ? $reservedRecord->reserved_inventory : 0;

                    return [
                        'product_id' => $product->id,
                        'category_id' => $product->category_id,
                        'product_name' => $product->name,
                        'base_name' => $product->base_name,
                        'variation_suffix' => $product->variation_suffix ?: trim(str_replace($product->base_name, '', $product->name)),
                        'sku' => $product->sku,
                        'total_quantity' => $totalQuantity,
                        'available_quantity' => $availableQuantity,
                        'reserved_quantity' => $reservedQuantity,
                        'stores_count' => $storeBreakdown->count(),
                        'stores' => $storeBreakdown,
                        'is_low_stock' => $batches->contains(function ($batch) {
                            return $batch->quantity <= $batch->reorder_level;
                        }),
                    ];
                })->values();

            return response()->json([
                'success' => true,
                'data' => $inventory,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch global inventory: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search product availability across all stores
     */
    public function searchProductAcrossStores(Request $request)
    {
        $request->validate([
            'search' => 'required|string|min:2',
        ]);

        try {
            $search = $request->search;

            // Search products by name or SKU
            $products = Product::where('is_archived', false);
            $this->whereAnyLike($products, ['name', 'sku'], $search);
            $products = $products->with(['productBatches' => function ($query) {
                    $query->where('quantity', '>', 0)->with('store');
                }])
                ->get()
                ->map(function ($product) {
                    $batches = $product->productBatches;
                    $totalQuantity = $batches->sum('quantity');

                    $storeAvailability = $batches->groupBy('store_id')->map(function ($storeBatches) {
                        $store = $storeBatches->first()->store;
                        return [
                            'store_id' => $store->id,
                            'store_name' => $store->name,
                            'store_code' => $store->store_code,
                            'quantity' => $storeBatches->sum('quantity'),
                            'is_warehouse' => $store->is_warehouse,
                            'is_online' => $store->is_online,
                        ];
                    })->values();

                    $reservedRecord = \App\Models\ReservedProduct::where('product_id', $product->id)->first();
                    $availableQuantity = $reservedRecord ? max(0, $reservedRecord->available_inventory) : $totalQuantity;
                    $reservedQuantity = $reservedRecord ? $reservedRecord->reserved_inventory : 0;

                    return [
                        'product_id' => $product->id,
                        'category_id' => $product->category_id,
                        'product_name' => $product->name,
                        'base_name' => $product->base_name,
                        'variation_suffix' => $product->variation_suffix ?: trim(str_replace($product->base_name, '', $product->name)),
                        'sku' => $product->sku,
                        'total_quantity' => $totalQuantity,
                        'available_quantity' => $availableQuantity,
                        'reserved_quantity' => $reservedQuantity,
                        'available_in_stores' => $storeAvailability->count(),
                        'stores' => $storeAvailability,
                    ];
                })
                ->filter(function ($product) {
                    return $product['total_quantity'] > 0;
                })
                ->values();

            return response()->json([
                'success' => true,
                'data' => $products,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search products: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get low stock alerts across all stores
     */
    public function getLowStockAlerts(Request $request)
    {
        try {
            $lowStockBatches = ProductBatch::with(['product', 'store'])
                ->whereColumn('quantity', '<=', 'reorder_level')
                ->where('quantity', '>', 0)
                ->get();

            $alerts = $lowStockBatches->map(function ($batch) {
                return [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'product_id' => $batch->product_id,
                    'product_name' => $batch->product->name,
                    'sku' => $batch->product->sku,
                    'store_id' => $batch->store_id,
                    'store_name' => $batch->store->name,
                    'current_quantity' => $batch->quantity,
                    'reorder_level' => $batch->reorder_level,
                    'shortage' => $batch->reorder_level - $batch->quantity,
                    'urgency' => $batch->quantity == 0 ? 'critical' : ($batch->quantity <= ($batch->reorder_level * 0.5) ? 'high' : 'medium'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'total_alerts' => $alerts->count(),
                    'critical' => $alerts->where('urgency', 'critical')->count(),
                    'high' => $alerts->where('urgency', 'high')->count(),
                    'medium' => $alerts->where('urgency', 'medium')->count(),
                    'alerts' => $alerts,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch low stock alerts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get inventory value report
     */
    public function getInventoryValue(Request $request)
    {
        try {
            $batches = ProductBatch::with(['product', 'store'])
                ->where('quantity', '>', 0)
                ->get();

            $totalValue = $batches->sum(function ($batch) {
                return $batch->quantity * $batch->cost_price;
            });

            // Group by store
            $storeValues = $batches->groupBy('store_id')->map(function ($storeBatches) {
                $store = $storeBatches->first()->store;
                $storeValue = $storeBatches->sum(function ($batch) {
                    return $batch->quantity * $batch->cost_price;
                });

                return [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'store_code' => $store->store_code,
                    'total_value' => $storeValue,
                    'products_count' => $storeBatches->unique('product_id')->count(),
                    'batches_count' => $storeBatches->count(),
                ];
            })->values();

            // Group by product
            $productValues = $batches->groupBy('product_id')->map(function ($productBatches) {
                $product = $productBatches->first()->product;
                $totalQuantity = $productBatches->sum('quantity');
                $totalValue = $productBatches->sum(function ($batch) {
                    return $batch->quantity * $batch->cost_price;
                });

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'total_quantity' => $totalQuantity,
                    'available_quantity' => \App\Models\ReservedProduct::where('product_id', $product->id)->value('available_inventory') ?? $totalQuantity,
                    'total_value' => $totalValue,
                    'average_unit_cost' => $totalQuantity > 0 ? $totalValue / $totalQuantity : 0,
                ];
            })->sortByDesc('total_value')->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_inventory_value' => $totalValue,
                    'total_products' => $batches->unique('product_id')->count(),
                    'total_batches' => $batches->count(),
                    'by_store' => $storeValues,
                    'top_products' => $productValues->take(20),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate inventory value: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get inventory statistics and dashboard data
     */
    public function getStatistics(Request $request)
    {
        try {
            $totalProducts = Product::count();
            $totalBatches = ProductBatch::count();
            $activeBatches = ProductBatch::where('quantity', '>', 0)->count();
            
            $totalInventoryUnits = ProductBatch::sum('quantity');
            $totalInventoryValue = ProductBatch::where('quantity', '>', 0)
                ->get()
                ->sum(function ($batch) {
                    return $batch->quantity * $batch->cost_price;
                });

            $lowStockCount = ProductBatch::whereColumn('quantity', '<=', 'reorder_level')
                ->where('quantity', '>', 0)
                ->count();

            $outOfStockCount = ProductBatch::where('quantity', 0)->count();

            // Expiring soon (within 30 days)
            $expiringSoon = ProductBatch::where('quantity', '>', 0)
                ->where('expiry_date', '<=', now()->addDays(30))
                ->where('expiry_date', '>=', now())
                ->count();

            // Store-wise summary
            $storesSummary = Store::active()->get()->map(function ($store) {
                $batches = $store->productBatches()->where('quantity', '>', 0)->get();
                $value = $batches->sum(function ($batch) {
                    return $batch->quantity * $batch->cost_price;
                });

                return [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'products_count' => $batches->unique('product_id')->count(),
                    'total_quantity' => $batches->sum('quantity'),
                    'total_value' => $value,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'overview' => [
                        'total_products' => $totalProducts,
                        'total_batches' => $totalBatches,
                        'active_batches' => $activeBatches,
                        'total_inventory_units' => $totalInventoryUnits,
                        'total_inventory_value' => $totalInventoryValue,
                    ],
                    'alerts' => [
                        'low_stock' => $lowStockCount,
                        'out_of_stock' => $outOfStockCount,
                        'expiring_soon' => $expiringSoon,
                    ],
                    'stores' => $storesSummary,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get stock aging analysis
     */
    public function getStockAging(Request $request)
    {
        try {
            $batches = ProductBatch::with(['product', 'store'])
                ->where('quantity', '>', 0)
                ->get();

            $aging = $batches->map(function ($batch) {
                $daysInStock = now()->diffInDays($batch->created_at);
                
                $ageCategory = 'fresh';
                if ($daysInStock > 180) {
                    $ageCategory = 'aged';
                } elseif ($daysInStock > 90) {
                    $ageCategory = 'medium';
                }

                return [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'product_name' => $batch->product->name,
                    'store_name' => $batch->store->name,
                    'quantity' => $batch->quantity,
                    'days_in_stock' => $daysInStock,
                    'age_category' => $ageCategory,
                    'value' => $batch->quantity * $batch->cost_price,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'fresh' => $aging->where('age_category', 'fresh')->values(),
                    'medium' => $aging->where('age_category', 'medium')->values(),
                    'aged' => $aging->where('age_category', 'aged')->values(),
                    'summary' => [
                        'fresh_count' => $aging->where('age_category', 'fresh')->count(),
                        'medium_count' => $aging->where('age_category', 'medium')->count(),
                        'aged_count' => $aging->where('age_category', 'aged')->count(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to analyze stock aging: ' . $e->getMessage(),
            ], 500);
        }
    }
}
