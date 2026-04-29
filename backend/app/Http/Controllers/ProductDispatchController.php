<?php

namespace App\Http\Controllers;

use App\Models\ProductDispatch;
use App\Models\ProductDispatchItem;
use App\Models\ProductBatch;
use App\Models\Store;
use App\Models\Employee;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductDispatchController extends Controller
{
    use DatabaseAgnosticSearch;

    /**
     * Treat these statuses as "received/available at destination".
     * Your system uses multiple "available-ish" statuses.
     */
    private function receivedStatuses(): array
    {
        return ['available', 'in_warehouse', 'in_shop', 'on_display'];
    }

    /**
     * Ground-truth received count:
     * Count scanned barcodes that are currently at destination store
     * AND in received/available statuses.
     */
    private function countReceivedAtDestination(ProductDispatch $dispatch, ProductDispatchItem $item): int
    {
        return $item->scannedBarcodes()
            ->where('product_barcodes.current_store_id', $dispatch->destination_store_id)
            ->whereIn('product_barcodes.current_status', $this->receivedStatuses())
            ->count();
    }

    /**
     * List all dispatches with filters
     *
     * GET /api/dispatches
     */
    public function index(Request $request)
    {
        $query = ProductDispatch::with([
            'sourceStore',
            'destinationStore',
            'createdBy',
            'approvedBy',
            'items.batch.product'
        ]);

        // Filter by status
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'pending':
                    $query->pending();
                    break;
                case 'in_transit':
                    $query->inTransit();
                    break;
                case 'delivered':
                    $query->delivered();
                    break;
                case 'cancelled':
                    $query->cancelled();
                    break;
                case 'overdue':
                    $query->overdue();
                    break;
                case 'expected_today':
                    $query->expectedToday();
                    break;
            }
        }

        // Filter by store
        if ($request->filled('source_store_id')) {
            $query->bySourceStore($request->source_store_id);
        }

        if ($request->filled('destination_store_id')) {
            $query->byDestinationStore($request->destination_store_id);
        }

        // Search by dispatch number
        if ($request->filled('search')) {
            $this->whereLike($query, 'dispatch_number', $request->search);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->where('dispatch_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('dispatch_date', '<=', $request->date_to);
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $dispatches = $query->paginate($request->input('per_page', 20));

        $formattedDispatches = [];
        foreach ($dispatches as $dispatch) {
            $formattedDispatches[] = $this->formatDispatchResponse($dispatch);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $dispatches->currentPage(),
                'data' => $formattedDispatches,
                'first_page_url' => $dispatches->url(1),
                'from' => $dispatches->firstItem(),
                'last_page' => $dispatches->lastPage(),
                'last_page_url' => $dispatches->url($dispatches->lastPage()),
                'next_page_url' => $dispatches->nextPageUrl(),
                'path' => $dispatches->path(),
                'per_page' => $dispatches->perPage(),
                'prev_page_url' => $dispatches->previousPageUrl(),
                'to' => $dispatches->lastItem(),
                'total' => $dispatches->total(),
            ]
        ]);
    }

    /**
     * Get specific dispatch details
     *
     * GET /api/dispatches/{id}
     */
    public function show($id)
    {
        $dispatch = ProductDispatch::with([
            'sourceStore',
            'destinationStore',
            'createdBy',
            'approvedBy',
            'items.batch.product',
            'items.batch.barcode'
        ])->find($id);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatDispatchResponse($dispatch, true)
        ]);
    }

    /**
     * Create new dispatch
     *
     * POST /api/dispatches
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source_store_id' => 'required|exists:stores,id',
            'destination_store_id' => 'required|exists:stores,id|different:source_store_id',
            'expected_delivery_date' => 'nullable|date|after_or_equal:today',
            'carrier_name' => 'nullable|string',
            'tracking_number' => 'nullable|string',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.batch_id' => 'required|exists:product_batches,id',
            'items.*.quantity' => 'required|integer|min:1',
            'draft_scan_history' => 'nullable|array',
            'draft_scan_history.*.barcode' => 'required|string',
            'draft_scan_history.*.batch_id' => 'required|exists:product_batches,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // --- IDEMPOTENCY CHECK ---
        // Check for duplicate dispatch created by same user in last 10 seconds with same source/destination
        $recentDispatch = ProductDispatch::where('created_by', Auth::id())
            ->where('source_store_id', $request->source_store_id)
            ->where('destination_store_id', $request->destination_store_id)
            ->where('created_at', '>=', now()->subSeconds(10))
            ->first();

        if ($recentDispatch) {
            // Further check if items match
            $recentItems = $recentDispatch->items()->pluck('quantity', 'product_batch_id')->toArray();
            $requestItems = [];
            foreach ($request->items as $item) {
                $requestItems[(int)$item['batch_id']] = (int)$item['quantity'];
            }

            if ($recentItems === $requestItems) {
                return response()->json([
                    'success' => true,
                    'message' => 'Dispatch already created recently (idempotency check)',
                    'data' => $this->formatDispatchResponse($recentDispatch->fresh([
                        'sourceStore',
                        'destinationStore',
                        'createdBy',
                        'items.batch.product'
                    ]), true)
                ], 200); // 200 OK since it already exists
            }
        }
        // -------------------------

        DB::beginTransaction();
        try {
            // 1. Create the Dispatch record
            $dispatch = ProductDispatch::create([
                'source_store_id' => $request->source_store_id,
                'destination_store_id' => $request->destination_store_id,
                'status' => 'pending',
                'expected_delivery_date' => $request->expected_delivery_date,
                'carrier_name' => $request->carrier_name,
                'tracking_number' => $request->tracking_number,
                'notes' => $request->notes,
                'created_by' => Auth::id(),
            ]);

            $batchToItemId = [];

            // 2. Add Items
            foreach ($request->items as $itemData) {
                $batch = ProductBatch::findOrFail($itemData['batch_id']);
                
                $barcodeAtSourceStore = $batch->barcodes()
    ->where('current_store_id', (int)$request->source_store_id)
    ->where('is_active', true)
    ->exists();

if (!$barcodeAtSourceStore) {
    throw new \Exception("Batch {$batch->batch_number} does not belong to the source store.");
}

                if ($batch->quantity < $itemData['quantity']) {
                    throw new \Exception("Insufficient quantity in batch {$batch->batch_number}. Available: {$batch->quantity}");
                }

                $item = $dispatch->addItem($batch, $itemData['quantity']);
                $batchToItemId[(string)$batch->id] = $item->id;
            }

            // 3. Process Draft Scan History (if provided)
            if ($request->filled('draft_scan_history')) {
                foreach ($request->draft_scan_history as $scan) {
                    $itemId = $batchToItemId[(string)$scan['batch_id']] ?? null;
                    if (!$itemId) continue;

                    // Reuse the existing scanBarcode logic (internal call or refactored)
                    // For simplicity here, we'll manually handle it or call the method.
                    $this->scanBarcodeAndAttach($dispatch, $itemId, $scan['barcode']);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dispatch created successfully',
                'data' => $this->formatDispatchResponse($dispatch->fresh([
                    'sourceStore',
                    'destinationStore',
                    'createdBy',
                    'items.batch.product'
                ]), true)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Scan barcode and add as new item if not exists, then attach barcode.
     *
     * POST /api/dispatches/{id}/scan-to-add
     */
    public function scanAndAddItem(Request $request, $id)
    {
        $dispatch = ProductDispatch::find($id);
        if (!$dispatch) {
            return response()->json(['success' => false, 'message' => 'Dispatch not found'], 404);
        }

        if (!in_array($dispatch->status, ['pending', 'pending_approval', 'approved'])) {
            return response()->json(['success' => false, 'message' => 'Cannot add items to this dispatch in its current status'], 422);
        }

        $validator = Validator::make($request->all(), [
            'barcode' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $barcode = \App\Models\ProductBarcode::where('barcode', $request->barcode)->first();
        if (!$barcode) {
            return response()->json(['success' => false, 'message' => 'Barcode not found'], 404);
        }

        // Validate barcode is at the source store
        if ((int)$barcode->current_store_id !== (int)$dispatch->source_store_id) {
            return response()->json(['success' => false, 'message' => 'Barcode is not currently at the source store'], 422);
        }

        // Find or Create Dispatch Item for this batch
        $batch = $barcode->batch;
        if (!$batch) {
            return response()->json(['success' => false, 'message' => 'Barcode is not linked to any batch'], 422);
        }

        DB::beginTransaction();
        try {
            $item = $dispatch->items()->where('product_batch_id', $batch->id)->first();
            
            if (!$item) {
                // Add new item with quantity 1
                $item = $dispatch->addItem($batch, 1);
            } else {
                // If it exists, we might need to increase the planned quantity if staff scans more than planned.
                // However, for "scanning" we usually assume they are scanning what's already planned.
                // If they scan more than planned, we increment planned quantity.
                $scannedCount = $item->scannedBarcodes()->count();
                if ($scannedCount >= $item->quantity) {
                    $item->update(['quantity' => $item->quantity + 1]);
                    $dispatch->updateTotals();
                }
            }

            // Now perform the standard scan logic (attaching barcode)
            // We can literally just call our existing scanBarcode logic if we refactor it or just copy the guts.
            
            // Check if already scanned for this dispatch item
            $alreadyScanned = $item->scannedBarcodes()->where('product_barcode_id', $barcode->id)->exists();
            if ($alreadyScanned) {
                throw new \Exception('This barcode has already been scanned for this item');
            }

            $item->scannedBarcodes()->attach($barcode->id, [
                'scanned_at' => now(),
                'scanned_by' => auth()->id(),
            ]);

            $statusAfter = $dispatch->status === 'in_transit' ? 'in_transit' : 'in_shipment';
            if (method_exists($barcode, 'updateLocation')) {
                $barcode->updateLocation($dispatch->source_store_id, $statusAfter, [
                    'dispatch_id' => $dispatch->id,
                    'reference_type' => 'dispatch',
                    'reference_id' => $dispatch->id,
                    'scanned_for_dispatch' => true,
                    'scanned_at' => now()->toISOString(),
                    'scanned_by' => auth()->id(),
                ], true);
            } else {
                $barcode->update([
                    'current_status' => $statusAfter,
                    'location_updated_at' => now(),
                    'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                        'dispatch_id' => $dispatch->id,
                        'reference_type' => 'dispatch',
                        'reference_id' => $dispatch->id,
                        'scanned_for_dispatch' => true,
                        'scanned_at' => now()->toISOString(),
                        'scanned_by' => auth()->id(),
                    ])
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Barcode added and scanned successfully',
                'data' => [
                    'dispatch_item_id' => $item->id,
                    'scanned_count' => $item->scannedBarcodes()->count(),
                    'required_quantity' => (int)$item->quantity,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }


    /**
     * Internal helper to attach barcode to dispatch item during creation
     */
    private function scanBarcodeAndAttach(ProductDispatch $dispatch, $itemId, $barcodeText)
    {
        $barcode = \App\Models\ProductBarcode::where('barcode', $barcodeText)->first();
        if (!$barcode) return;

        $item = ProductDispatchItem::find($itemId);
        if (!$item) return;

        // Basic validation (same as scanBarcode method but without returning HTTP responses)
        if ((int)$barcode->product_id !== (int)$item->batch->product_id) return;
        if ((int)$barcode->current_store_id !== (int)$dispatch->source_store_id) return;

        // Attach
        $item->scannedBarcodes()->attach($barcode->id, [
            'scanned_at' => now(),
            'scanned_by' => Auth::id(),
        ]);

        // Status
        $statusAfter = $dispatch->status === 'in_transit' ? 'in_transit' : 'in_shipment';
        
        $barcode->update([
            'current_status' => $statusAfter,
            'location_updated_at' => now(),
            'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                'dispatch_id' => $dispatch->id,
                'dispatch_number' => $dispatch->dispatch_number,
                'reference_type' => 'dispatch',
                'reference_id' => $dispatch->id,
                'scanned_for_dispatch' => true,
                'scanned_at' => now()->toISOString(),
                'scanned_by' => Auth::id(),
            ])
        ]);
    }

    /**
     * Add item to dispatch
     *
     * POST /api/dispatches/{id}/items
     */
    public function addItem(Request $request, $id)
    {
        $dispatch = ProductDispatch::find($id);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        if (!$dispatch->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Can only add items to pending dispatches'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'batch_id' => 'required|exists:product_batches,id',
            'quantity' => 'required|integer|min:1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $batch = ProductBatch::find($request->batch_id);

            // Validate batch belongs to source store
            if ($batch->store_id !== $dispatch->source_store_id) {
                throw new \Exception('Batch does not belong to the source store');
            }

            // Validate sufficient quantity
            if ($batch->quantity < $request->quantity) {
                throw new \Exception('Insufficient quantity in batch. Available: ' . $batch->quantity);
            }

            // Add the item
            $item = $dispatch->addItem($batch, $request->quantity);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Item added to dispatch successfully',
                'data' => [
                    'dispatch_item' => [
                        'id' => $item->id,
                        'product' => [
                            'id' => $batch->product->id,
                            'name' => $batch->product->name,
                            'sku' => $batch->product->sku,
                        ],
                        'batch_number' => $batch->batch_number,
                        'quantity' => $item->quantity,
                        'unit_cost' => number_format((float)$item->unit_cost, 2),
                        'unit_price' => number_format((float)$item->unit_price, 2),
                        'total_cost' => number_format((float)$item->total_cost, 2),
                        'total_value' => number_format((float)$item->total_value, 2),
                    ],
                    'dispatch_totals' => [
                        'total_items' => $dispatch->fresh()->total_items,
                        'total_cost' => number_format((float)$dispatch->fresh()->total_cost, 2),
                        'total_value' => number_format((float)$dispatch->fresh()->total_value, 2),
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Remove item from dispatch
     *
     * DELETE /api/dispatches/{dispatchId}/items/{itemId}
     */
    public function removeItem($dispatchId, $itemId)
    {
        $dispatch = ProductDispatch::find($dispatchId);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        if (!$dispatch->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Can only remove items from pending dispatches'
            ], 422);
        }

        $item = ProductDispatchItem::find($itemId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch item not found'
            ], 404);
        }

        DB::beginTransaction();
        try {
            $dispatch->removeItem($item);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Item removed from dispatch successfully',
                'data' => [
                    'dispatch_totals' => [
                        'total_items' => $dispatch->fresh()->total_items,
                        'total_cost' => number_format((float)$dispatch->fresh()->total_cost, 2),
                        'total_value' => number_format((float)$dispatch->fresh()->total_value, 2),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Approve a dispatch
     *
     * PATCH /api/dispatches/{id}/approve
     */
    public function approve($id)
    {
        $dispatch = ProductDispatch::find($id);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        if (!$dispatch->canBeApproved()) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch cannot be approved in its current state'
            ], 422);
        }

        // Check if dispatch has items
        if ($dispatch->items()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot approve dispatch without items'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $employee = Employee::find(Auth::id());
            $dispatch->approve($employee);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dispatch approved successfully',
                'data' => $this->formatDispatchResponse($dispatch->fresh([
                    'sourceStore',
                    'destinationStore',
                    'createdBy',
                    'approvedBy',
                    'items.batch.product'
                ]), true)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Mark dispatch as in transit (dispatched)
     *
     * PATCH /api/dispatches/{id}/dispatch
     */
    public function markDispatched($id)
    {
        $dispatch = ProductDispatch::find($id);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        if (!$dispatch->canBeDispatched()) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch cannot be sent in its current state. Ensure it is approved first.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $dispatch->dispatch();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dispatch marked as in transit successfully',
                'data' => $this->formatDispatchResponse($dispatch->fresh([
                    'sourceStore',
                    'destinationStore',
                    'createdBy',
                    'approvedBy',
                    'items.batch.product'
                ]), true)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Scan barcode for a dispatch item at SOURCE STORE
     * This should be done BEFORE marking dispatch as in_transit (sending)
     *
     * POST /api/dispatches/{id}/items/{itemId}/scan-barcode
     * Body: { "barcode": "8801234567890" }
     */
    public function scanBarcode(Request $request, $dispatchId, $itemId)
    {
        $dispatch = ProductDispatch::find($dispatchId);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        // Can scan barcodes when dispatch is pending (before sending) or in_transit
        if (!in_array($dispatch->status, ['pending', 'in_transit'])) {
            return response()->json([
                'success' => false,
                'message' => 'Barcodes can only be scanned for pending or in-transit dispatches'
            ], 422);
        }

        $item = ProductDispatchItem::where('id', $itemId)
            ->where('product_dispatch_id', $dispatchId)
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch item not found'
            ], 404);
        }

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

        $barcode = \App\Models\ProductBarcode::where('barcode', $request->barcode)->first();

        if (!$barcode) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode not found in system'
            ], 404);
        }

        if (!$barcode->is_active || $barcode->is_defective) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode is not active or is defective'
            ], 422);
        }

        // Validate barcode is for the correct product
        if ((int)$barcode->product_id !== (int)$item->batch->product_id) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode does not match the product for this dispatch item'
            ], 422);
        }

        // Validate barcode is at the source store
        if ((int)$barcode->current_store_id !== (int)$dispatch->source_store_id) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode is not currently at the source store'
            ], 422);
        }

        // Validate barcode status is dispatchable
        $dispatchableStatuses = ['in_shop', 'on_display', 'in_warehouse', 'available'];
        $isDispatchable = in_array($barcode->current_status, $dispatchableStatuses, true);

        // Allow re-scanning if already reserved for THIS dispatch
        if (!$isDispatchable && $barcode->current_status === 'in_shipment') {
            $meta = $barcode->location_metadata ?? [];
            $isDispatchable = isset($meta['dispatch_id']) && (int)$meta['dispatch_id'] === (int)$dispatch->id;
        }

        if (!$isDispatchable) {
            return response()->json([
                'success' => false,
                'message' => "Barcode is not available for dispatch. Current status: {$barcode->current_status}"
            ], 422);
        }

        // Check if already scanned for this dispatch item
        $alreadyScanned = $item->scannedBarcodes()
            ->where('product_barcode_id', $barcode->id)
            ->exists();

        if ($alreadyScanned) {
            return response()->json([
                'success' => false,
                'message' => 'This barcode has already been scanned for this item'
            ], 422);
        }

        // Check if we've reached the required quantity
        $currentScannedCount = $item->scannedBarcodes()->count();
        if ($currentScannedCount >= (int)$item->quantity) {
            return response()->json([
                'success' => false,
                'message' => "All required barcodes have already been scanned ({$item->quantity} of {$item->quantity})"
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Attach barcode to dispatch item
            $item->scannedBarcodes()->attach($barcode->id, [
                'scanned_at' => now(),
                'scanned_by' => auth()->id(),
            ]);

            // Mark barcode as in_shipment (reserved) for pending dispatch, or in_transit if already in_transit.
            $statusAfter = $dispatch->status === 'in_transit' ? 'in_transit' : 'in_shipment';

            // Prefer using updateLocation if present; fall back if it fails (prevents movement logging issues from blocking scans)
            if (method_exists($barcode, 'updateLocation')) {
                try {
                    $barcode->updateLocation(
                        $dispatch->source_store_id,
                        $statusAfter,
                        [
                            'dispatch_id' => $dispatch->id,
                            'dispatch_number' => $dispatch->dispatch_number,
                            'destination_store_id' => $dispatch->destination_store_id,
                            'reference_type' => 'dispatch',
                            'reference_id' => $dispatch->id,
                            'scanned_for_dispatch' => true,
                            'scanned_at' => now()->toISOString(),
                            'scanned_by' => auth()->id(),
                        ],
                        true
                    );
                } catch (\Throwable $t) {
                    $barcode->update([
                        'current_status' => $statusAfter,
                        'location_updated_at' => now(),
                        'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                            'dispatch_id' => $dispatch->id,
                            'dispatch_number' => $dispatch->dispatch_number,
                            'destination_store_id' => $dispatch->destination_store_id,
                            'reference_type' => 'dispatch',
                            'reference_id' => $dispatch->id,
                            'scanned_for_dispatch' => true,
                            'scanned_at' => now()->toISOString(),
                            'scanned_by' => auth()->id(),
                            'updateLocation_error' => $t->getMessage(),
                        ])
                    ]);
                }
            } else {
                $barcode->update([
                    'current_status' => $statusAfter,
                    'location_updated_at' => now(),
                    'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                        'dispatch_id' => $dispatch->id,
                        'dispatch_number' => $dispatch->dispatch_number,
                        'destination_store_id' => $dispatch->destination_store_id,
                        'reference_type' => 'dispatch',
                        'reference_id' => $dispatch->id,
                        'scanned_for_dispatch' => true,
                        'scanned_at' => now()->toISOString(),
                        'scanned_by' => auth()->id(),
                    ])
                ]);
            }

            DB::commit();

            $scannedCount = $item->scannedBarcodes()->count();
            $remainingCount = (int)$item->quantity - $scannedCount;

            return response()->json([
                'success' => true,
                'message' => "Barcode scanned successfully. {$scannedCount} of {$item->quantity} items scanned.",
                'data' => [
                    'barcode' => $barcode->barcode,
                    'scanned_count' => $scannedCount,
                    'required_quantity' => (int)$item->quantity,
                    'remaining_count' => $remainingCount,
                    'all_scanned' => $remainingCount === 0,
                    'scanned_at' => now()->toISOString(),
                    'scanned_by' => auth()->user()->name ?? 'Unknown'
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get scanned barcodes for a dispatch item
     *
     * GET /api/dispatches/{id}/items/{itemId}/scanned-barcodes
     */
    public function getScannedBarcodes($dispatchId, $itemId)
    {
        $dispatch = ProductDispatch::find($dispatchId);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        $item = ProductDispatchItem::where('id', $itemId)
            ->where('product_dispatch_id', $dispatchId)
            ->with(['scannedBarcodes.product', 'scannedBarcodes.currentStore'])
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch item not found'
            ], 404);
        }

        $scannedBarcodes = $item->scannedBarcodes->map(function ($barcode) {
            return [
                'id' => $barcode->id,
                'barcode' => $barcode->barcode,
                'product' => [
                    'id' => $barcode->product->id,
                    'name' => $barcode->product->name,
                ],
                'current_store' => [
                    'id' => $barcode->currentStore->id ?? null,
                    'name' => $barcode->currentStore->name ?? 'Unknown',
                ],
                'scanned_at' => $barcode->pivot->scanned_at,
                'scanned_by' => $barcode->pivot->scannedByEmployee->name ?? 'Unknown',
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'dispatch_item_id' => $item->id,
                'required_quantity' => $item->quantity,
                'scanned_count' => $scannedBarcodes->count(),
                'remaining_count' => $item->quantity - $scannedBarcodes->count(),
                'scanned_barcodes' => $scannedBarcodes
            ]
        ]);
    }

    /**
     * Scan barcode when RECEIVING dispatch at destination store
     *
     * POST /api/dispatches/{dispatchId}/items/{itemId}/receive-barcode
     * Body: { "barcode": "BRC-12345" }
     */
    public function receiveBarcode(Request $request, $dispatchId, $itemId)
    {
        $dispatch = ProductDispatch::find($dispatchId);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        // Can only receive barcodes when dispatch is in_transit
        if ($dispatch->status !== 'in_transit') {
            return response()->json([
                'success' => false,
                'message' => 'Barcodes can only be received when dispatch is in transit'
            ], 422);
        }

        $item = ProductDispatchItem::where('id', $itemId)
            ->where('product_dispatch_id', $dispatchId)
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch item not found'
            ], 404);
        }

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

        // Find the barcode
        $barcode = \App\Models\ProductBarcode::where('barcode', $request->barcode)->first();

        if (!$barcode) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode not found in system'
            ], 404);
        }

        // Validate barcode is for the correct product
        if ((int)$barcode->product_id !== (int)$item->batch->product_id) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode does not match the product for this dispatch item'
            ], 422);
        }

        // Check if this barcode was actually sent in this dispatch
        $wasSentInDispatch = $item->scannedBarcodes()->where('product_barcode_id', $barcode->id)->exists();
        if (!$wasSentInDispatch) {
            return response()->json([
                'success' => false,
                'message' => 'This barcode was not sent in this dispatch'
            ], 422);
        }

        // Check if already received (broader than just "available")
        if (
            (int)$barcode->current_store_id === (int)$dispatch->destination_store_id &&
            in_array($barcode->current_status, $this->receivedStatuses(), true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This barcode has already been received at destination'
            ], 422);
        }

        DB::beginTransaction();
        try {

            // Update barcode location and status to received
            // Prefer updateLocation if available, but do NOT let movement logging failures block receiving
            if (method_exists($barcode, 'updateLocation')) {
                try {
                    $barcode->updateLocation(
                        $dispatch->destination_store_id,
                        'available',
                        [
                            'received_at' => now()->toISOString(),
                            'received_by' => auth()->id(),
                            'dispatch_number' => $dispatch->dispatch_number,
                            'from_store_id' => $dispatch->source_store_id,
                            'reference_type' => 'dispatch_receive',
                            'reference_id' => $dispatch->id,
                        ],
                        true
                    );
                } catch (\Throwable $t) {
                    $barcode->update([
                        'current_status' => 'available',
                        'current_store_id' => $dispatch->destination_store_id,
                        'location_updated_at' => now(),
                        'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                            'received_at' => now()->toISOString(),
                            'received_by' => auth()->id(),
                            'dispatch_number' => $dispatch->dispatch_number,
                            'from_store_id' => $dispatch->source_store_id,
                            'reference_type' => 'dispatch_receive',
                            'reference_id' => $dispatch->id,
                            'updateLocation_error' => $t->getMessage(),
                        ])
                    ]);
                }
            } else {
                $barcode->update([
                    'current_status' => 'available',
                    'current_store_id' => $dispatch->destination_store_id,
                    'location_updated_at' => now(),
                    'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                        'received_at' => now()->toISOString(),
                        'received_by' => auth()->id(),
                        'dispatch_number' => $dispatch->dispatch_number,
                        'from_store_id' => $dispatch->source_store_id,
                        'reference_type' => 'dispatch_receive',
                        'reference_id' => $dispatch->id,
                    ])
                ]);
            }

            // ✅ IMPORTANT: sync received_quantity from barcode truth (NOT increment)
            $receivedCount = $this->countReceivedAtDestination($dispatch, $item);
            ProductDispatchItem::where('id', $item->id)->update([
                'received_quantity' => $receivedCount
            ]);

            DB::commit();

            // Get counts
            $totalSentCount = $item->scannedBarcodes()->count();
            $remainingCount = $totalSentCount - $receivedCount;

            return response()->json([
                'success' => true,
                'message' => "Barcode received successfully. {$receivedCount} of {$totalSentCount} items received.",
                'data' => [
                    'barcode' => $barcode->barcode,
                    'received_count' => $receivedCount,
                    'total_sent' => $totalSentCount,
                    'remaining_count' => $remainingCount,
                    'all_received' => $remainingCount === 0,
                    'received_at' => now()->toISOString(),
                    'received_by' => auth()->user()->name ?? 'Unknown',
                    'current_store' => [
                        'id' => $dispatch->destinationStore->id,
                        'name' => $dispatch->destinationStore->name,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get received barcodes count and list
     *
     * GET /api/dispatches/{dispatchId}/items/{itemId}/received-barcodes
     */
    public function getReceivedBarcodes($dispatchId, $itemId)
    {
        $dispatch = ProductDispatch::find($dispatchId);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        $item = ProductDispatchItem::where('id', $itemId)
            ->where('product_dispatch_id', $dispatchId)
            ->with(['scannedBarcodes'])
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch item not found'
            ], 404);
        }

        // Get all barcodes that were sent
        $sentBarcodes = $item->scannedBarcodes;

        // ✅ Received = at destination + receivedStatuses()
        $receivedBarcodes = $sentBarcodes->filter(function ($barcode) use ($dispatch) {
            return (int)$barcode->current_store_id === (int)$dispatch->destination_store_id
                && in_array($barcode->current_status, $this->receivedStatuses(), true);
        });

        $receivedBarcodesData = $receivedBarcodes->map(function ($barcode) {
            $metadata = $barcode->location_metadata ?? [];
            return [
                'id' => $barcode->id,
                'barcode' => $barcode->barcode,
                'received_at' => $metadata['received_at'] ?? null,
                'received_by_id' => $metadata['received_by'] ?? null,
                'current_store' => [
                    'id' => $barcode->currentStore->id ?? null,
                    'name' => $barcode->currentStore->name ?? 'Unknown',
                ],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'dispatch_item_id' => $item->id,
                'total_sent' => $sentBarcodes->count(),
                'received_count' => $receivedBarcodes->count(),
                'pending_count' => $sentBarcodes->count() - $receivedBarcodes->count(),
                'received_barcodes' => $receivedBarcodesData,
            ]
        ]);
    }

    /**
     * Mark dispatch as delivered
     * This processes inventory movements
     *
     * PATCH /api/dispatches/{id}/deliver
     */
    public function markDelivered(Request $request, $id)
    {
        $dispatch = ProductDispatch::find($id);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        if (!$dispatch->canBeDelivered()) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch cannot be delivered in its current state'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'items' => 'array',
            'items.*.item_id' => 'required|exists:product_dispatch_items,id',
            'items.*.received_quantity' => 'required|integer|min:0',
            'items.*.damaged_quantity' => 'integer|min:0',
            'items.*.missing_quantity' => 'integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Validate that all dispatch items have required barcodes scanned at SENDING
            $itemsWithMissingBarcodes = [];
            // Validate that all dispatch items have been RECEIVED at destination
            $itemsWithPendingReceipt = [];

            foreach ($dispatch->items as $item) {
                $scannedCount = $item->scannedBarcodes()->count();

                // Check if all items were scanned at source
                if ($scannedCount < $item->quantity) {
                    $itemsWithMissingBarcodes[] = [
                        'item_id' => $item->id,
                        'product' => $item->batch->product->name,
                        'required' => $item->quantity,
                        'scanned' => $scannedCount,
                        'missing' => $item->quantity - $scannedCount
                    ];
                }

                // ✅ IMPORTANT: compute received from barcode truth (NOT received_quantity column)
                $receivedCount = $this->countReceivedAtDestination($dispatch, $item);

                // Keep DB column in sync (optional but helpful for reports/UI)
                if (($item->received_quantity ?? 0) !== $receivedCount) {
                    $item->update(['received_quantity' => $receivedCount]);
                }

                if ($receivedCount < $scannedCount) {
                    $itemsWithPendingReceipt[] = [
                        'item_id' => $item->id,
                        'product' => $item->batch->product->name,
                        'sent' => $scannedCount,
                        'received' => $receivedCount,
                        'pending' => $scannedCount - $receivedCount
                    ];
                }
            }

            if (!empty($itemsWithMissingBarcodes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot deliver dispatch: Not all barcodes have been scanned at source',
                    'items_with_missing_barcodes' => $itemsWithMissingBarcodes
                ], 422);
            }

            if (!empty($itemsWithPendingReceipt)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot deliver dispatch: Not all barcodes have been received at destination',
                    'hint' => 'Use POST /api/dispatches/' . $dispatch->id . '/items/{itemId}/receive-barcode to scan and receive items',
                    'items_with_pending_receipt' => $itemsWithPendingReceipt
                ], 422);
            }

            // Update item statuses if provided
            if ($request->filled('items')) {
                foreach ($request->items as $itemData) {
                    $item = ProductDispatchItem::find($itemData['item_id']);
                    if ($item && $item->product_dispatch_id == $dispatch->id) {
                        $item->markAsReceived(
                            $itemData['received_quantity'],
                            $itemData['damaged_quantity'] ?? 0,
                            $itemData['missing_quantity'] ?? 0
                        );
                    }
                }
            }

            // Mark as delivered and process inventory movements
            $dispatch->deliver();

            // Additional safety check: Fix any barcodes that might still be stuck in transit
            $stuckBarcodes = \App\Models\ProductBarcode::where('current_status', 'in_transit')
                ->whereHas('batch', function ($query) use ($dispatch) {
                    $batchIds = $dispatch->items->pluck('product_batch_id')->toArray();
                    $query->whereIn('id', $batchIds);
                })
                ->get();

            foreach ($stuckBarcodes as $barcode) {
                $metadata = $barcode->location_metadata ?? [];
                if (isset($metadata['dispatch_number']) && $metadata['dispatch_number'] === $dispatch->dispatch_number) {
                    // This barcode was part of this dispatch, fix its status
                    $barcode->update([
                        'current_status' => 'available',
                        'current_store_id' => $dispatch->destination_store_id,
                        'location_updated_at' => now(),
                        'location_metadata' => array_merge($metadata, [
                            'auto_fixed_at' => now()->toISOString(),
                            'fix_reason' => 'post_delivery_cleanup'
                        ])
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dispatch delivered successfully. Inventory movements have been processed and barcode statuses updated.',
                'data' => $this->formatDispatchResponse($dispatch->fresh([
                    'sourceStore',
                    'destinationStore',
                    'createdBy',
                    'approvedBy',
                    'items.batch.product'
                ]), true)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Cancel a dispatch
     *
     * PATCH /api/dispatches/{id}/cancel
     */
    public function cancel($id)
    {
        $dispatch = ProductDispatch::find($id);

        if (!$dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch not found'
            ], 404);
        }

        DB::beginTransaction();
        try {
            $dispatch->cancel();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Dispatch cancelled successfully',
                'data' => $this->formatDispatchResponse($dispatch->fresh([
                    'sourceStore',
                    'destinationStore',
                    'createdBy',
                    'approvedBy'
                ]), true)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get dispatch statistics
     *
     * GET /api/dispatches/statistics
     */
    public function getStatistics(Request $request)
    {
        $storeId = $request->input('store_id');

        $query = ProductDispatch::query();

        if ($storeId) {
            $query->where(function ($q) use ($storeId) {
                $q->where('source_store_id', $storeId)
                    ->orWhere('destination_store_id', $storeId);
            });
        }

        $stats = [
            'total_dispatches' => $query->count(),
            'pending' => (clone $query)->pending()->count(),
            'in_transit' => (clone $query)->inTransit()->count(),
            'delivered' => (clone $query)->delivered()->count(),
            'cancelled' => (clone $query)->cancelled()->count(),
            'overdue' => (clone $query)->overdue()->count(),
            'expected_today' => (clone $query)->expectedToday()->count(),
            'total_value_in_transit' => (clone $query)->inTransit()->sum('total_value'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Helper function to format dispatch response
     */
    private function formatDispatchResponse(ProductDispatch $dispatch, $detailed = false)
    {
        $response = [
            'id' => $dispatch->id,
            'dispatch_number' => $dispatch->dispatch_number,
            'status' => $dispatch->status,
            'delivery_status' => $dispatch->delivery_status,
            'source_store' => [
                'id' => $dispatch->sourceStore->id,
                'name' => $dispatch->sourceStore->name,
            ],
            'destination_store' => [
                'id' => $dispatch->destinationStore->id,
                'name' => $dispatch->destinationStore->name,
            ],
            'dispatch_date' => $dispatch->dispatch_date->format('Y-m-d H:i:s'),
            'expected_delivery_date' => $dispatch->expected_delivery_date?->format('Y-m-d'),
            'actual_delivery_date' => $dispatch->actual_delivery_date?->format('Y-m-d H:i:s'),
            'is_overdue' => $dispatch->isOverdue(),
            'carrier_name' => $dispatch->carrier_name,
            'tracking_number' => $dispatch->tracking_number,
            'total_items' => $dispatch->total_items,
            'total_cost' => number_format((float)$dispatch->total_cost, 2),
            'total_value' => number_format((float)$dispatch->total_value, 2),
            'created_by' => $dispatch->createdBy ? [
                'id' => $dispatch->createdBy->id,
                'name' => $dispatch->createdBy->name,
            ] : null,
            'approved_by' => $dispatch->approvedBy ? [
                'id' => $dispatch->approvedBy->id,
                'name' => $dispatch->approvedBy->name,
            ] : null,
            'approved_at' => $dispatch->approved_at?->format('Y-m-d H:i:s'),
            'created_at' => $dispatch->created_at->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $response['notes'] = $dispatch->notes;
            $response['metadata'] = $dispatch->metadata;
            $response['items'] = $dispatch->items->map(function ($item) {
                $itemData = [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->batch->product->id,
                        'name' => $item->batch->product->name,
                        'sku' => $item->batch->product->sku,
                    ],
                    'batch' => [
                        'id' => $item->batch->id,
                        'batch_number' => $item->batch->batch_number,
                        'barcode' => $item->batch->barcode?->barcode,
                    ],
                    'quantity' => $item->quantity,
                    'received_quantity' => $item->received_quantity,
                    'damaged_quantity' => $item->damaged_quantity,
                    'missing_quantity' => $item->missing_quantity,
                    'status' => $item->status,
                    'unit_cost' => number_format((float)$item->unit_cost, 2),
                    'unit_price' => number_format((float)$item->unit_price, 2),
                    'total_cost' => number_format((float)$item->total_cost, 2),
                    'total_value' => number_format((float)$item->total_value, 2),
                ];

                // Add barcode scanning status
                $itemData['barcode_scanning'] = [
                    'required_quantity' => $item->quantity,
                    'scanned_count' => $item->getScannedBarcodesCount(),
                    'remaining_count' => $item->getRemainingBarcodesCount(),
                    'all_scanned' => $item->hasAllBarcodesScanned(),
                    'progress_percentage' => $item->getBarcodeScanningProgress(),
                ];

                return $itemData;
            });
        }

        return $response;
    }

    /**
     * Get dispatches pending shipment creation (for Pathao delivery)
     *
     * GET /api/dispatches/pending-shipment
     */
    public function getPendingShipment(Request $request)
    {
        $query = ProductDispatch::with([
            'sourceStore',
            'destinationStore',
            'customer',
            'order',
            'items.batch.product'
        ])->pendingPathaoShipment();

        // Filter by destination store (warehouse)
        if ($request->filled('warehouse_id')) {
            $query->byDestinationStore($request->warehouse_id);
        }

        $dispatches = $query->orderBy('actual_delivery_date', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => count($dispatches) . ' dispatches pending shipment creation',
            'data' => $dispatches->map(function ($dispatch) {
                return [
                    'id' => $dispatch->id,
                    'dispatch_number' => $dispatch->dispatch_number,
                    'source_store' => $dispatch->sourceStore->name,
                    'warehouse' => $dispatch->destinationStore->name,
                    'customer' => [
                        'id' => $dispatch->customer->id,
                        'name' => $dispatch->customer->name,
                        'phone' => $dispatch->customer->phone,
                    ],
                    'order' => [
                        'id' => $dispatch->order->id,
                        'order_number' => $dispatch->order->order_number,
                        'total_amount' => $dispatch->order->total_amount,
                    ],
                    'delivery_info' => $dispatch->getCustomerDeliveryInfo(),
                    'items_count' => $dispatch->items->count(),
                    'total_value' => $dispatch->total_value,
                    'delivered_at' => $dispatch->actual_delivery_date?->format('Y-m-d H:i:s'),
                    'notes' => $dispatch->notes,
                ];
            })
        ]);
    }

    /**
     * Create shipment from dispatch
     *
     * POST /api/dispatches/{id}/create-shipment
     */
    public function createShipment($id, Request $request)
    {
        $dispatch = ProductDispatch::with(['customer', 'order', 'destinationStore'])->findOrFail($id);

        if (!$dispatch->isReadyForShipment()) {
            return response()->json([
                'success' => false,
                'message' => 'Dispatch is not ready for shipment creation. Status: ' . $dispatch->status . ', For Pathao: ' . ($dispatch->for_pathao_delivery ? 'Yes' : 'No') . ', Has Shipment: ' . ($dispatch->hasShipment() ? 'Yes' : 'No')
            ], 400);
        }

        DB::beginTransaction();
        try {
            $shipment = $dispatch->createShipmentForDelivery();

            // Optionally send to Pathao immediately
            if ($request->boolean('send_to_pathao')) {
                // Call ShipmentController's sendToPathao method
                $shipmentController = new \App\Http\Controllers\ShipmentController();
                $shipmentController->sendToPathao($shipment);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shipment created successfully' . ($request->boolean('send_to_pathao') ? ' and sent to Pathao' : ''),
                'data' => [
                    'dispatch' => $this->formatDispatchResponse($dispatch->fresh(), true),
                    'shipment' => $shipment->load(['order', 'customer', 'store'])
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create shipment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create shipments from multiple dispatches
     *
     * POST /api/dispatches/bulk-create-shipment
     */
    public function bulkCreateShipment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'dispatch_ids' => 'required|array|min:1',
            'dispatch_ids.*' => 'exists:product_dispatches,id',
            'send_to_pathao' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        $dispatches = ProductDispatch::with(['customer', 'order', 'destinationStore'])
            ->whereIn('id', $request->dispatch_ids)
            ->get();

        foreach ($dispatches as $dispatch) {
            try {
                if (!$dispatch->isReadyForShipment()) {
                    $results['failed'][] = [
                        'dispatch_id' => $dispatch->id,
                        'dispatch_number' => $dispatch->dispatch_number,
                        'reason' => 'Not ready for shipment creation'
                    ];
                    continue;
                }

                DB::beginTransaction();

                $shipment = $dispatch->createShipmentForDelivery();

                // Optionally send to Pathao
                if ($request->boolean('send_to_pathao')) {
                    $shipmentController = new \App\Http\Controllers\ShipmentController();
                    $shipmentController->sendToPathao($shipment);
                }

                DB::commit();

                $results['success'][] = [
                    'dispatch_id' => $dispatch->id,
                    'dispatch_number' => $dispatch->dispatch_number,
                    'shipment_id' => $shipment->id,
                    'shipment_number' => $shipment->shipment_number,
                    'pathao_consignment_id' => $shipment->pathao_consignment_id
                ];

            } catch (\Exception $e) {
                DB::rollBack();
                $results['failed'][] = [
                    'dispatch_id' => $dispatch->id,
                    'dispatch_number' => $dispatch->dispatch_number,
                    'reason' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => count($results['success']) . ' shipments created successfully, ' . count($results['failed']) . ' failed',
            'data' => $results
        ]);
    }
}
