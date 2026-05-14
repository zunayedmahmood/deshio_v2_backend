<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductBatch;
use App\Models\ProductMovement;
use App\Models\ProductBarcodeRelabel;
use App\Services\FloatingBarcodeRelabelService;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductBarcodeController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * Scan a barcode and get complete product information
     * This is the core endpoint for barcode scanning
     * 
     * POST /api/barcodes/scan
     * Body: {
     *   "barcode": "123456789012"
     * }
     */
    public function scan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $scanResult = ProductBarcode::scanBarcode($request->barcode);

        if (!$scanResult['found']) {
            return response()->json([
                'success' => false,
                'message' => $scanResult['message']
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'barcode_id' => $scanResult['barcode']->id,
                'barcode' => $scanResult['barcode']->barcode,
                'barcode_type' => $scanResult['barcode']->type,
                'is_defective' => $scanResult['barcode']->is_defective,
                'product' => [
                    'id' => $scanResult['product']->id,
                    'name' => $scanResult['product']->name,
                    'sku' => $scanResult['product']->sku,
                    'description' => $scanResult['product']->description,
                    'category' => $scanResult['product']->category ? [
                        'id' => $scanResult['product']->category->id,
                        'name' => $scanResult['product']->category->name,
                    ] : null,
                    'vendor' => $scanResult['product']->vendor ? [
                        'id' => $scanResult['product']->vendor->id,
                        'name' => $scanResult['product']->vendor->name,
                    ] : null,
                ],
                'current_location' => $scanResult['current_location'] ? [
                    'id' => $scanResult['current_location']->id,
                    'name' => $scanResult['current_location']->name,
                    'address' => $scanResult['current_location']->address,
                ] : null,
                'current_batch' => $scanResult['current_batch'] ? [
                    'id' => $scanResult['current_batch']->id,
                    'batch_number' => $scanResult['current_batch']->batch_number,
                    'quantity' => $scanResult['current_batch']->quantity,
                    'cost_price' => number_format((float)$scanResult['current_batch']->cost_price, 2),
                    'sell_price' => number_format((float)$scanResult['current_batch']->sell_price, 2),
                    'status' => $scanResult['current_batch']->status,
                    'expiry_date' => $scanResult['current_batch']->expiry_date ? date('Y-m-d', strtotime($scanResult['current_batch']->expiry_date)) : null,
                ] : null,
                'is_available' => $scanResult['is_available'],
                'quantity_available' => $scanResult['quantity_available'],
                'last_movement' => $scanResult['last_movement'] ? [
                    'type' => $scanResult['last_movement']->movement_type,
                    'from' => $scanResult['last_movement']->fromStore?->name,
                    'to' => $scanResult['last_movement']->toStore?->name,
                    'date' => $scanResult['last_movement']->movement_date->format('Y-m-d H:i:s'),
                    'quantity' => $scanResult['last_movement']->quantity,
                ] : null,
            ]
        ]);
    }


    /**
     * List floating replacement barcode records.
     *
     * GET /api/barcodes/relabels?batch_id=1&status=open
     */
    public function relabels(Request $request)
    {
        $query = ProductBarcodeRelabel::with([
            'product:id,name,sku',
            'batch:id,batch_number,quantity,store_id',
            'store:id,name',
            'replacementBarcode:id,barcode,current_status,is_active,is_replacement,replacement_status',
            'knownOriginalBarcode:id,barcode,current_status,is_active',
            'reconciledOriginalBarcode:id,barcode,current_status,is_active',
            'creator:id,name',
        ])->latest();

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = (int) $request->input('per_page', 20);
        $relabels = $query->paginate(max(1, min($perPage, 100)));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $relabels->items(),
                'pagination' => [
                    'current_page' => $relabels->currentPage(),
                    'per_page' => $relabels->perPage(),
                    'total' => $relabels->total(),
                    'last_page' => $relabels->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Generate a floating replacement barcode for a lost/damaged sticker.
     *
     * POST /api/barcodes/relabels
     * Body: {
     *   "batch_id": 1,
     *   "product_id": 1,        // optional; checked if provided
     *   "store_id": 1,          // optional; must match batch store if provided
     *   "barcode": "111001",   // optional; auto-generated if omitted
     *   "reason": "lost_sticker",
     *   "notes": "Sticker lost from one item"
     * }
     */
    public function createRelabel(Request $request, FloatingBarcodeRelabelService $service)
    {
        $validator = Validator::make($request->all(), [
            'batch_number' => 'required|exists:product_batches,batch_number',
            'product_id' => 'nullable|exists:products,id',
            'store_id' => 'nullable|exists:stores,id',
            'barcode' => 'nullable|string|max:80|unique:product_barcodes,barcode',
            'type' => 'nullable|string|in:CODE128,EAN13,QR',
            'reason' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:1000',
            'known_original_barcode_id' => 'nullable|exists:product_barcodes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $validated = $validator->validated();
            $batch = ProductBatch::where('batch_number', $validated['batch_number'])->first();
            $validated['batch_id'] = $batch->id;

            $relabel = $service->createReplacement(
                $validated,
                auth('api')->id() ?: auth()->id()
            );

            return response()->json([
                'success' => true,
                'message' => 'Replacement barcode generated. Stock quantity was not increased.',
                'data' => [
                    'relabel' => $relabel,
                    'replacement_barcode' => [
                        'id' => $relabel->replacementBarcode->id,
                        'barcode' => $relabel->replacementBarcode->barcode,
                        'type' => $relabel->replacementBarcode->type,
                        'product_name' => $relabel->product->name ?? null,
                        'batch_number' => $relabel->batch->batch_number ?? null,
                        'sell_price' => $relabel->batch->sell_price ?? null,
                        'batch_quantity_after_relabel' => $relabel->batch->quantity ?? null,
                        'status' => $relabel->replacementBarcode->current_status,
                        'replacement_status' => $relabel->replacementBarcode->replacement_status,
                    ],
                    'rule' => 'This barcode is a floating scan identity for one existing unit. It does not add stock.',
                ],
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create replacement barcode',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually force reconciliation for a batch after stock reaches zero.
     * Normally OrderController@complete runs this automatically.
     */
    public function reconcileRelabelBatch(Request $request, FloatingBarcodeRelabelService $service)
    {
        $validator = Validator::make($request->all(), [
            'batch_id' => 'required|exists:product_batches,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $batch = ProductBatch::findOrFail($request->batch_id);
        $result = $service->reconcilePoolAfterStockChange($batch);

        return response()->json([
            'success' => true,
            'message' => $result['reconciled'] ? 'Relabel pool reconciled.' : 'No reconciliation was needed.',
            'data' => $result,
        ]);
    }

    /**
     * Get barcode location history
     * 
     * GET /api/barcodes/{barcode}/history
     */
    public function getHistory($barcode)
    {
        $barcodeRecord = ProductBarcode::where('barcode', $barcode)->first();

        if (!$barcodeRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode not found'
            ], 404);
        }

        $history = $barcodeRecord->getLocationHistory();

        return response()->json([
            'success' => true,
            'data' => [
                'barcode' => $barcode,
                'product' => [
                    'id' => $barcodeRecord->product->id,
                    'name' => $barcodeRecord->product->name,
                    'sku' => $barcodeRecord->product->sku,
                ],
                'movement_count' => $barcodeRecord->getMovementCount(),
                'history' => $history->map(function ($movement) {
                    return [
                        'id' => $movement->id,
                        'type' => $movement->movement_type,
                        'from_store' => $movement->fromStore ? [
                            'id' => $movement->fromStore->id,
                            'name' => $movement->fromStore->name,
                        ] : null,
                        'to_store' => $movement->toStore ? [
                            'id' => $movement->toStore->id,
                            'name' => $movement->toStore->name,
                        ] : null,
                        'batch' => [
                            'id' => $movement->batch->id,
                            'batch_number' => $movement->batch->batch_number,
                        ],
                        'quantity' => $movement->quantity,
                        'date' => $movement->movement_date->format('Y-m-d H:i:s'),
                        'reference' => $movement->reference_number,
                        'notes' => $movement->notes,
                        'performed_by' => $movement->performedBy ? [
                            'id' => $movement->performedBy->id,
                            'name' => $movement->performedBy->name,
                        ] : null,
                    ];
                })
            ]
        ]);
    }

    /**
     * Get current location of a barcode
     * 
     * GET /api/barcodes/{barcode}/location
     */
    public function getCurrentLocation($barcode)
    {
        $barcodeRecord = ProductBarcode::where('barcode', $barcode)->first();

        if (!$barcodeRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode not found'
            ], 404);
        }

        $currentStore = $barcodeRecord->getCurrentStore();
        $currentBatch = $barcodeRecord->getCurrentBatch();

        return response()->json([
            'success' => true,
            'data' => [
                'barcode' => $barcode,
                'product' => [
                    'id' => $barcodeRecord->product->id,
                    'name' => $barcodeRecord->product->name,
                    'sku' => $barcodeRecord->product->sku,
                ],
                'current_location' => $currentStore ? [
                    'id' => $currentStore->id,
                    'name' => $currentStore->name,
                    'address' => $currentStore->address,
                    'phone' => $currentStore->phone,
                ] : null,
                'current_batch' => $currentBatch ? [
                    'id' => $currentBatch->id,
                    'batch_number' => $currentBatch->batch_number,
                    'quantity_available' => $currentBatch->quantity,
                    'status' => $currentBatch->status,
                ] : null,
            ]
        ]);
    }

    /**
     * List all barcodes with filters
     * 
     * GET /api/barcodes
     */
    public function index(Request $request)
    {
        $query = ProductBarcode::with(['product']);

        // Filter by product
        if ($request->filled('product_id')) {
            $query->byProduct($request->product_id);
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->byType($request->type);
        }

        // Filter by status
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('is_primary')) {
            $query->where('is_primary', $request->boolean('is_primary'));
        }

        // Search by barcode
        if ($request->filled('search')) {
            $this->whereLike($query, 'barcode', $request->search);
        }

        $barcodes = $query->paginate($request->input('per_page', 20));

        $formattedBarcodes = [];
        foreach ($barcodes as $barcodeRecord) {
            $formattedBarcodes[] = [
                'id' => $barcodeRecord->id,
                'barcode' => $barcodeRecord->barcode,
                'type' => $barcodeRecord->type,
                'is_primary' => $barcodeRecord->is_primary,
                'is_active' => $barcodeRecord->is_active,
                'current_status' => $barcodeRecord->current_status,
                'is_replacement' => (bool) $barcodeRecord->is_replacement,
                'replacement_status' => $barcodeRecord->replacement_status,
                'relabel_reason' => $barcodeRecord->relabel_reason,
                'product' => [
                    'id' => $barcodeRecord->product->id,
                    'name' => $barcodeRecord->product->name,
                    'sku' => $barcodeRecord->product->sku,
                ],
                'current_location' => $barcodeRecord->getCurrentStore()?->name,
                'movement_count' => $barcodeRecord->getMovementCount(),
                'generated_at' => $barcodeRecord->generated_at?->format('Y-m-d H:i:s'),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $barcodes->currentPage(),
                'data' => $formattedBarcodes,
                'first_page_url' => $barcodes->url(1),
                'from' => $barcodes->firstItem(),
                'last_page' => $barcodes->lastPage(),
                'last_page_url' => $barcodes->url($barcodes->lastPage()),
                'next_page_url' => $barcodes->nextPageUrl(),
                'path' => $barcodes->path(),
                'per_page' => $barcodes->perPage(),
                'prev_page_url' => $barcodes->previousPageUrl(),
                'to' => $barcodes->lastItem(),
                'total' => $barcodes->total(),
            ]
        ]);
    }

    /**
     * Generate new barcode for a product
     * 
     * POST /api/barcodes/generate
     * Body: {
     *   "product_id": 1,
     *   "type": "CODE128",
     *   "make_primary": false,
     *   "quantity": 1  // Number of barcodes to generate
     * }
     */
    public function generate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'type' => 'string|in:CODE128,EAN13,QR',
            'make_primary' => 'boolean',
            'quantity' => 'integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $product = Product::find($request->product_id);
        $type = $request->input('type', 'CODE128');
        $makePrimary = $request->input('make_primary', false);
        $quantity = $request->input('quantity', 1);

        $barcodes = [];
        for ($i = 0; $i < $quantity; $i++) {
            $barcode = ProductBarcode::createForProduct(
                $product,
                $type,
                $makePrimary && $i === 0
            );
            $barcodes[] = $barcode;
        }

        return response()->json([
            'success' => true,
            'message' => count($barcodes) . ' barcode(s) generated successfully',
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ],
                'barcodes' => array_map(function ($barcode) {
                    return [
                        'id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'type' => $barcode->type,
                        'is_primary' => $barcode->is_primary,
                        'formatted' => $barcode->formatted_barcode,
                    ];
                }, $barcodes)
            ]
        ], 201);
    }

    /**
     * Get barcodes for a specific product
     * 
     * GET /api/products/{productId}/barcodes
     */
    public function getProductBarcodes($productId)
    {
        $product = Product::find($productId);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $barcodes = ProductBarcode::getBarcodesForProduct($productId, false);

        return response()->json([
            'success' => true,
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ],
                'barcode_count' => $barcodes->count(),
                'barcodes' => $barcodes->map(function ($barcode) {
                    return [
                        'id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'type' => $barcode->type,
                        'is_primary' => $barcode->is_primary,
                        'is_active' => $barcode->is_active,
                        'current_status' => $barcode->current_status,
                        'is_replacement' => (bool) $barcode->is_replacement,
                        'replacement_status' => $barcode->replacement_status,
                        'relabel_reason' => $barcode->relabel_reason,
                        'current_location' => $barcode->getCurrentStore()?->name,
                        'movement_count' => $barcode->getMovementCount(),
                        'generated_at' => $barcode->generated_at?->format('Y-m-d H:i:s'),
                    ];
                })
            ]
        ]);
    }

    /**
     * Make a barcode primary for its product
     * 
     * PATCH /api/barcodes/{id}/make-primary
     */
    public function makePrimary($id)
    {
        $barcode = ProductBarcode::find($id);

        if (!$barcode) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode not found'
            ], 404);
        }

        $barcode->makePrimary();

        return response()->json([
            'success' => true,
            'message' => 'Barcode set as primary',
            'data' => [
                'barcode' => $barcode->barcode,
                'product_id' => $barcode->product_id,
                'is_primary' => $barcode->is_primary,
            ]
        ]);
    }

    /**
     * Deactivate a barcode
     * 
     * DELETE /api/barcodes/{id}
     */
    public function deactivate($id)
    {
        $barcode = ProductBarcode::find($id);

        if (!$barcode) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode not found'
            ], 404);
        }

        // Prevent deactivating if it's the only barcode for the product
        $otherActiveBarcodes = ProductBarcode::byProduct($barcode->product_id)
            ->where('id', '!=', $id)
            ->where('is_active', true)
            ->count();

        if ($otherActiveBarcodes === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate the only active barcode for this product'
            ], 422);
        }

        $barcode->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Barcode deactivated successfully'
        ]);
    }

    /**
     * Batch scan multiple barcodes
     * Useful for inventory verification
     * 
     * POST /api/barcodes/batch-scan
     * Body: {
     *   "barcodes": ["123", "456", "789"]
     * }
     */
    public function batchScan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcodes' => 'required|array|min:1',
            'barcodes.*' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $results = [];
        $found = 0;
        $notFound = 0;

        foreach ($request->barcodes as $barcode) {
            $scanResult = ProductBarcode::scanBarcode($barcode);
            
            if ($scanResult['found']) {
                $found++;
                $results[] = [
                    'barcode' => $barcode,
                    'found' => true,
                    'product_name' => $scanResult['product']->name,
                    'current_location' => $scanResult['current_location']?->name,
                    'quantity_available' => $scanResult['quantity_available'],
                ];
            } else {
                $notFound++;
                $results[] = [
                    'barcode' => $barcode,
                    'found' => false,
                    'message' => $scanResult['message'],
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_scanned' => count($request->barcodes),
                'found' => $found,
                'not_found' => $notFound,
                'results' => $results,
            ]
        ]);
    }
}
