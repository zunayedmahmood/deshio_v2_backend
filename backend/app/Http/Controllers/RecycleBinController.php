<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Vendor;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Field;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RecycleBinController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * Get all soft deleted items across different models
     * 
     * GET /api/recycle-bin
     * Query params: type, per_page, search, sort_by, sort_direction
     */
    public function index(Request $request)
    {
        try {
            $type = $request->input('type', 'all');
            $perPage = $request->input('per_page', 20);
            $search = $request->input('search');
            $sortBy = $request->input('sort_by', 'deleted_at');
            $sortDirection = $request->input('sort_direction', 'desc');

            $items = [];

            if ($type === 'all' || $type === 'products') {
                $products = Product::onlyTrashed()
                    ->with(['category', 'vendor'])
                    ->when($search, function ($query) use ($search) {
                        $this->whereAnyLike($query, ['name', 'sku'], $search);
                    })
                    ->orderBy($sortBy, $sortDirection)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'type' => 'product',
                            'name' => $item->name,
                            'identifier' => $item->sku,
                            'details' => [
                                'category' => $item->category->name ?? 'N/A',
                                'vendor' => $item->vendor->name ?? 'N/A',
                            ],
                            'deleted_at' => $item->deleted_at,
                            'days_until_permanent_delete' => $this->getDaysUntilPermanentDelete($item->deleted_at),
                            'can_restore' => true,
                        ];
                    });

                $items = array_merge($items, $products->toArray());
            }

            if ($type === 'all' || $type === 'categories') {
                $categories = Category::onlyTrashed()
                    ->withCount('products')
                    ->when($search, function ($query) use ($search) {
                        $this->whereLike($query, 'name', $search);
                    })
                    ->orderBy($sortBy, $sortDirection)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'type' => 'category',
                            'name' => $item->name,
                            'identifier' => $item->slug ?? 'N/A',
                            'details' => [
                                'products_count' => $item->products_count,
                            ],
                            'deleted_at' => $item->deleted_at,
                            'days_until_permanent_delete' => $this->getDaysUntilPermanentDelete($item->deleted_at),
                            'can_restore' => true,
                        ];
                    });

                $items = array_merge($items, $categories->toArray());
            }

            if ($type === 'all' || $type === 'employees') {
                $employees = Employee::onlyTrashed()
                    ->with('role')
                    ->when($search, function ($query) use ($search) {
                        $this->whereAnyLike($query, ['name', 'email'], $search);
                    })
                    ->orderBy($sortBy, $sortDirection)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'type' => 'employee',
                            'name' => $item->name,
                            'identifier' => $item->email,
                            'details' => [
                                'role' => $item->role->name ?? 'N/A',
                                'phone' => $item->phone ?? 'N/A',
                            ],
                            'deleted_at' => $item->deleted_at,
                            'days_until_permanent_delete' => $this->getDaysUntilPermanentDelete($item->deleted_at),
                            'can_restore' => true,
                        ];
                    });

                $items = array_merge($items, $employees->toArray());
            }

            if ($type === 'all' || $type === 'vendors') {
                $vendors = Vendor::onlyTrashed()
                    ->when($search, function ($query) use ($search) {
                        $likeOperator = $this->getLikeOperator();
                        $pattern = $this->buildLikePattern($search);
                        $query->where('name', $likeOperator, $pattern)
                              ->orWhere('email', $likeOperator, $pattern);
                    })
                    ->orderBy($sortBy, $sortDirection)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'type' => 'vendor',
                            'name' => $item->name,
                            'identifier' => $item->email ?? 'N/A',
                            'details' => [
                                'phone' => $item->phone ?? 'N/A',
                                'company' => $item->company_name ?? 'N/A',
                            ],
                            'deleted_at' => $item->deleted_at,
                            'days_until_permanent_delete' => $this->getDaysUntilPermanentDelete($item->deleted_at),
                            'can_restore' => true,
                        ];
                    });

                $items = array_merge($items, $vendors->toArray());
            }

            if ($type === 'all' || $type === 'stores') {
                $stores = Store::onlyTrashed()
                    ->when($search, function ($query) use ($search) {
                        $likeOperator = $this->getLikeOperator();
                        $pattern = $this->buildLikePattern($search);
                        $query->where('name', $likeOperator, $pattern);
                    })
                    ->orderBy($sortBy, $sortDirection)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'type' => 'store',
                            'name' => $item->name,
                            'identifier' => $item->store_code ?? 'N/A',
                            'details' => [
                                'type' => $item->is_warehouse ? 'warehouse' : ($item->is_online ? 'online' : 'retail'),
                                'address' => $item->address ?? 'N/A',
                            ],
                            'deleted_at' => $item->deleted_at,
                            'days_until_permanent_delete' => $this->getDaysUntilPermanentDelete($item->deleted_at),
                            'can_restore' => true,
                        ];
                    });

                $items = array_merge($items, $stores->toArray());
            }

            // Sort combined items
            usort($items, function ($a, $b) use ($sortBy, $sortDirection) {
                if ($sortBy === 'deleted_at') {
                    $comparison = strtotime($a['deleted_at']) - strtotime($b['deleted_at']);
                } else {
                    $comparison = strcmp($a['name'], $b['name']);
                }
                
                return $sortDirection === 'desc' ? -$comparison : $comparison;
            });

            // Manual pagination
            $page = $request->input('page', 1);
            $offset = ($page - 1) * $perPage;
            $paginatedItems = array_slice($items, $offset, $perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'items' => $paginatedItems,
                    'pagination' => [
                        'total' => count($items),
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => ceil(count($items) / $perPage),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recycle bin items: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get statistics for recycle bin
     * 
     * GET /api/recycle-bin/statistics
     */
    public function getStatistics()
    {
        try {
            $stats = [
                'total' => 0,
                'by_type' => [
                    'products' => Product::onlyTrashed()->count(),
                    'categories' => Category::onlyTrashed()->count(),
                    'employees' => Employee::onlyTrashed()->count(),
                    'vendors' => Vendor::onlyTrashed()->count(),
                    'stores' => Store::onlyTrashed()->count(),
                    'customers' => Customer::onlyTrashed()->count(),
                    'orders' => Order::onlyTrashed()->count(),
                ],
                'expiring_soon' => [], // Items to be permanently deleted in next 2 days
                'oldest_item' => null,
            ];

            $stats['total'] = array_sum($stats['by_type']);

            // Get items expiring soon (deleted more than 5 days ago)
            $expiryThreshold = Carbon::now()->subDays(5);
            
            $expiringSoon = [];
            
            // Products
            Product::onlyTrashed()
                ->where('deleted_at', '<=', $expiryThreshold)
                ->get()
                ->each(function ($item) use (&$expiringSoon) {
                    $expiringSoon[] = [
                        'type' => 'product',
                        'name' => $item->name,
                        'deleted_at' => $item->deleted_at,
                        'days_remaining' => $this->getDaysUntilPermanentDelete($item->deleted_at),
                    ];
                });

            // Categories
            Category::onlyTrashed()
                ->where('deleted_at', '<=', $expiryThreshold)
                ->get()
                ->each(function ($item) use (&$expiringSoon) {
                    $expiringSoon[] = [
                        'type' => 'category',
                        'name' => $item->name,
                        'deleted_at' => $item->deleted_at,
                        'days_remaining' => $this->getDaysUntilPermanentDelete($item->deleted_at),
                    ];
                });

            $stats['expiring_soon'] = $expiringSoon;

            // Get oldest item
            $oldestProduct = Product::onlyTrashed()->oldest('deleted_at')->first();
            if ($oldestProduct) {
                $stats['oldest_item'] = [
                    'type' => 'product',
                    'name' => $oldestProduct->name,
                    'deleted_at' => $oldestProduct->deleted_at,
                    'days_in_bin' => Carbon::parse($oldestProduct->deleted_at)->diffInDays(now()),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a deleted item
     * 
     * POST /api/recycle-bin/restore
     * Body: { type: 'product', id: 1 }
     */
    public function restore(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:product,category,employee,vendor,store,customer,order',
            'id' => 'required|integer',
        ]);

        DB::beginTransaction();
        try {
            $model = $this->getModelByType($request->type);
            $item = $model::onlyTrashed()->findOrFail($request->id);

            $item->restore();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => ucfirst($request->type) . ' restored successfully',
                'data' => $item,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore item: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore multiple items
     * 
     * POST /api/recycle-bin/restore-multiple
     * Body: { items: [{type: 'product', id: 1}, {type: 'category', id: 2}] }
     */
    public function restoreMultiple(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.type' => 'required|string|in:product,category,employee,vendor,store,customer,order',
            'items.*.id' => 'required|integer',
        ]);

        DB::beginTransaction();
        try {
            $restored = [];
            $failed = [];

            foreach ($request->items as $itemData) {
                try {
                    $model = $this->getModelByType($itemData['type']);
                    $item = $model::onlyTrashed()->find($itemData['id']);

                    if ($item) {
                        $item->restore();
                        $restored[] = [
                            'type' => $itemData['type'],
                            'id' => $itemData['id'],
                            'name' => $item->name ?? 'N/A',
                        ];
                    } else {
                        $failed[] = [
                            'type' => $itemData['type'],
                            'id' => $itemData['id'],
                            'reason' => 'Item not found in recycle bin',
                        ];
                    }
                } catch (\Exception $e) {
                    $failed[] = [
                        'type' => $itemData['type'],
                        'id' => $itemData['id'],
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($restored) . ' item(s) restored successfully',
                'data' => [
                    'restored' => $restored,
                    'failed' => $failed,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore items: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Permanently delete an item
     * 
     * DELETE /api/recycle-bin/permanent-delete
     * Body: { type: 'product', id: 1 }
     */
    public function permanentDelete(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:product,category,employee,vendor,store,customer,order',
            'id' => 'required|integer',
        ]);

        DB::beginTransaction();
        try {
            $model = $this->getModelByType($request->type);
            $item = $model::onlyTrashed()->findOrFail($request->id);

            $itemName = $item->name ?? $item->email ?? 'Item';
            $item->forceDelete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => ucfirst($request->type) . " '{$itemName}' permanently deleted",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete item: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Empty recycle bin (delete all items older than 7 days)
     * 
     * DELETE /api/recycle-bin/empty
     */
    public function emptyRecycleBin()
    {
        DB::beginTransaction();
        try {
            $deletionDate = Carbon::now()->subDays(7);
            $deletedCount = 0;

            // Products
            $deletedCount += Product::onlyTrashed()
                ->where('deleted_at', '<=', $deletionDate)
                ->forceDelete();

            // Categories
            $deletedCount += Category::onlyTrashed()
                ->where('deleted_at', '<=', $deletionDate)
                ->forceDelete();

            // Employees
            $deletedCount += Employee::onlyTrashed()
                ->where('deleted_at', '<=', $deletionDate)
                ->forceDelete();

            // Vendors
            $deletedCount += Vendor::onlyTrashed()
                ->where('deleted_at', '<=', $deletionDate)
                ->forceDelete();

            // Stores
            $deletedCount += Store::onlyTrashed()
                ->where('deleted_at', '<=', $deletionDate)
                ->forceDelete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Recycle bin emptied. {$deletedCount} item(s) permanently deleted",
                'data' => [
                    'deleted_count' => $deletedCount,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to empty recycle bin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Auto-cleanup: Permanently delete items older than 7 days
     * This should be called by a scheduled job
     * 
     * POST /api/recycle-bin/auto-cleanup
     */
    public function autoCleanup()
    {
        try {
            $deletionDate = Carbon::now()->subDays(7);
            $deletedItems = [];

            // Products
            $products = Product::onlyTrashed()
                ->where('deleted_at', '<=', $deletionDate)
                ->get();
            
            foreach ($products as $product) {
                $deletedItems[] = [
                    'type' => 'product',
                    'name' => $product->name,
                    'deleted_at' => $product->deleted_at,
                ];
                $product->forceDelete();
            }

            // Categories
            $categories = Category::onlyTrashed()
                ->where('deleted_at', '<=', $deletionDate)
                ->get();
            
            foreach ($categories as $category) {
                $deletedItems[] = [
                    'type' => 'category',
                    'name' => $category->name,
                    'deleted_at' => $category->deleted_at,
                ];
                $category->forceDelete();
            }

            // Similar for other models...

            return response()->json([
                'success' => true,
                'message' => count($deletedItems) . ' item(s) auto-cleaned from recycle bin',
                'data' => [
                    'deleted_items' => $deletedItems,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Auto-cleanup failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get deleted item details
     * 
     * GET /api/recycle-bin/{type}/{id}
     */
    public function show($type, $id)
    {
        try {
            $model = $this->getModelByType($type);
            $item = $model::onlyTrashed()->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'item' => $item,
                    'days_until_permanent_delete' => $this->getDaysUntilPermanentDelete($item->deleted_at),
                    'can_restore' => true,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found',
            ], 404);
        }
    }

    /**
     * Helper: Get model class by type
     */
    private function getModelByType($type)
    {
        return match($type) {
            'product' => Product::class,
            'category' => Category::class,
            'employee' => Employee::class,
            'vendor' => Vendor::class,
            'store' => Store::class,
            'customer' => Customer::class,
            'order' => Order::class,
            default => throw new \Exception('Invalid type'),
        };
    }

    /**
     * Helper: Calculate days until permanent deletion
     */
    private function getDaysUntilPermanentDelete($deletedAt)
    {
        $deletionDate = Carbon::parse($deletedAt)->addDays(7);
        $daysRemaining = Carbon::now()->diffInDays($deletionDate, false);
        
        return max(0, $daysRemaining);
    }
}
