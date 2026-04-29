<?php

namespace App\Http\Controllers;

use App\Models\ProductBarcode;
use App\Models\ProductMovement;
use App\Models\ProductBatch;
use App\Models\Store;
use App\Models\Product;
use App\Models\Employee;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BarcodeLocationController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * Get current location and status of a specific barcode
     * 
     * GET /api/barcode-tracking/{barcode}/location
     */
    public function getBarcodeLocation($barcode)
    {
        $barcodeRecord = ProductBarcode::where('barcode', $barcode)
            ->with(['product', 'currentStore', 'batch'])
            ->first();

        if (!$barcodeRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $barcodeRecord->getCurrentLocationDetails()
        ]);
    }

    /**
     * Get complete movement history of a specific barcode
     * 
     * GET /api/barcode-tracking/{barcode}/history
     */
    public function getBarcodeHistory($barcode)
    {
        $barcodeRecord = ProductBarcode::where('barcode', $barcode)->first();

        if (!$barcodeRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode not found'
            ], 404);
        }

        $history = $barcodeRecord->getDetailedLocationHistory();

        return response()->json([
            'success' => true,
            'data' => [
                'barcode' => $barcode,
                'product' => [
                    'id' => $barcodeRecord->product_id,
                    'name' => $barcodeRecord->product->name ?? 'Unknown',
                    'sku' => $barcodeRecord->product->sku ?? null,
                ],
                'current_location' => $barcodeRecord->getCurrentLocationDetails(),
                'total_movements' => $history->count(),
                'history' => $history
            ]
        ]);
    }

    /**
     * Get all barcodes at a specific store with filtering
     * 
     * GET /api/barcode-tracking/store/{storeId}
     * Query params: status, product_id, available_only, batch_id, search
     */
    public function getBarcodesAtStore(Request $request, $storeId)
    {
        $store = Store::find($storeId);

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found'
            ], 404);
        }

        $query = ProductBarcode::atStore($storeId)
            ->with(['product', 'batch', 'currentStore']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('current_status', $request->status);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by batch
        if ($request->has('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        // Only show available for sale
        if ($request->boolean('available_only')) {
            $query->availableForSale();
        }

        // Search by barcode
        if ($request->has('search')) {
            $this->whereLike($query, 'barcode', $request->search);
        }

        // Pagination
        $perPage = $request->input('per_page', 50);
        $barcodes = $query->paginate($perPage);

        // Summary statistics
        $summary = ProductBarcode::atStore($storeId)
            ->select('current_status', DB::raw('count(*) as count'))
            ->groupBy('current_status')
            ->get()
            ->pluck('count', 'current_status');

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'type' => $store->store_type,
                ],
                'summary' => [
                    'total_barcodes' => ProductBarcode::atStore($storeId)->count(),
                    'in_warehouse' => $summary['in_warehouse'] ?? 0,
                    'in_shop' => $summary['in_shop'] ?? 0,
                    'on_display' => $summary['on_display'] ?? 0,
                    'in_transit' => $summary['in_transit'] ?? 0,
                    'in_shipment' => $summary['in_shipment'] ?? 0,
                    'available_for_sale' => ProductBarcode::atStore($storeId)->availableForSale()->count(),
                ],
                'filters' => [
                    'status' => $request->status,
                    'product_id' => $request->product_id,
                    'batch_id' => $request->batch_id,
                    'available_only' => $request->boolean('available_only'),
                    'search' => $request->search,
                ],
                'barcodes' => $barcodes->items(),
                'pagination' => [
                    'current_page' => $barcodes->currentPage(),
                    'per_page' => $barcodes->perPage(),
                    'total' => $barcodes->total(),
                    'last_page' => $barcodes->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Advanced search for barcodes with multiple filters
     * 
     * POST /api/barcode-tracking/search
     * Body: { store_id, product_id, status, statuses[], available_only, etc. }
     */
    public function advancedSearch(Request $request)
    {
        $query = ProductBarcode::query()->with(['product', 'currentStore', 'batch']);

        // Filter by store
        if ($request->has('store_id')) {
            $query->atStore($request->store_id);
        }

        // Filter by multiple stores
        if ($request->has('store_ids')) {
            $query->whereIn('current_store_id', $request->store_ids);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by multiple products
        if ($request->has('product_ids')) {
            $query->whereIn('product_id', $request->product_ids);
        }

        // Filter by single status
        if ($request->has('status')) {
            $query->where('current_status', $request->status);
        }

        // Filter by multiple statuses
        if ($request->has('statuses')) {
            $query->whereIn('current_status', $request->statuses);
        }

        // Filter by batch
        if ($request->has('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        // Only active barcodes
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Only available for sale
        if ($request->boolean('available_only')) {
            $query->availableForSale();
        }

        // Only defective
        if ($request->boolean('defective_only')) {
            $query->defective();
        }

        // Search by barcode pattern
        if ($request->has('barcode_search')) {
            $this->whereLike($query, 'barcode', $request->barcode_search);
        }

        // Filter by location update date range
        if ($request->has('updated_from')) {
            $query->where('location_updated_at', '>=', $request->updated_from);
        }
        if ($request->has('updated_to')) {
            $query->where('location_updated_at', '<=', $request->updated_to);
        }

        // Filter by creation date range
        if ($request->has('created_from')) {
            $query->where('created_at', '>=', $request->created_from);
        }
        if ($request->has('created_to')) {
            $query->where('created_at', '<=', $request->created_to);
        }

        // Order by
        $orderBy = $request->input('order_by', 'location_updated_at');
        $orderDirection = $request->input('order_direction', 'desc');
        $query->orderBy($orderBy, $orderDirection);

        // Pagination
        $perPage = $request->input('per_page', 50);
        $barcodes = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'filters_applied' => $request->except(['page', 'per_page', 'order_by', 'order_direction']),
                'total_results' => $barcodes->total(),
                'barcodes' => array_map(function ($barcode) {
                    return [
                        'id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'product' => [
                            'id' => $barcode->product_id,
                            'name' => $barcode->product->name ?? 'Unknown',
                            'sku' => $barcode->product->sku ?? null,
                        ],
                        'current_store' => $barcode->currentStore ? [
                            'id' => $barcode->currentStore->id,
                            'name' => $barcode->currentStore->name,
                            'type' => $barcode->currentStore->is_warehouse ? 'warehouse' : ($barcode->currentStore->is_online ? 'online' : 'retail'),
                        ] : null,
                        'batch' => $barcode->batch ? [
                            'id' => $barcode->batch->id,
                            'batch_number' => $barcode->batch->batch_number,
                        ] : null,
                        'current_status' => $barcode->current_status,
                        'status_label' => $barcode->getStatusLabel(),
                        'is_active' => $barcode->is_active,
                        'is_defective' => $barcode->is_defective,
                        'is_available_for_sale' => $barcode->isAvailableForSale(),
                        'location_updated_at' => $barcode->location_updated_at,
                        'location_metadata' => $barcode->location_metadata,
                        'created_at' => $barcode->created_at,
                    ];
                }, $barcodes->items()),
                'pagination' => [
                    'current_page' => $barcodes->currentPage(),
                    'per_page' => $barcodes->perPage(),
                    'total' => $barcodes->total(),
                    'last_page' => $barcodes->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Get barcodes grouped by status
     * 
     * GET /api/barcode-tracking/grouped-by-status
     * Query params: store_id, product_id
     */
    public function getGroupedByStatus(Request $request)
    {
        $query = ProductBarcode::query();

        if ($request->has('store_id')) {
            $query->atStore($request->store_id);
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $grouped = $query->with(['product', 'currentStore', 'batch'])
            ->get()
            ->groupBy('current_status');

        $result = [];
        foreach ($grouped as $status => $barcodes) {
            $result[$status] = [
                'status' => $status,
                'status_label' => $barcodes->first()->getStatusLabel(),
                'count' => $barcodes->count(),
                'barcodes' => $barcodes->map(function ($barcode) {
                    return [
                        'id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'product_name' => $barcode->product->name ?? 'Unknown',
                        'store_name' => $barcode->currentStore->name ?? 'Unknown',
                        'location_metadata' => $barcode->location_metadata,
                    ];
                })
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => [
                    'store_id' => $request->store_id,
                    'product_id' => $request->product_id,
                ],
                'total_barcodes' => $query->count(),
                'grouped_by_status' => $result
            ]
        ]);
    }

    /**
     * Get barcodes grouped by store
     * 
     * GET /api/barcode-tracking/grouped-by-store
     * Query params: status, product_id
     */
    public function getGroupedByStore(Request $request)
    {
        $query = ProductBarcode::query();

        if ($request->has('status')) {
            $query->where('current_status', $request->status);
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $grouped = $query->with(['product', 'currentStore', 'batch'])
            ->get()
            ->groupBy('current_store_id');

        $result = [];
        foreach ($grouped as $storeId => $barcodes) {
            $store = $barcodes->first()->currentStore;
            $result[] = [
                'store' => $store ? [
                    'id' => $store->id,
                    'name' => $store->name,
                    'type' => $store->is_warehouse ? 'warehouse' : ($store->is_online ? 'online' : 'retail'),
                ] : ['id' => null, 'name' => 'Unknown', 'type' => null],
                'count' => $barcodes->count(),
                'status_breakdown' => $barcodes->groupBy('current_status')->map(function ($items, $status) {
                    return [
                        'status' => $status,
                        'count' => $items->count(),
                    ];
                })->values(),
                'barcodes' => $barcodes->map(function ($barcode) {
                    return [
                        'id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'product_name' => $barcode->product->name ?? 'Unknown',
                        'current_status' => $barcode->current_status,
                        'status_label' => $barcode->getStatusLabel(),
                    ];
                })
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => [
                    'status' => $request->status,
                    'product_id' => $request->product_id,
                ],
                'total_barcodes' => $query->count(),
                'total_stores' => count($result),
                'grouped_by_store' => $result
            ]
        ]);
    }

    /**
     * Get barcodes grouped by product
     * 
     * GET /api/barcode-tracking/grouped-by-product
     * Query params: store_id, status
     */
    public function getGroupedByProduct(Request $request)
    {
        $query = ProductBarcode::query();

        if ($request->has('store_id')) {
            $query->atStore($request->store_id);
        }

        if ($request->has('status')) {
            $query->where('current_status', $request->status);
        }

        $grouped = $query->with(['product', 'currentStore', 'batch'])
            ->get()
            ->groupBy('product_id');

        $result = [];
        foreach ($grouped as $productId => $barcodes) {
            $product = $barcodes->first()->product;
            $result[] = [
                'product' => $product ? [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ] : ['id' => null, 'name' => 'Unknown', 'sku' => null],
                'count' => $barcodes->count(),
                'available_for_sale' => $barcodes->filter(fn($b) => $b->isAvailableForSale())->count(),
                'status_breakdown' => $barcodes->groupBy('current_status')->map(function ($items, $status) {
                    return [
                        'status' => $status,
                        'count' => $items->count(),
                    ];
                })->values(),
                'store_breakdown' => $barcodes->groupBy('current_store_id')->map(function ($items) {
                    $store = $items->first()->currentStore;
                    return [
                        'store_name' => $store->name ?? 'Unknown',
                        'count' => $items->count(),
                    ];
                })->values(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => [
                    'store_id' => $request->store_id,
                    'status' => $request->status,
                ],
                'total_barcodes' => $query->count(),
                'total_products' => count($result),
                'grouped_by_product' => $result
            ]
        ]);
    }

    /**
     * Get movement history for a date range
     * 
     * GET /api/barcode-tracking/movements
     * Query params: barcode, store_id, product_id, from_date, to_date, movement_type
     */
    public function getMovements(Request $request)
    {
        $query = ProductMovement::query()
            ->with(['barcode.product', 'fromStore', 'toStore', 'batch', 'performedBy', 'dispatch']);

        // Filter by barcode
        if ($request->has('barcode')) {
            $barcodeRecord = ProductBarcode::where('barcode', $request->barcode)->first();
            if ($barcodeRecord) {
                $query->where('product_barcode_id', $barcodeRecord->id);
            }
        }

        // Filter by barcode ID
        if ($request->has('barcode_id')) {
            $query->where('product_barcode_id', $request->barcode_id);
        }

        // Filter by store (movements involving this store)
        if ($request->has('store_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('from_store_id', $request->store_id)
                  ->orWhere('to_store_id', $request->store_id);
            });
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->whereHas('barcode', function ($q) use ($request) {
                $q->where('product_id', $request->product_id);
            });
        }

        // Filter by movement type
        if ($request->has('movement_type')) {
            $query->where('movement_type', $request->movement_type);
        }

        // Filter by reference type
        if ($request->has('reference_type')) {
            $query->where('reference_type', $request->reference_type);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('movement_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('movement_date', '<=', $request->to_date);
        }

        // Order by movement date
        $query->orderBy('movement_date', 'desc');

        // Pagination
        $perPage = $request->input('per_page', 50);
        $movements = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => $request->except(['page', 'per_page']),
                'total_movements' => $movements->total(),
                'movements' => array_map(function ($movement) {
                    return [
                        'id' => $movement->id,
                        'movement_date' => $movement->movement_date,
                        'barcode' => $movement->barcode ? [
                            'id' => $movement->barcode->id,
                            'barcode' => $movement->barcode->barcode,
                            'product_name' => $movement->barcode->product->name ?? 'Unknown',
                        ] : null,
                        'from_store' => $movement->fromStore ? [
                            'id' => $movement->fromStore->id,
                            'name' => $movement->fromStore->name,
                        ] : null,
                        'to_store' => $movement->toStore ? [
                            'id' => $movement->toStore->id,
                            'name' => $movement->toStore->name,
                        ] : null,
                        'movement_type' => $movement->movement_type,
                        'status_before' => $movement->status_before,
                        'status_after' => $movement->status_after,
                        'reference_type' => $movement->reference_type,
                        'reference_id' => $movement->reference_id,
                        'quantity' => $movement->quantity,
                        'notes' => $movement->notes,
                        'performed_by' => $movement->performedBy ? [
                            'id' => $movement->performedBy->id,
                            'name' => $movement->performedBy->name,
                        ] : null,
                    ];
                }, $movements->items()),
                'pagination' => [
                    'current_page' => $movements->currentPage(),
                    'per_page' => $movements->perPage(),
                    'total' => $movements->total(),
                    'last_page' => $movements->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Get statistics summary
     * 
     * GET /api/barcode-tracking/statistics
     * Query params: store_id, product_id
     */
    public function getStatistics(Request $request)
    {
        $query = ProductBarcode::query();

        if ($request->has('store_id')) {
            $query->atStore($request->store_id);
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $total = $query->count();
        $active = (clone $query)->active()->count();
        $defective = (clone $query)->defective()->count();
        $availableForSale = (clone $query)->availableForSale()->count();

        // Status breakdown
        $statusBreakdown = (clone $query)
            ->select('current_status', DB::raw('count(*) as count'))
            ->groupBy('current_status')
            ->get()
            ->mapWithKeys(function ($item) {
                return [$item->current_status => $item->count];
            });

        // Store breakdown
        $storeBreakdown = (clone $query)
            ->with('currentStore:id,name')
            ->get()
            ->groupBy('current_store_id')
            ->map(function ($items, $storeId) {
                $store = $items->first()->currentStore;
                return [
                    'store_id' => $storeId,
                    'store_name' => $store->name ?? 'Unknown',
                    'count' => $items->count(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => [
                    'store_id' => $request->store_id,
                    'product_id' => $request->product_id,
                ],
                'summary' => [
                    'total_barcodes' => $total,
                    'active' => $active,
                    'inactive' => $total - $active,
                    'defective' => $defective,
                    'available_for_sale' => $availableForSale,
                ],
                'status_breakdown' => $statusBreakdown,
                'store_breakdown' => $storeBreakdown,
            ]
        ]);
    }

    /**
     * Get barcodes that haven't moved in X days (stagnant inventory)
     * 
     * GET /api/barcode-tracking/stagnant
     * Query params: days (default: 90), store_id, status
     */
    public function getStagnantBarcodes(Request $request)
    {
        $days = $request->input('days', 90);
        $cutoffDate = now()->subDays($days);

        $query = ProductBarcode::where('location_updated_at', '<', $cutoffDate)
            ->where('current_status', '!=', 'with_customer')
            ->where('current_status', '!=', 'disposed')
            ->with(['product', 'currentStore', 'batch']);

        if ($request->has('store_id')) {
            $query->atStore($request->store_id);
        }

        if ($request->has('status')) {
            $query->where('current_status', $request->status);
        }

        $barcodes = $query->get();

        return response()->json([
            'success' => true,
            'data' => [
                'cutoff_days' => $days,
                'cutoff_date' => $cutoffDate,
                'total_stagnant' => $barcodes->count(),
                'barcodes' => $barcodes->map(function ($barcode) use ($cutoffDate) {
                    $daysSinceUpdate = $barcode->location_updated_at 
                        ? $barcode->location_updated_at->diffInDays(now())
                        : null;

                    return [
                        'id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'product' => [
                            'id' => $barcode->product_id,
                            'name' => $barcode->product->name ?? 'Unknown',
                            'sku' => $barcode->product->sku ?? null,
                        ],
                        'current_store' => $barcode->currentStore ? [
                            'id' => $barcode->currentStore->id,
                            'name' => $barcode->currentStore->name,
                        ] : null,
                        'current_status' => $barcode->current_status,
                        'status_label' => $barcode->getStatusLabel(),
                        'location_updated_at' => $barcode->location_updated_at,
                        'days_since_last_movement' => $daysSinceUpdate,
                    ];
                })
            ]
        ]);
    }

    /**
     * Get barcodes in transit for too long
     * 
     * GET /api/barcode-tracking/overdue-transit
     * Query params: days (default: 7)
     */
    public function getOverdueTransit(Request $request)
    {
        $days = $request->input('days', 7);
        $cutoffDate = now()->subDays($days);

        $barcodes = ProductBarcode::inTransit()
            ->where('location_updated_at', '<', $cutoffDate)
            ->with(['product', 'currentStore', 'batch'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'cutoff_days' => $days,
                'cutoff_date' => $cutoffDate,
                'total_overdue' => $barcodes->count(),
                'barcodes' => $barcodes->map(function ($barcode) {
                    $daysSinceTransit = $barcode->location_updated_at 
                        ? $barcode->location_updated_at->diffInDays(now())
                        : null;

                    return [
                        'id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'product' => [
                            'id' => $barcode->product_id,
                            'name' => $barcode->product->name ?? 'Unknown',
                        ],
                        'destination_store' => $barcode->currentStore ? [
                            'id' => $barcode->currentStore->id,
                            'name' => $barcode->currentStore->name,
                        ] : null,
                        'transit_started_at' => $barcode->location_updated_at,
                        'days_in_transit' => $daysSinceTransit,
                        'dispatch_id' => $barcode->location_metadata['dispatch_id'] ?? null,
                    ];
                })
            ]
        ]);
    }

    /**
     * Get all barcodes for a specific product
     * 
     * GET /api/barcode-tracking/by-product/{productId}
     * Query params: status, store_id, available_only, per_page
     */
    public function getByProduct(Request $request, $productId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $query = ProductBarcode::where('product_id', $productId)
            ->with(['currentStore', 'batch']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('current_status', $request->status);
        }

        // Filter by store
        if ($request->has('store_id')) {
            $query->atStore($request->store_id);
        }

        // Only available for sale
        if ($request->boolean('available_only')) {
            $query->availableForSale();
        }

        // Pagination
        $perPage = $request->input('per_page', 50);
        $barcodes = $query->paginate($perPage);

        // Summary statistics
        $totalCount = ProductBarcode::where('product_id', $productId)->count();
        $activeCount = ProductBarcode::where('product_id', $productId)->active()->count();
        $availableCount = ProductBarcode::where('product_id', $productId)->availableForSale()->count();
        $soldCount = ProductBarcode::where('product_id', $productId)->withCustomer()->count();

        // Status breakdown
        $statusBreakdown = ProductBarcode::where('product_id', $productId)
            ->select('current_status', DB::raw('count(*) as count'))
            ->groupBy('current_status')
            ->get()
            ->pluck('count', 'current_status');

        // Store distribution
        $storeDistribution = ProductBarcode::where('product_id', $productId)
            ->whereNotNull('current_store_id')
            ->with('currentStore:id,name')
            ->get()
            ->groupBy('current_store_id')
            ->map(function ($items) {
                $store = $items->first()->currentStore;
                return [
                    'store_id' => $store->id,
                    'store_name' => $store->name,
                    'count' => $items->count(),
                    'available' => $items->filter(fn($b) => $b->isAvailableForSale())->count(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ],
                'summary' => [
                    'total_units' => $totalCount,
                    'active' => $activeCount,
                    'inactive' => $totalCount - $activeCount,
                    'available_for_sale' => $availableCount,
                    'sold' => $soldCount,
                ],
                'status_breakdown' => $statusBreakdown,
                'store_distribution' => $storeDistribution,
                'filters' => [
                    'status' => $request->status,
                    'store_id' => $request->store_id,
                    'available_only' => $request->boolean('available_only'),
                ],
                'barcodes' => $barcodes->items(),
                'pagination' => [
                    'current_page' => $barcodes->currentPage(),
                    'per_page' => $barcodes->perPage(),
                    'total' => $barcodes->total(),
                    'last_page' => $barcodes->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Get all barcodes for a specific batch
     * 
     * GET /api/barcode-tracking/by-batch/{batchId}
     * Query params: status, store_id, available_only
     */
    public function getByBatch(Request $request, $batchId)
    {
        $batch = ProductBatch::with('product')->find($batchId);

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        $query = ProductBarcode::where('batch_id', $batchId)
            ->with(['currentStore', 'product']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('current_status', $request->status);
        }

        // Filter by store
        if ($request->has('store_id')) {
            $query->atStore($request->store_id);
        }

        // Only available for sale
        if ($request->boolean('available_only')) {
            $query->availableForSale();
        }

        $barcodes = $query->get();

        // Summary
        $summary = [
            'total_units' => $barcodes->count(),
            'active' => $barcodes->where('is_active', true)->count(),
            'available_for_sale' => $barcodes->filter(fn($b) => $b->isAvailableForSale())->count(),
            'sold' => $barcodes->where('current_status', 'with_customer')->count(),
            'defective' => $barcodes->where('is_defective', true)->count(),
        ];

        // Status breakdown
        $statusBreakdown = $barcodes->groupBy('current_status')
            ->map(fn($items, $status) => [
                'status' => $status,
                'count' => $items->count()
            ])
            ->values();

        // Store distribution
        $storeDistribution = $barcodes->groupBy('current_store_id')
            ->map(function ($items) {
                $store = $items->first()->currentStore;
                return [
                    'store_id' => $store->id ?? null,
                    'store_name' => $store->name ?? 'Unknown',
                    'count' => $items->count(),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'batch' => [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'product' => [
                        'id' => $batch->product_id,
                        'name' => $batch->product->name ?? 'Unknown',
                        'sku' => $batch->product->sku ?? null,
                    ],
                    'original_quantity' => $batch->quantity,
                ],
                'summary' => $summary,
                'status_breakdown' => $statusBreakdown,
                'store_distribution' => $storeDistribution,
                'filters' => [
                    'status' => $request->status,
                    'store_id' => $request->store_id,
                    'available_only' => $request->boolean('available_only'),
                ],
                'barcodes' => $barcodes->map(function ($barcode) {
                    return [
                        'id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'current_store' => $barcode->currentStore ? [
                            'id' => $barcode->currentStore->id,
                            'name' => $barcode->currentStore->name,
                        ] : null,
                        'current_status' => $barcode->current_status,
                        'status_label' => $barcode->getStatusLabel(),
                        'is_active' => $barcode->is_active,
                        'is_defective' => $barcode->is_defective,
                        'is_available_for_sale' => $barcode->isAvailableForSale(),
                        'location_updated_at' => $barcode->location_updated_at,
                    ];
                })
            ]
        ]);
    }

    /**
     * Get barcodes sold within a date range
     * 
     * GET /api/barcode-tracking/sales
     * Query params: from_date, to_date, store_id, product_id, per_page
     */
    public function getSales(Request $request)
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $query = ProductMovement::where('movement_type', 'sale')
            ->whereBetween('movement_date', [$request->from_date, $request->to_date])
            ->with(['barcode.product', 'toStore', 'performedBy']);

        // Filter by store
        if ($request->has('store_id')) {
            $query->where('to_store_id', $request->store_id);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->whereHas('barcode', function ($q) use ($request) {
                $q->where('product_id', $request->product_id);
            });
        }

        // Pagination
        $perPage = $request->input('per_page', 50);
        $sales = $query->orderBy('movement_date', 'desc')->paginate($perPage);

        // Summary statistics
        $totalSales = $query->count();
        $uniqueProducts = ProductMovement::where('movement_type', 'sale')
            ->whereBetween('movement_date', [$request->from_date, $request->to_date])
            ->when($request->has('store_id'), function ($q) use ($request) {
                $q->where('to_store_id', $request->store_id);
            })
            ->join('product_barcodes', 'product_movements.product_barcode_id', '=', 'product_barcodes.id')
            ->distinct('product_barcodes.product_id')
            ->count('product_barcodes.product_id');

        // Sales by date
        $salesByDate = ProductMovement::where('movement_type', 'sale')
            ->whereBetween('movement_date', [$request->from_date, $request->to_date])
            ->when($request->has('store_id'), function ($q) use ($request) {
                $q->where('to_store_id', $request->store_id);
            })
            ->when($request->has('product_id'), function ($q) use ($request) {
                $q->whereHas('barcode', function ($q2) use ($request) {
                    $q2->where('product_id', $request->product_id);
                });
            });
            
        $dateCastSql = $this->getDateCastSql('movement_date');
        $trends = $query->selectRaw("{$dateCastSql} as date, COUNT(*) as count")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date_range' => [
                    'from' => $request->from_date,
                    'to' => $request->to_date,
                ],
                'filters' => [
                    'store_id' => $request->store_id,
                    'product_id' => $request->product_id,
                ],
                'summary' => [
                    'total_sales' => $totalSales,
                    'unique_products_sold' => $uniqueProducts,
                    'daily_average' => round($totalSales / max(1, now()->parse($request->to_date)->diffInDays($request->from_date) + 1), 2),
                ],
                'sales_by_date' => $salesByDate,
                'sales' => array_map(function ($movement) {
                    return [
                        'id' => $movement->id,
                        'sale_date' => $movement->movement_date,
                        'barcode' => $movement->barcode ? [
                            'id' => $movement->barcode->id,
                            'barcode' => $movement->barcode->barcode,
                            'product' => [
                                'id' => $movement->barcode->product_id,
                                'name' => $movement->barcode->product->name ?? 'Unknown',
                                'sku' => $movement->barcode->product->sku ?? null,
                            ],
                        ] : null,
                        'store' => $movement->toStore ? [
                            'id' => $movement->toStore->id,
                            'name' => $movement->toStore->name,
                        ] : null,
                        'sold_by' => $movement->performedBy ? [
                            'id' => $movement->performedBy->id,
                            'name' => $movement->performedBy->name,
                        ] : null,
                        'reference_type' => $movement->reference_type,
                        'reference_id' => $movement->reference_id,
                        'notes' => $movement->notes,
                    ];
                }, $sales->items()),
                'pagination' => [
                    'current_page' => $sales->currentPage(),
                    'per_page' => $sales->perPage(),
                    'total' => $sales->total(),
                    'last_page' => $sales->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Get barcodes by multiple stores comparison
     * 
     * POST /api/barcode-tracking/compare-stores
     * Body: { store_ids: [1,2,3], product_id, status }
     */
    public function compareStores(Request $request)
    {
        $request->validate([
            'store_ids' => 'required|array|min:2',
            'store_ids.*' => 'exists:stores,id',
        ]);

        $query = ProductBarcode::whereIn('current_store_id', $request->store_ids)
            ->with(['product', 'currentStore', 'batch']);

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('current_status', $request->status);
        }

        $barcodes = $query->get();

        // Group by store
        $storeComparison = collect($request->store_ids)->map(function ($storeId) use ($barcodes) {
            $store = Store::find($storeId);
            $storeBarcodes = $barcodes->where('current_store_id', $storeId);

            return [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'type' => $store->is_warehouse ? 'warehouse' : ($store->is_online ? 'online' : 'retail'),
                ],
                'summary' => [
                    'total_units' => $storeBarcodes->count(),
                    'available_for_sale' => $storeBarcodes->filter(fn($b) => $b->isAvailableForSale())->count(),
                    'on_display' => $storeBarcodes->where('current_status', 'on_display')->count(),
                    'in_warehouse' => $storeBarcodes->where('current_status', 'in_warehouse')->count(),
                ],
                'status_breakdown' => $storeBarcodes->groupBy('current_status')
                    ->map(fn($items, $status) => [
                        'status' => $status,
                        'count' => $items->count()
                    ])
                    ->values(),
                'product_breakdown' => $storeBarcodes->groupBy('product_id')
                    ->map(function ($items) {
                        $product = $items->first()->product;
                        return [
                            'product_id' => $product->id ?? null,
                            'product_name' => $product->name ?? 'Unknown',
                            'count' => $items->count(),
                        ];
                    })
                    ->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => [
                    'store_ids' => $request->store_ids,
                    'product_id' => $request->product_id,
                    'status' => $request->status,
                ],
                'total_barcodes' => $barcodes->count(),
                'store_comparison' => $storeComparison,
            ]
        ]);
    }

    /**
     * Get recently added barcodes
     * 
     * GET /api/barcode-tracking/recent
     * Query params: days (default: 7), store_id, product_id, per_page
     */
    public function getRecent(Request $request)
    {
        $days = $request->input('days', 7);
        $sinceDate = now()->subDays($days);

        $query = ProductBarcode::where('created_at', '>=', $sinceDate)
            ->with(['product', 'currentStore', 'batch']);

        // Filter by store
        if ($request->has('store_id')) {
            $query->atStore($request->store_id);
        }

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        // Pagination
        $perPage = $request->input('per_page', 50);
        $barcodes = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Summary by date
        $byDate = ProductBarcode::where('created_at', '>=', $sinceDate)
            ->when($request->has('store_id'), function ($q) use ($request) {
                $q->atStore($request->store_id);
            })
            ->when($request->has('product_id'), function ($q) use ($request) {
                $q->where('product_id', $request->product_id);
            });
            
        $dateCastSql = $this->getDateCastSql('created_at');
        $trends = $query->selectRaw("{$dateCastSql} as date, COUNT(*) as count")
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'days' => $days,
                    'since' => $sinceDate,
                ],
                'filters' => [
                    'store_id' => $request->store_id,
                    'product_id' => $request->product_id,
                ],
                'summary' => [
                    'total_new_barcodes' => $query->count(),
                    'daily_average' => round($query->count() / $days, 2),
                ],
                'by_date' => $byDate,
                'barcodes' => $barcodes->items(),
                'pagination' => [
                    'current_page' => $barcodes->currentPage(),
                    'per_page' => $barcodes->perPage(),
                    'total' => $barcodes->total(),
                    'last_page' => $barcodes->lastPage(),
                ]
            ]
        ]);
    }
}
