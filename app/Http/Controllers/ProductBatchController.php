<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBarcode;
use App\Models\Store;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
        $query = ProductBatch::with(['product', 'store', 'barcode']);

        // Filter by product
        if ($request->filled('product_id')) {
            $query->byProduct($request->product_id);
        }

        // Filter by store
        if ($request->filled('store_id')) {
            $query->byStore($request->store_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'available':
                    $query->available();
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

        // Filter by barcode
        if ($request->filled('barcode')) {
            $query->whereHas('barcode', function ($q) use ($request) {
                $q->where('barcode', $request->barcode);
            });
        }

        // Filter expiring soon
        if ($request->filled('expiring_days')) {
            $query->expiringSoon($request->expiring_days);
        }

        // Search by batch number, product name, or product SKU
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $this->whereLike($q, 'batch_number', $search);
                $q->orWhereHas('product', function ($pq) use ($search) {
                    $this->whereLike($pq, 'name', $search);
                    $this->orWhereLike($pq, 'sku', $search);
                });
            });
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $batches = $query->paginate($request->input('per_page', 20));

        // Format the response
        $formattedBatches = [];
        foreach ($batches as $batch) {
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

        $query = ProductBatch::with(['product', 'store', 'barcode'])
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

        $query = ProductBatch::with(['product', 'store', 'barcode'])
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

        $query = ProductBatch::with(['product', 'store', 'barcode'])
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
     * Delete/deactivate a batch
     * 
     * DELETE /api/batches/{id}
     */
    public function destroy($id)
    {
        $batch = ProductBatch::find($id);

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        // Check if batch has been used in any dispatches or movements
        $hasMovements = $batch->getMovementCount() > 0;

        if ($hasMovements) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete batch with movement history. Consider deactivating it instead.'
            ], 422);
        }

        // Deactivate instead of delete to preserve data integrity
        $batch->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Batch deactivated successfully'
        ]);
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
            ],
            'store' => [
                'id' => $batch->store->id,
                'name' => $batch->store->name,
            ],
            'quantity' => $batch->quantity,
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
            'sell_price' => 'required|numeric|min:0',
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

            $newSellPrice = $request->sell_price;

            // Get all batches for this product
            $batches = ProductBatch::where('product_id', $productId)->get();

            if ($batches->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No batches found for this product',
                ], 404);
            }

            // Store old prices for response
            $updates = [];
            
            DB::beginTransaction();
            try {
                foreach ($batches as $batch) {
                    $oldPrice = $batch->sell_price;
                    $batch->sell_price = $newSellPrice;
                    $batch->save();

                    $updates[] = [
                        'batch_id' => $batch->id,
                        'batch_number' => $batch->batch_number,
                        'store' => $batch->store->name ?? 'N/A',
                        'old_price' => number_format((float)$oldPrice, 2),
                        'new_price' => number_format((float)$newSellPrice, 2),
                    ];
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Successfully updated selling price for all batches',
                    'data' => [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'new_sell_price' => number_format((float)$newSellPrice, 2),
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
