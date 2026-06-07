<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBarcode;
use App\Models\PurchaseOrderItem;
use App\Models\BatchDeletedBarcode;
use App\Models\MasterInventory;
use App\Models\Store;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Services\InventoryReservationService;

class ProductBatchController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * List all batches with filtering options
     * 
     * GET /api/batches
     * Query params: product_id, store_id, status, barcode, expiring_days
     */
    public function index(Request $request)
    {
        $query = ProductBatch::with(['product.images', 'store', 'barcode']);

        // Filter by product
        if ($request->filled('product_id')) {
            $query->byProduct($request->product_id);
        }

        $requestedStoreId = $request->filled('store_id') ? (int) $request->store_id : null;
        $productIdsFilter = [];

        // Filter by store. For barcode-tracked stock, a returned unit can be
        // physically restored to a store by product_barcodes.current_store_id
        // even if the batch row was left stale. Include those rows as candidates;
        // the self-heal below will relink them to a proper active batch.
        if ($requestedStoreId) {
            $query->where(function ($storeQuery) use ($requestedStoreId) {
                $storeQuery->where('store_id', $requestedStoreId)
                    ->orWhereHas('barcodes', function ($barcodeQuery) use ($requestedStoreId) {
                        $barcodeQuery->availableForSale()
                            ->where('current_store_id', $requestedStoreId);
                    });
            });
        }

        if ($request->filled('product_ids')) {
            $ids = $request->product_ids;
            if (is_string($ids)) {
                $ids = preg_split('/,/', $ids);
            }
            $productIdsFilter = collect((array) $ids)
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();
            if (!empty($productIdsFilter)) {
                $query->whereIn('product_id', $productIdsFilter);
            }
        }

        if ($request->filled('exact_price')) {
            $query->where('sell_price', (float) $request->exact_price);
        }

        if ($request->filled('min_sell_price')) {
            $query->where('sell_price', '>=', (float) $request->min_sell_price);
        }

        if ($request->filled('max_sell_price')) {
            $query->where('sell_price', '<=', (float) $request->max_sell_price);
        }

        // Filter by status
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'available':
                    $storeIdForAvailability = $requestedStoreId;
                    $query->where(function ($availabilityQuery) use ($storeIdForAvailability) {
                        // Normal batch-level availability.
                        $availabilityQuery->where(function ($batchQuery) {
                            $batchQuery->where('availability', true)
                                ->where('quantity', '>', 0);
                        })
                        // Barcode lifecycle availability. This is important after
                        // lookup returns/exchanges: POS can sell a restored barcode
                        // when the barcode is active/sellable and the batch quantity
                        // is back above zero, even if the batch availability flag was
                        // left stale as false when stock previously hit zero.
                        ->orWhere(function ($barcodeBackedQuery) use ($storeIdForAvailability) {
                            $barcodeBackedQuery->where('quantity', '>', 0)
                                ->whereHas('barcodes', function ($barcodeQuery) use ($storeIdForAvailability) {
                                    $barcodeQuery->availableForSale();
                                    if ($storeIdForAvailability) {
                                        $barcodeQuery->where('current_store_id', $storeIdForAvailability);
                                    }
                                });
                        });
                    });
                    break;
                case 'expired':
                    $query->expired();
                    break;
                case 'low_stock':
                    $query->where('quantity', '<=', $request->input('threshold', 10))
                          ->where('quantity', '>', 0);
                    break;
                case 'out_of_stock':
                    $query->where('quantity', 0);
                    break;
                case 'inactive':
                    $query->where('is_active', false);
                    break;
            }
        }

        // Filter by barcode. Match any unit barcode in the batch, not only
        // the batch's primary barcode_id. Returned/exchanged units often become
        // sellable again as individual ProductBarcode rows while the batch primary
        // barcode may be a different unit.
        if ($request->filled('barcode')) {
            $barcode = trim((string) $request->barcode);
            $query->whereHas('barcodes', function ($q) use ($barcode) {
                $q->where('barcode', $barcode);
            });
        }

        // Filter expiring soon
        if ($request->filled('expiring_days')) {
            $query->expiringSoon($request->expiring_days);
        }

        // Search by batch number, product name, or product SKU
        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            if ($term !== '') {
                $query->where(function ($q) use ($term) {
                    $q->where('batch_number', 'like', "%{$term}%")
                        ->orWhereHas('barcodes', function($bq) use ($term) {
                            $bq->where('barcode', 'like', "%{$term}%");
                        })
                        ->orWhereHas('product', function ($productQuery) use ($term) {
                            $productQuery->where('name', 'like', "%{$term}%")
                                ->orWhere('base_name', 'like', "%{$term}%")
                                ->orWhere('variation_suffix', 'like', "%{$term}%")
                                ->orWhere('sku', 'like', "%{$term}%");
                        });
                });
            }
        }

        // Before executing the list query, revive any returned unit whose barcode
        // is already sellable but whose batch row is still stock-out/inactive/stale.
        // This is needed not only for social-commerce selected-store searches, but
        // also for Inventory > Batch Price Update, which loads batches by product_id
        // without a store/status filter.
        app(InventoryReservationService::class)->reviveSellableBarcodeBackedBatches(
            $productIdsFilter,
            $requestedStoreId ? [$requestedStoreId] : [],
            $request->input('search') ?: $request->input('barcode')
        );

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $batches = $query->paginate($request->input('per_page', 20));

        // Format the response. When a specific store is requested, expose the
        // barcode-lifecycle sellable quantity for that store so social-commerce
        // does not trust stale batch.quantity after low-stock return/exchange.
        $formattedBatches = [];
        foreach ($batches as $batch) {
            if ($requestedStoreId) {
                $sellableQuantityForStore = ProductBarcode::where('batch_id', $batch->id)
                    ->where('current_store_id', $requestedStoreId)
                    ->availableForSale()
                    ->count();

                $batch->setAttribute('store_sellable_quantity', $sellableQuantityForStore);
                $batch->setAttribute('store_available_quantity', $sellableQuantityForStore > 0 ? $sellableQuantityForStore : (int) $batch->quantity);
            }

            $formattedBatches[] = $this->formatBatchResponse($batch);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $batches->currentPage(),
                'data' => $formattedBatches,
                'first_page_url' => $batches->url(1),
                'from' => $batches->firstItem(),
                'last_page' => $batches->lastPage(),
                'last_page_url' => $batches->url($batches->lastPage()),
                'next_page_url' => $batches->nextPageUrl(),
                'path' => $batches->path(),
                'per_page' => $batches->perPage(),
                'prev_page_url' => $batches->previousPageUrl(),
                'to' => $batches->lastItem(),
                'total' => $batches->total(),
            ]
        ]);
    }

    /**
     * Get specific batch details
     * 
     * GET /api/batches/{id}
     */
    public function show($id)
    {
        $batch = ProductBatch::with([
            'product.images',
            'product.category',
            'product.vendor',
            'store',
            'barcode'
        ])->find($id);

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatBatchResponse($batch, true)
        ]);
    }

    /**
     * Create new batch (physical inventory received)
     * 
     * IMPORTANT: By default, generates individual barcodes for EACH physical unit
     * This is essential for:
     * - Tracking individual defective items
     * - Individual sales and returns
     * - Inventory audits
     * - Item-level traceability
     * 
     * POST /api/batches
     * Body: {
     *   "product_id": 1,
     *   "store_id": 1,
     *   "quantity": 100,
     *   "cost_price": 500.00,
     *   "sell_price": 750.00,
     *   "manufactured_date": "2024-01-01",
     *   "expiry_date": "2026-01-01",
     *   "barcode_type": "CODE128",
     *   "skip_barcode_generation": false,  // Set to true to skip (NOT RECOMMENDED)
     *   "notes": "Received from vendor X"
     * }
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'store_id' => 'required|exists:stores,id',
            'quantity' => 'required|integer|min:1|max:10000',  // Max 10k units per batch for performance
            'cost_price' => 'required|numeric|min:0',
            'sell_price' => 'required|numeric|min:0',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'manufactured_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:manufactured_date',
            'skip_barcode_generation' => 'boolean',
            'barcode_type' => 'string|in:CODE128,EAN13,QR',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Warning for large batches
        if ($request->quantity > 1000) {
            \Log::warning("Large batch creation: {$request->quantity} units. This will generate {$request->quantity} barcodes.", [
                'product_id' => $request->product_id,
                'store_id' => $request->store_id,
            ]);
        }

        DB::beginTransaction();
        try {
            // Create the batch
            $batch = ProductBatch::create([
                'product_id' => $request->product_id,
                'store_id' => $request->store_id,
                'quantity' => $request->quantity,
                'cost_price' => $request->cost_price,
                'sell_price' => $request->sell_price,
                'tax_percentage' => $request->input('tax_percentage', 0),
                'availability' => true,
                'manufactured_date' => $request->manufactured_date,
                'expiry_date' => $request->expiry_date,
                'notes' => $request->notes,
                'is_active' => true,
            ]);

            // Generate individual barcodes for EACH unit (unless explicitly skipped)
            $barcodes = [];
            $skipBarcodes = $request->input('skip_barcode_generation', false);
            
            if (!$skipBarcodes) {
                $barcodeType = $request->input('barcode_type', 'CODE128');
                $quantity = $request->quantity;
                
            // Determine initial status based on store type
            $store = Store::find($request->store_id);
            $initialStatus = $store && $store->is_warehouse 
                ? 'in_warehouse' 
                : 'in_shop';                // Generate barcodes for all units
                // First barcode is the primary one (associated with batch)
                for ($i = 0; $i < $quantity; $i++) {
                    $barcode = ProductBarcode::create([
                        'product_id' => $request->product_id,
                        'batch_id' => $batch->id,  // Link barcode to batch
                        'type' => $barcodeType,
                        'is_primary' => ($i === 0),  // First barcode is primary
                        'is_active' => true,
                        'generated_at' => now(),
                        'current_store_id' => $request->store_id,  // Set initial location
                        'current_status' => $initialStatus,  // Set initial status
                        'location_updated_at' => now(),  // Track location set time
                    ]);
                    
                    $barcodes[] = $barcode;
                    
                    // Associate primary barcode with batch
                    if ($i === 0) {
                        $batch->update(['barcode_id' => $barcode->id]);
                    }
                }
            }

            DB::commit();

            app(InventoryReservationService::class)->syncProduct((int) $request->product_id);

            $response = [
                'success' => true,
                'message' => $skipBarcodes 
                    ? 'Batch created successfully (barcodes skipped)' 
                    : "Batch created successfully with {$request->quantity} individual barcodes",
                'data' => [
                    'batch' => $this->formatBatchResponse($batch->fresh(['product', 'store', 'barcode']), true),
                    'barcodes_generated' => count($barcodes),
                    'primary_barcode' => $barcodes[0] ?? null,
                ]
            ];

            // Include all barcodes for small batches
            if (count($barcodes) <= 20) {
                $response['data']['all_barcodes'] = array_map(function($bc) {
                    return [
                        'id' => $bc->id,
                        'barcode' => $bc->barcode,
                        'type' => $bc->type,
                        'is_primary' => $bc->is_primary,
                    ];
                }, $barcodes);
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create batch: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update batch details
     * 
     * PUT /api/batches/{id}
     */
    public function update(Request $request, $id)
    {
        $batch = ProductBatch::find($id);

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'integer|min:0',
            'cost_price' => 'numeric|min:0',
            'sell_price' => 'numeric|min:0',
            'availability' => 'boolean',
            'manufactured_date' => 'date',
            'expiry_date' => 'date|after:manufactured_date',
            'is_active' => 'boolean',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $batch->update($request->only([
            'quantity',
            'cost_price',
            'sell_price',
            'availability',
            'manufactured_date',
            'expiry_date',
            'is_active',
            'notes'
        ]));

        app(InventoryReservationService::class)->reviveSellableBarcodeBackedBatches([(int) $batch->product_id], [(int) $batch->store_id]);
        app(InventoryReservationService::class)->syncProduct((int) $batch->product_id);

        return response()->json([
            'success' => true,
            'message' => 'Batch updated successfully',
            'data' => $this->formatBatchResponse($batch->fresh(['product', 'store', 'barcode']), true)
        ]);
    }

    /**
     * Adjust batch quantity (add or remove stock)
     * 
     * POST /api/batches/{id}/adjust-stock
     * Body: {
     *   "adjustment": 10,  // Positive to add, negative to remove
     *   "reason": "Damaged units removed"
     * }
     */
    public function adjustStock(Request $request, $id)
    {
        $batch = ProductBatch::find($id);

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'adjustment' => 'required|integer|not_in:0',
            'reason' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $oldQuantity = $batch->quantity;
        $newQuantity = max(0, $oldQuantity + $request->adjustment);

        if ($request->adjustment > 0) {
            $batch->addStock($request->adjustment);
        } else {
            $batch->removeStock(abs($request->adjustment));
        }

        // Log the adjustment in notes
        $note = sprintf(
            "[%s] Stock adjusted: %d → %d (%+d). Reason: %s",
            now()->format('Y-m-d H:i:s'),
            $oldQuantity,
            $newQuantity,
            $request->adjustment,
            $request->reason
        );
        
        $batch->update([
            'notes' => ($batch->notes ? $batch->notes . "\n" : '') . $note
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Stock adjusted successfully',
            'data' => [
                'batch' => $this->formatBatchResponse($batch->fresh(['product', 'store', 'barcode']), true),
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
                'adjustment' => $request->adjustment
            ]
        ]);
    }

    /**
     * Get batches that are low on stock
     * 
     * GET /api/batches/low-stock
     */
    public function getLowStock(Request $request)
    {
        $threshold = $request->input('threshold', 10);
        $storeId = $request->input('store_id');

        $query = ProductBatch::with(['product.images', 'store', 'barcode'])
            ->where('quantity', '<=', $threshold)
            ->where('quantity', '>', 0)
            ->where('is_active', true);

        if ($storeId) {
            $query->byStore($storeId);
        }

        $batches = $query->orderBy('quantity', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'threshold' => $threshold,
                'count' => $batches->count(),
                'batches' => $batches->map(function ($batch) {
                    return $this->formatBatchResponse($batch);
                })
            ]
        ]);
    }

    /**
     * Get batches that are expiring soon
     * 
     * GET /api/batches/expiring-soon
     */
    public function getExpiringSoon(Request $request)
    {
        $days = $request->input('days', 30);
        $storeId = $request->input('store_id');

        $query = ProductBatch::with(['product.images', 'store', 'barcode'])
            ->expiringSoon($days)
            ->where('is_active', true);

        if ($storeId) {
            $query->byStore($storeId);
        }

        $batches = $query->orderBy('expiry_date', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'days' => $days,
                'count' => $batches->count(),
                'batches' => $batches->map(function ($batch) {
                    return $this->formatBatchResponse($batch);
                })
            ]
        ]);
    }

    /**
     * Get expired batches
     * 
     * GET /api/batches/expired
     */
    public function getExpired(Request $request)
    {
        $storeId = $request->input('store_id');

        $query = ProductBatch::with(['product.images', 'store', 'barcode'])
            ->expired()
            ->where('is_active', true);

        if ($storeId) {
            $query->byStore($storeId);
        }

        $batches = $query->orderBy('expiry_date', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'count' => $batches->count(),
                'batches' => $batches->map(function ($batch) {
                    return $this->formatBatchResponse($batch);
                })
            ]
        ]);
    }

    /**
     * Get batch statistics
     * 
     * GET /api/batches/statistics
     */
    public function getStatistics(Request $request)
    {
        $storeId = $request->input('store_id');

        $query = ProductBatch::query();
        
        if ($storeId) {
            $query->byStore($storeId);
        }

        $stats = [
            'total_batches' => $query->count(),
            'active_batches' => (clone $query)->where('is_active', true)->count(),
            'available_batches' => (clone $query)->available()->count(),
            'low_stock_batches' => (clone $query)->where('quantity', '<=', 10)->where('quantity', '>', 0)->count(),
            'out_of_stock_batches' => (clone $query)->where('quantity', 0)->count(),
            'expiring_soon_batches' => (clone $query)->expiringSoon(30)->count(),
            'expired_batches' => (clone $query)->expired()->count(),
            'total_inventory_value' => (clone $query)->sum(DB::raw('quantity * cost_price')),
            'total_sell_value' => (clone $query)->sum(DB::raw('quantity * sell_price')),
            'total_units' => (clone $query)->sum('quantity'),
        ];

        // By store breakdown
        if (!$storeId) {
            $stats['by_store'] = ProductBatch::select('store_id')
                ->selectRaw('COUNT(*) as batch_count')
                ->selectRaw('SUM(quantity) as total_units')
                ->selectRaw('SUM(quantity * cost_price) as inventory_value')
                ->with('store:id,name')
                ->groupBy('store_id')
                ->get()
                ->map(function ($item) {
                    return [
                        'store_id' => $item->store_id,
                        'store_name' => $item->store->name ?? 'Unknown',
                        'batch_count' => $item->batch_count,
                        'total_units' => $item->total_units,
                        'inventory_value' => number_format((float)$item->inventory_value, 2)
                    ];
                });
        }

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Delete a single batch while preserving barcode identities.
     *
     * This mirrors the purchase-order deletion safety rule: the physical barcode
     * rows are kept, detached from the deleted batch, and written to
     * batch_deleted_barcodes so POS/online packing cannot sell them again.
     * Lookup/return/exchange can still find the barcode history.
     *
     * DELETE /api/batches/{id}
     */
    public function destroy($id)
    {
        $batch = ProductBatch::with(['product', 'store'])->find($id);

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        DB::beginTransaction();
        try {
            $productId = $batch->product_id;
            $batchNumber = $batch->batch_number;
            $storeId = $batch->store_id;
            $storeName = $batch->store?->name;

            $poItem = PurchaseOrderItem::with('purchaseOrder')
                ->where('product_batch_id', $batch->id)
                ->first();

            $barcodes = ProductBarcode::where('batch_id', $batch->id)
                ->get(['id', 'batch_id', 'product_id']);

            foreach ($barcodes as $barcode) {
                BatchDeletedBarcode::updateOrCreate(
                    ['product_barcode_id' => $barcode->id],
                    [
                        'deleted_product_batch_id' => $batch->id,
                        'deleted_batch_number' => $batchNumber,
                        'product_id' => $barcode->product_id ?: $productId,
                        'store_id' => $storeId,
                        'store_name' => $storeName,
                        'purchase_order_id' => $poItem?->purchase_order_id,
                        'purchase_order_number' => $poItem?->purchaseOrder?->po_number,
                        'deleted_by' => auth()->id(),
                        'deleted_at' => now(),
                    ]
                );
            }

            // product_barcodes.batch_id uses a cascading FK in existing installs.
            // Detach first so deleting product_batches does not delete barcodes.
            ProductBarcode::where('batch_id', $batch->id)->update(['batch_id' => null]);

            $batch->delete();

            DB::commit();

            if (method_exists(MasterInventory::class, 'syncProductInventory')) {
                MasterInventory::syncProductInventory($productId);
            }

            return response()->json([
                'success' => true,
                'message' => 'Batch deleted. Its barcodes were preserved and blocked from POS/online packing sale. Use Lookup return/exchange if a customer brings one back.',
                'data' => [
                    'deleted_batch_id' => (int) $id,
                    'deleted_batch_number' => $batchNumber,
                    'barcodes_logged' => $barcodes->count(),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete batch: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Preview the destructive stock reset used by Inventory > Delete Bulk Batch.
     *
     * POST /api/batches/delete-bulk-batch/preview
     */
    public function previewBulkDeleteRecreate(Request $request)
    {
        $this->normalizeBulkDeletePriceInput($request);

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'store_id' => 'required|exists:stores,id',
            'quantity' => 'required|integer|min:1|max:10000',
            'cost_price' => 'required|numeric|min:0',
            'sell_price' => 'required|numeric|min:0',
        ], [
            'cost_price.required' => 'Cost price is required before previewing the stock reset.',
            'sell_price.required' => 'Selling price is required before previewing the stock reset.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $product = Product::findOrFail($request->product_id);
        $store = Store::findOrFail($request->store_id);
        $summary = $this->buildBulkDeleteRecreateSummary($product, $store, (int) $request->quantity, $request);

        return response()->json([
            'success' => true,
            'message' => 'Preview ready. Confirm only if this stock reset is intentional.',
            'data' => $summary,
        ]);
    }

    /**
     * Delete all existing batches for a product, log their barcodes as blocked,
     * then create one fresh batch under the selected store with fresh unit barcodes.
     *
     * POST /api/batches/delete-bulk-batch/confirm
     */
    public function bulkDeleteRecreate(Request $request)
    {
        $this->normalizeBulkDeletePriceInput($request);

        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'store_id' => 'required|exists:stores,id',
            'quantity' => 'required|integer|min:1|max:10000',
            'cost_price' => 'required|numeric|min:0',
            'sell_price' => 'required|numeric|min:0',
            'barcode_type' => 'nullable|string|in:CODE128,EAN13,QR',
        ], [
            'cost_price.required' => 'Cost price is required before confirming the stock reset.',
            'sell_price.required' => 'Selling price is required before confirming the stock reset.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $productId = (int) $request->product_id;
        $storeId = (int) $request->store_id;
        $quantity = (int) $request->quantity;

        DB::beginTransaction();
        try {
            $product = Product::lockForUpdate()->findOrFail($productId);
            $store = Store::findOrFail($storeId);

            $oldBatches = ProductBatch::with(['product', 'store'])
                ->where('product_id', $productId)
                ->orderBy('id')
                ->get();

            $priceSource = $this->resolveBulkBatchPrices($productId, $request);
            $deletedSummaries = [];
            $deletedBatches = 0;
            $deletedUnits = 0;
            $blockedBarcodes = 0;

            foreach ($oldBatches as $batch) {
                $deletedUnits += (int) $batch->quantity;
                $deletedSummaries[] = $this->deleteBatchInsideTransaction($batch);
                $deletedBatches++;
                $blockedBarcodes += (int) end($deletedSummaries)['barcodes_logged'];
            }

            $created = $this->createBatchInsideTransaction([
                'product_id' => $productId,
                'store_id' => $storeId,
                'quantity' => $quantity,
                'cost_price' => (float) $priceSource['cost_price'],
                'sell_price' => (float) $priceSource['sell_price'],
                'barcode_type' => $request->input('barcode_type', 'CODE128'),
                'notes' => 'Created from Delete Bulk Batch stock reset after deleting previous batches across all stores.',
            ]);

            DB::commit();

            if (class_exists(InventoryReservationService::class)) {
                app(InventoryReservationService::class)->syncProduct($productId);
            }

            if (method_exists(MasterInventory::class, 'syncProductInventory')) {
                MasterInventory::syncProductInventory($productId);
            }

            return response()->json([
                'success' => true,
                'message' => "Stock updated. {$deletedBatches} old batch(es) deleted, {$blockedBarcodes} old barcode(s) blocked, and {$quantity} fresh barcode(s) generated.",
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'sku' => $product->sku,
                    ],
                    'store' => [
                        'id' => $store->id,
                        'name' => $store->name,
                    ],
                    'deleted_batches' => $deletedBatches,
                    'deleted_units' => $deletedUnits,
                    'blocked_barcodes' => $blockedBarcodes,
                    'deleted_batch_details' => $deletedSummaries,
                    'created_batch' => $this->formatBatchResponse($created['batch']->fresh(['product.images', 'store', 'barcode']), true),
                    'barcodes_generated' => count($created['barcodes']),
                    'all_barcodes' => collect($created['barcodes'])->map(function ($bc) {
                        return [
                            'id' => $bc->id,
                            'barcode' => $bc->barcode,
                            'type' => $bc->type,
                            'is_primary' => (bool) $bc->is_primary,
                        ];
                    })->values(),
                    'price_source' => $priceSource,
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function buildBulkDeleteRecreateSummary(Product $product, Store $store, int $quantity, Request $request): array
    {
        $batches = ProductBatch::with('store')
            ->where('product_id', $product->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $batchIds = $batches->pluck('id')->all();
        $barcodesToBlock = empty($batchIds)
            ? 0
            : ProductBarcode::whereIn('batch_id', $batchIds)->count();

        $priceSource = $this->resolveBulkBatchPrices($product->id, $request);

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
            ],
            'target_store' => [
                'id' => $store->id,
                'name' => $store->name,
                'type' => $store->is_warehouse ? 'warehouse' : ($store->is_online ? 'online' : 'retail'),
            ],
            'new_stock_count' => $quantity,
            'existing_batches' => $batches->count(),
            'existing_units' => (int) $batches->sum('quantity'),
            'barcodes_to_block' => $barcodesToBlock,
            'old_batches' => $batches->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'store_id' => $batch->store_id,
                    'store_name' => $batch->store?->name,
                    'quantity' => (int) $batch->quantity,
                    'cost_price' => (float) $batch->cost_price,
                    'sell_price' => (float) $batch->sell_price,
                    'created_at' => $batch->created_at?->format('Y-m-d H:i:s'),
                ];
            })->values(),
            'new_batch' => [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'quantity' => $quantity,
                'cost_price' => (float) $priceSource['cost_price'],
                'sell_price' => (float) $priceSource['sell_price'],
            ],
            'price_source' => $priceSource,
            'warnings' => [
                'This will permanently delete every current batch for this exact product across every store.',
                'Old unit barcodes will be recorded in batch_deleted_barcodes and blocked from POS/social-commerce packing.',
                'Lookup return/exchange will still be able to process those old barcodes.',
                'Cost price and selling price are required manual inputs for this reset. The backend will not guess prices from older batches.',
            ],
        ];
    }

    private function normalizeBulkDeletePriceInput(Request $request): void
    {
        if (!$request->filled('sell_price') && $request->filled('selling_price')) {
            $request->merge(['sell_price' => $request->input('selling_price')]);
        }
    }

    private function resolveBulkBatchPrices(int $productId, Request $request): array
    {
        return [
            'source' => 'manual_input',
            'source_batch_id' => null,
            'source_batch_number' => null,
            'cost_price' => (float) $request->input('cost_price'),
            'sell_price' => (float) $request->input('sell_price'),
        ];
    }

    private function deleteBatchInsideTransaction(ProductBatch $batch): array
    {
        $productId = $batch->product_id;
        $batchNumber = $batch->batch_number;
        $storeId = $batch->store_id;
        $storeName = $batch->store?->name;

        $poItem = PurchaseOrderItem::with('purchaseOrder')
            ->where('product_batch_id', $batch->id)
            ->first();

        $barcodes = ProductBarcode::where('batch_id', $batch->id)
            ->get(['id', 'batch_id', 'product_id']);

        foreach ($barcodes as $barcode) {
            BatchDeletedBarcode::updateOrCreate(
                ['product_barcode_id' => $barcode->id],
                [
                    'deleted_product_batch_id' => $batch->id,
                    'deleted_batch_number' => $batchNumber,
                    'product_id' => $barcode->product_id ?: $productId,
                    'store_id' => $storeId,
                    'store_name' => $storeName,
                    'purchase_order_id' => $poItem?->purchase_order_id,
                    'purchase_order_number' => $poItem?->purchaseOrder?->po_number,
                    'deleted_by' => auth()->id(),
                    'deleted_at' => now(),
                ]
            );
        }

        ProductBarcode::where('batch_id', $batch->id)->update(['batch_id' => null]);
        $batch->delete();

        return [
            'deleted_batch_id' => (int) $batch->id,
            'deleted_batch_number' => $batchNumber,
            'store_id' => $storeId,
            'store_name' => $storeName,
            'quantity' => (int) $batch->quantity,
            'barcodes_logged' => $barcodes->count(),
        ];
    }

    private function createBatchInsideTransaction(array $data): array
    {
        $batch = ProductBatch::create([
            'product_id' => $data['product_id'],
            'store_id' => $data['store_id'],
            'quantity' => $data['quantity'],
            'cost_price' => $data['cost_price'],
            'sell_price' => $data['sell_price'],
            'tax_percentage' => $data['tax_percentage'] ?? 0,
            'availability' => true,
            'manufactured_date' => $data['manufactured_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => true,
        ]);

        $store = Store::find($data['store_id']);
        $initialStatus = $store && $store->is_warehouse ? 'in_warehouse' : 'in_shop';
        $barcodeType = $data['barcode_type'] ?? 'CODE128';
        $barcodes = [];

        for ($i = 0; $i < (int) $data['quantity']; $i++) {
            $barcode = ProductBarcode::create([
                'product_id' => $data['product_id'],
                'batch_id' => $batch->id,
                'type' => $barcodeType,
                'is_primary' => ($i === 0),
                'is_active' => true,
                'generated_at' => now(),
                'current_store_id' => $data['store_id'],
                'current_status' => $initialStatus,
                'location_updated_at' => now(),
            ]);

            $barcodes[] = $barcode;

            if ($i === 0) {
                $batch->update(['barcode_id' => $barcode->id]);
            }
        }

        return [
            'batch' => $batch,
            'barcodes' => $barcodes,
        ];
    }

    /**
     * Helper function to format batch response
     */
    private function formatBatchResponse(ProductBatch $batch, $detailed = false)
    {
        $response = [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'product' => [
                'id' => $batch->product->id,
                'name' => $batch->product->name,
                'sku' => $batch->product->sku,
                'primary_image' => $batch->product->images->where('is_primary', true)->first() ? [
                    'url' => $batch->product->images->where('is_primary', true)->first()->image_url
                ] : null,
            ],
            'store' => [
                'id' => $batch->store->id,
                'name' => $batch->store->name,
            ],
            'quantity' => $batch->quantity,
            'store_sellable_quantity' => $batch->getAttribute('store_sellable_quantity'),
            'store_available_quantity' => $batch->getAttribute('store_available_quantity'),
            'cost_price' => number_format((float)$batch->cost_price, 2),
            'sell_price' => number_format((float)$batch->sell_price, 2),
            'profit_margin' => $batch->calculateProfitMargin() . '%',
            'total_value' => number_format((float)$batch->getTotalValue(), 2),
            'sell_value' => number_format((float)$batch->getSellValue(), 2),
            'availability' => $batch->availability,
            'status' => $batch->status,
            'is_active' => $batch->is_active,
            'manufactured_date' => $batch->manufactured_date ? date('Y-m-d', strtotime($batch->manufactured_date)) : null,
            'expiry_date' => $batch->expiry_date ? date('Y-m-d', strtotime($batch->expiry_date)) : null,
            'days_until_expiry' => $batch->getDaysUntilExpiry(),
            'barcode' => $batch->barcode ? [
                'id' => $batch->barcode->id,
                'barcode' => $batch->barcode->barcode,
                'type' => $batch->barcode->type,
            ] : null,
            'created_at' => $batch->created_at->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $response['product']['category'] = $batch->product->category ? [
                'id' => $batch->product->category->id,
                'name' => $batch->product->category->name,
            ] : null;
            $response['product']['vendor'] = $batch->product->vendor ? [
                'id' => $batch->product->vendor->id,
                'name' => $batch->product->vendor->name,
            ] : null;
            $response['notes'] = $batch->notes;
            $response['movement_count'] = $batch->getMovementCount();
            $response['last_movement'] = $batch->getLastMovement()?->movement_date?->format('Y-m-d H:i:s');
        }

        return $response;
    }

    /**
     * Update selling price for all batches of a specific product
     * 
     * POST /api/products/{product_id}/batches/update-price
     * 
     * Request body:
     * {
     *   "sell_price": 4000.00
     * }
     * 
     * @param Request $request
     * @param int $productId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateAllBatchPrices(Request $request, $productId)
    {
        $validator = Validator::make($request->all(), [
            'sell_price' => 'nullable|required_without:cost_price|numeric|min:0',
            'cost_price' => 'nullable|required_without:sell_price|numeric|min:0',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Verify product exists
            $product = Product::find($productId);
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found',
                ], 404);
            }

            $targetProductIds = $request->filled('product_ids')
                ? collect($request->input('product_ids'))
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0)
                    ->unique()
                    ->values()
                    ->all()
                : [(int) $productId];

            if (empty($targetProductIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No products selected for batch price update',
                ], 422);
            }

            $hasSellPrice = $request->filled('sell_price');
            $hasCostPrice = $request->filled('cost_price');
            $newSellPrice = $hasSellPrice ? (float)$request->sell_price : null;
            $newCostPrice = $hasCostPrice ? (float)$request->cost_price : null;

            // Revive returned-barcode backed stock-out batches first so the price
            // update page can see and update batches that were restored from lookup
            // return/exchange after reaching zero stock.
            app(InventoryReservationService::class)->reviveSellableBarcodeBackedBatches($targetProductIds);

            // Get all batches for the selected product(s)
            $batches = ProductBatch::whereIn('product_id', $targetProductIds)->with(['store', 'product'])->get();

            if ($batches->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No batches found for selected products',
                ], 404);
            }

            // Store old prices for response
            $updates = [];
            
            DB::beginTransaction();
            try {
                foreach ($batches as $batch) {
                    $oldSellPrice = $batch->sell_price;
                    $oldCostPrice = $batch->cost_price;

                    if ($hasSellPrice) {
                        $batch->sell_price = $newSellPrice;
                    }
                    if ($hasCostPrice) {
                        $batch->cost_price = $newCostPrice;
                    }
                    
                    \Illuminate\Support\Facades\Log::info('BATCH_PRICE_UPDATE_DIAGNOSTIC', [
                        'batch_id' => $batch->id,
                        'product_id' => $batch->product_id,
                        'old_sell_price' => $oldSellPrice,
                        'new_sell_price' => $hasSellPrice ? $newSellPrice : $oldSellPrice,
                        'old_cost_price' => $oldCostPrice,
                        'new_cost_price' => $hasCostPrice ? $newCostPrice : $oldCostPrice,
                    ]);
                    
                    $batch->save();

                    $updates[] = [
                        'batch_id' => $batch->id,
                        'batch_number' => $batch->batch_number,
                        'product_name' => $batch->product->name ?? 'Unknown Product',
                        'store' => $batch->store->name ?? 'N/A',
                        'old_price' => number_format((float)$oldSellPrice, 2),
                        'new_price' => number_format((float)($hasSellPrice ? $newSellPrice : $oldSellPrice), 2),
                        'old_sell_price' => number_format((float)$oldSellPrice, 2),
                        'new_sell_price' => number_format((float)($hasSellPrice ? $newSellPrice : $oldSellPrice), 2),
                        'old_cost_price' => number_format((float)$oldCostPrice, 2),
                        'new_cost_price' => number_format((float)($hasCostPrice ? $newCostPrice : $oldCostPrice), 2),
                    ];
                }

                DB::commit();

                foreach ($targetProductIds as $targetProductId) {
                    app(InventoryReservationService::class)->syncProduct((int) $targetProductId);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Successfully updated batch prices',
                    'data' => [
                        'product_id' => $product->id,
                        'product_ids' => $targetProductIds,
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'new_sell_price' => $hasSellPrice ? number_format((float)$newSellPrice, 2) : null,
                        'new_cost_price' => $hasCostPrice ? number_format((float)$newCostPrice, 2) : null,
                        'batches_updated' => count($updates),
                        'updates' => $updates,
                    ],
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update batch prices: ' . $e->getMessage(),
            ], 500);
        }
    }
}
