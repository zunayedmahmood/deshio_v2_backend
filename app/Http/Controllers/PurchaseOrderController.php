<?php
namespace App\Http\Controllers;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Models\ProductBatch;
use App\Models\ProductBarcode;
use App\Models\MasterInventory;
class PurchaseOrderController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * Create a new purchase order
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'store_id' => 'required|exists:stores,id',
            'expected_delivery_date' => 'nullable|date|after_or_equal:today',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'terms_and_conditions' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity_ordered' => 'required|integer|min:1',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.unit_sell_price' => 'nullable|numeric|min:0',
            'items.*.tax_amount' => 'nullable|numeric|min:0',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);
        // Verify store is a warehouse
        $store = Store::findOrFail($validated['store_id']);
        if (!$store->is_warehouse) {
            return response()->json([
                'success' => false,
                'message' => 'Only warehouse can receive products from vendors'
            ], 422);
        }
        DB::beginTransaction();
        try {
            // Create purchase order
            $po = PurchaseOrder::create([
                'po_number' => PurchaseOrder::generatePONumber(),
                'vendor_id' => $validated['vendor_id'],
                'store_id' => $validated['store_id'],
                'created_by' => auth()->id(),
                'order_date' => now()->format('Y-m-d'),
                'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
                'status' => 'draft',
                'payment_status' => 'unpaid',
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'shipping_cost' => $validated['shipping_cost'] ?? 0,
                'notes' => $validated['notes'] ?? null,
                'terms_and_conditions' => $validated['terms_and_conditions'] ?? null,
            ]);
            // Create purchase order items
            foreach ($validated['items'] as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity_ordered' => $itemData['quantity_ordered'],
                    'unit_cost' => $itemData['unit_cost'] ?? 0,
                    'unit_sell_price' => $itemData['unit_sell_price'] ?? $product->price,
                    'tax_amount' => $itemData['tax_amount'] ?? 0,
                    'discount_amount' => $itemData['discount_amount'] ?? 0,
                    'notes' => $itemData['notes'] ?? null,
                ]);
            }
            // Calculate totals
            $po->calculateTotals();
            $po->save();
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Purchase order created successfully',
                'data' => $po->load('items', 'vendor', 'store')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create purchase order: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Get all purchase orders with filters
     */
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['vendor', 'store', 'createdBy']);
        // Filters
        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        if ($request->has('search')) {
            $this->whereLike($query, 'po_number', $request->search);
        }
        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('created_at', [$request->from_date, $request->to_date]);
        }
        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);
        $purchaseOrders = $query->paginate($request->get('per_page', 15));
        return response()->json([
            'success' => true,
            'data' => $purchaseOrders
        ]);
    }
    /**
     * Get single purchase order with details
     */
    public function show($id)
    {
        $po = PurchaseOrder::with([
            'vendor',
            'store',
            'createdBy',
            'approvedBy',
            'receivedBy',
            'items.product',
            'items.productBatch',
            'payments.vendorPayment'
        ])->findOrFail($id);
        return response()->json([
            'success' => true,
            'data' => $po
        ]);
    }
    /**
     * Update purchase order (only in draft status)
     */
    public function update(Request $request, $id)
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Can only update draft purchase orders'
            ], 422);
        }
        $validated = $request->validate([
            'vendor_id' => 'sometimes|exists:vendors,id',
            'expected_delivery_date' => 'nullable|date',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'terms_and_conditions' => 'nullable|string',
        ]);
        $po->update($validated);
        $po->calculateTotals();
        $po->save();
        return response()->json([
            'success' => true,
            'message' => 'Purchase order updated successfully',
            'data' => $po->load('items', 'vendor', 'store')
        ]);
    }
    /**
     * Add item to purchase order
     */
    public function addItem(Request $request, $id)
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Can only add items to draft purchase orders'
            ], 422);
        }
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity_ordered' => 'required|integer|min:1',
            'unit_cost' => 'nullable|numeric|min:0',
            'unit_sell_price' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        $product = Product::findOrFail($validated['product_id']);
        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'quantity_ordered' => $validated['quantity_ordered'],
            'unit_cost' => $validated['unit_cost'] ?? 0,
            'unit_sell_price' => $validated['unit_sell_price'] ?? $product->price,
            'tax_amount' => $validated['tax_amount'] ?? 0,
            'discount_amount' => $validated['discount_amount'] ?? 0,
            'notes' => $validated['notes'] ?? null,
        ]);
        $po->calculateTotals();
        $po->save();
        return response()->json([
            'success' => true,
            'message' => 'Item added to purchase order',
            'data' => $item
        ]);
    }
    /**
     * Update item in purchase order
     */
    public function updateItem(Request $request, $id, $itemId)
    {
        $po = PurchaseOrder::findOrFail($id);
        $item = PurchaseOrderItem::where('purchase_order_id', $id)
            ->findOrFail($itemId);
        if ($po->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Can only update items in draft purchase orders'
            ], 422);
        }
        $validated = $request->validate([
            'quantity_ordered' => 'sometimes|integer|min:1',
            'unit_cost' => 'sometimes|numeric|min:0',
            'unit_sell_price' => 'nullable|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        $item->update($validated);
        $po->calculateTotals();
        $po->save();
        return response()->json([
            'success' => true,
            'message' => 'Item updated successfully',
            'data' => $item
        ]);
    }
    /**
     * Remove item from purchase order
     */
    public function removeItem($id, $itemId)
    {
        $po = PurchaseOrder::findOrFail($id);
        $item = PurchaseOrderItem::where('purchase_order_id', $id)
            ->findOrFail($itemId);
        if ($po->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Can only remove items from draft purchase orders'
            ], 422);
        }
        $item->delete();
        $po->calculateTotals();
        $po->save();
        return response()->json([
            'success' => true,
            'message' => 'Item removed successfully'
        ]);
    }
    /**
     * Approve purchase order
     */
    public function approve($id)
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Can only approve draft purchase orders'
            ], 422);
        }
        $po->status = 'approved';
        $po->approved_by = auth()->id();
        $po->approved_at = now();
        $po->save();
        return response()->json([
            'success' => true,
            'message' => 'Purchase order approved successfully',
            'data' => $po
        ]);
    }
    /**
     * Receive purchase order (create product batches)
     */
    public function receive(Request $request, $id)
    {
        $po = PurchaseOrder::findOrFail($id);
        if (!in_array($po->status, ['approved', 'partially_received'])) {
            return response()->json([
                'success' => false,
                'message' => 'Purchase order must be approved before receiving'
            ], 422);
        }
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received' => 'required|integer|min:1',
            'items.*.batch_number' => 'nullable|string',
            'items.*.manufactured_date' => 'nullable|date',
            'items.*.expiry_date' => 'nullable|date',
        ]);
        try {
            $po->markAsReceived($validated['items']);
            
            // Update received_by and received_at
            $po->received_by = auth()->id();
            $po->received_at = now();
            $po->save();
            return response()->json([
                'success' => true,
                'message' => 'Products received successfully',
                'data' => $po->load('items.productBatch')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to receive products: ' . $e->getMessage()
            ], 500);
        }
    }
    /**
     * Cancel purchase order
     */
    public function cancel(Request $request, $id)
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status === 'received') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel received purchase order'
            ], 422);
        }
        $validated = $request->validate([
            'reason' => 'nullable|string'
        ]);
        $po->cancel($validated['reason'] ?? null);
        $po->cancelled_at = now();
        $po->save();
        return response()->json([
            'success' => true,
            'message' => 'Purchase order cancelled successfully'
        ]);
    }
    /**
     * Get purchase order statistics
     */
    public function statistics(Request $request)
    {
        $query = PurchaseOrder::query();
        // Date range filter
        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('created_at', [$request->from_date, $request->to_date]);
        }
        $stats = [
            'total_purchase_orders' => $query->count(),
            'by_status' => (clone $query)->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get(),
            'by_payment_status' => (clone $query)->selectRaw('payment_status, COUNT(*) as count')
                ->groupBy('payment_status')
                ->get(),
            'total_amount' => (clone $query)->sum('total_amount'),
            'total_paid' => (clone $query)->sum('paid_amount'),
            'total_outstanding' => (clone $query)->sum('outstanding_amount'),
            'overdue_orders' => PurchaseOrder::overdue()->count(),
            'recent_orders' => PurchaseOrder::with('vendor')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
        ];
        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
    /**
     * Check if purchase order can be deleted
     */
    public function canDelete($id)
    {
        $po = PurchaseOrder::findOrFail($id);
        $canDelete = $po->paid_amount <= 0;
        
        return response()->json([
            'success' => true,
            'can_delete' => $canDelete,
            'reason' => $canDelete ? null : 'Cannot delete purchase order with existing payments'
        ]);
    }
    /**
     * Delete purchase order and related inventory records.
     *
     * Deshio keeps physical barcode identities after PO deletion. Barcodes from
     * deleted batches are detached from the batch and recorded in
     * deleted_purchase_order_barcodes so lookup/return/exchange flows can still
     * recognize that their original PO/batch was deleted.
     */
    public function destroy(Request $request, $id)
    {
        $po = PurchaseOrder::with('items')->findOrFail($id);

        if ($po->paid_amount > 0 || $po->payment_status !== 'unpaid') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete purchase order as it has already been paid or partially paid.'
            ], 422);
        }

        $validated = $request->validate([
            'password' => 'required|string'
        ]);

        if (!Hash::check($validated['password'], auth()->user()->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid password. Deletion aborted.'
            ], 401);
        }

        DB::beginTransaction();
        try {
            $productIdsToSync = [];
            $batchIdsToDelete = [];

            foreach ($po->items as $item) {
                if ($item->product_id) {
                    $productIdsToSync[] = $item->product_id;
                }

                if ($item->product_batch_id) {
                    $batchIdsToDelete[] = $item->product_batch_id;
                }
            }

            $batchIdsToDelete = array_values(array_unique(array_filter($batchIdsToDelete)));

            if (!empty($batchIdsToDelete)) {
                $batchesById = ProductBatch::whereIn('id', $batchIdsToDelete)
                    ->get(['id', 'batch_number'])
                    ->keyBy('id');

                $barcodes = ProductBarcode::whereIn('batch_id', $batchIdsToDelete)
                    ->get(['id', 'batch_id', 'product_id']);

                foreach ($barcodes as $barcode) {
                    $batch = $batchesById->get($barcode->batch_id);

                    \App\Models\DeletedPurchaseOrderBarcode::updateOrCreate(
                        ['product_barcode_id' => $barcode->id],
                        [
                            'deleted_purchase_order_id' => $po->id,
                            'deleted_product_batch_id' => $barcode->batch_id,
                            'deleted_po_number' => $po->po_number,
                            'deleted_batch_number' => $batch?->batch_number,
                            'product_id' => $barcode->product_id,
                            'deleted_at' => now(),
                        ]
                    );
                }

                // product_barcodes.batch_id is a cascading FK in existing installs.
                // Detach first so deleting product_batches does not delete barcodes.
                ProductBarcode::whereIn('batch_id', $batchIdsToDelete)->update(['batch_id' => null]);

                ProductBatch::whereIn('id', $batchIdsToDelete)->delete();
            }

            $po->items()->delete();
            $po->delete();

            DB::commit();

            foreach (array_unique($productIdsToSync) as $productId) {
                if (method_exists(MasterInventory::class, 'syncProductInventory')) {
                    MasterInventory::syncProductInventory($productId);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Purchase order deleted. Batches were removed and barcodes were preserved with deleted PO references.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete purchase order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export a single purchase order as CSV.
     *
     * The reporting page expects this endpoint. Totals are recalculated per row so
     * old purchase order items with stale/zero total_cost still export correctly.
     */
    public function exportCsv(Request $request, $id)
    {
        $po = PurchaseOrder::with([
            'vendor',
            'store',
            'createdBy',
            'approvedBy',
            'receivedBy',
            'items.product.category',
            'items.productBatch',
        ])->findOrFail($id);

        $filename = 'purchase-order-' . ($po->po_number ?: $po->id) . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($po) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Purchase Order Summary']);
            fputcsv($out, ['PO Number', $po->po_number]);
            fputcsv($out, ['Status', $po->status]);
            fputcsv($out, ['Vendor', optional($po->vendor)->name ?? 'Unknown Vendor']);
            fputcsv($out, ['Warehouse / Store', optional($po->store)->name ?? 'Unknown Store']);
            fputcsv($out, ['Order Date', optional($po->order_date)->format('Y-m-d')]);
            fputcsv($out, ['Expected Delivery Date', optional($po->expected_delivery_date)->format('Y-m-d')]);
            fputcsv($out, ['Actual Delivery Date', optional($po->actual_delivery_date)->format('Y-m-d')]);
            fputcsv($out, ['Payment Status', $po->payment_status]);
            fputcsv($out, ['Created By', optional($po->createdBy)->name]);
            fputcsv($out, ['Approved By', optional($po->approvedBy)->name]);
            fputcsv($out, ['Received By', optional($po->receivedBy)->name]);
            fputcsv($out, []);

            fputcsv($out, [
                'PO Number',
                'PO Status',
                'Vendor',
                'Warehouse',
                'Order Date',
                'Expected Delivery Date',
                'Actual Delivery Date',
                'Payment Status',
                'Product ID',
                'Product Name',
                'SKU',
                'Category',
                'Quantity Ordered',
                'Quantity Received',
                'Quantity Pending',
                'Unit Cost',
                'Unit Sell Price',
                'Item Discount',
                'Item Tax',
                'Item Total Cost',
                'Batch Number',
                'Linked Batch ID',
                'Receive Status',
                'Notes',
            ]);

            $totalOrdered = 0;
            $totalReceived = 0;
            $totalPending = 0;
            $totalItemCost = 0;

            foreach ($po->items as $item) {
                $product = $item->product;
                $batch = $item->productBatch;
                $qtyOrdered = (float) ($item->quantity_ordered ?? 0);
                $qtyReceived = (float) ($item->quantity_received ?? 0);
                $qtyPending = (float) ($item->quantity_pending ?? max($qtyOrdered - $qtyReceived, 0));
                $itemTotal = (float) ($item->total_cost ?? 0);

                if ($itemTotal <= 0 && $qtyOrdered > 0) {
                    $itemTotal = max(($qtyOrdered * (float) ($item->unit_cost ?? 0)) - (float) ($item->discount_amount ?? 0) + (float) ($item->tax_amount ?? 0), 0);
                }

                $totalOrdered += $qtyOrdered;
                $totalReceived += $qtyReceived;
                $totalPending += $qtyPending;
                $totalItemCost += $itemTotal;

                fputcsv($out, [
                    $po->po_number,
                    $po->status,
                    optional($po->vendor)->name ?? 'Unknown Vendor',
                    optional($po->store)->name ?? 'Unknown Store',
                    optional($po->order_date)->format('Y-m-d'),
                    optional($po->expected_delivery_date)->format('Y-m-d'),
                    optional($po->actual_delivery_date)->format('Y-m-d'),
                    $po->payment_status,
                    $item->product_id,
                    $item->product_name ?: optional($product)->name,
                    $item->product_sku ?: optional($product)->sku,
                    optional(optional($product)->category)->title ?? 'Uncategorized',
                    $qtyOrdered,
                    $qtyReceived,
                    $qtyPending,
                    number_format((float) ($item->unit_cost ?? 0), 2, '.', ''),
                    number_format((float) ($item->unit_sell_price ?? optional($batch)->sell_price ?? 0), 2, '.', ''),
                    number_format((float) ($item->discount_amount ?? 0), 2, '.', ''),
                    number_format((float) ($item->tax_amount ?? 0), 2, '.', ''),
                    number_format($itemTotal, 2, '.', ''),
                    $item->batch_number ?: optional($batch)->batch_number,
                    $item->product_batch_id,
                    $item->receive_status,
                    $item->notes,
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, [
                'TOTAL', '', '', '', '', '', '', '', '', '', '', '',
                $totalOrdered,
                $totalReceived,
                $totalPending,
                '', '', '', '',
                number_format($totalItemCost, 2, '.', ''),
                '', '', '', '',
            ]);

            fputcsv($out, []);
            fputcsv($out, ['PO Subtotal', number_format((float) ($po->subtotal ?: $totalItemCost), 2, '.', '')]);
            fputcsv($out, ['PO Discount', number_format((float) ($po->discount_amount ?? 0), 2, '.', '')]);
            fputcsv($out, ['PO Tax', number_format((float) ($po->tax_amount ?? 0), 2, '.', '')]);
            fputcsv($out, ['Shipping Cost', number_format((float) ($po->shipping_cost ?? 0), 2, '.', '')]);
            fputcsv($out, ['Other Charges', number_format((float) ($po->other_charges ?? 0), 2, '.', '')]);
            fputcsv($out, ['PO Total', number_format((float) ($po->total_amount ?: ($totalItemCost - (float) ($po->discount_amount ?? 0) + (float) ($po->tax_amount ?? 0) + (float) ($po->shipping_cost ?? 0) + (float) ($po->other_charges ?? 0))), 2, '.', '')]);
            fputcsv($out, ['Paid Amount', number_format((float) ($po->paid_amount ?? 0), 2, '.', '')]);
            fputcsv($out, ['Outstanding Amount', number_format((float) ($po->outstanding_amount ?? max((float) ($po->total_amount ?? 0) - (float) ($po->paid_amount ?? 0), 0)), 2, '.', '')]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Export all barcodes linked to a purchase order as CSV.
     *
     * If an item was received but no barcode rows exist for the linked batch, the
     * export includes a visible missing-barcode row. This helps catch receiving
     * issues that otherwise look like a successful empty report.
     */
    public function exportBarcodesCsv(Request $request, $id)
    {
        $po = PurchaseOrder::with([
            'vendor',
            'store',
            'items.product.category',
            'items.productBatch',
        ])->findOrFail($id);

        $filename = 'purchase-order-barcodes-' . ($po->po_number ?: $po->id) . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($po) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'PO Number',
                'PO Status',
                'Vendor',
                'Warehouse',
                'Product ID',
                'Product Name',
                'SKU',
                'Category',
                'PO Item ID',
                'Batch ID',
                'Batch Number',
                'Barcode ID',
                'Barcode',
                'Current Store',
                'Current Status',
                'Is Active',
                'Is Defective',
                'Generated At',
                'Location Updated At',
                'Quantity Ordered',
                'Quantity Received',
                'Report Note',
            ]);

            foreach ($po->items as $item) {
                $product = $item->product;
                $batch = $item->productBatch;
                $barcodes = collect();

                if ($item->product_batch_id) {
                    $barcodes = ProductBarcode::with(['currentStore'])
                        ->where('batch_id', $item->product_batch_id)
                        ->orderBy('id')
                        ->get();
                }

                if ($barcodes->isEmpty()) {
                    fputcsv($out, [
                        $po->po_number,
                        $po->status,
                        optional($po->vendor)->name ?? 'Unknown Vendor',
                        optional($po->store)->name ?? 'Unknown Store',
                        $item->product_id,
                        $item->product_name ?: optional($product)->name,
                        $item->product_sku ?: optional($product)->sku,
                        optional(optional($product)->category)->title ?? 'Uncategorized',
                        $item->id,
                        $item->product_batch_id,
                        $item->batch_number ?: optional($batch)->batch_number,
                        '',
                        '',
                        optional($po->store)->name ?? 'Unknown Store',
                        'missing_barcode_rows',
                        '',
                        '',
                        '',
                        '',
                        (float) ($item->quantity_ordered ?? 0),
                        (float) ($item->quantity_received ?? 0),
                        $item->product_batch_id
                            ? 'No barcodes found for this received batch'
                            : (((int) ($item->quantity_received ?? 0) > 0 && !empty($item->batch_number))
                                ? 'This received batch was deleted from Product > Batch. Barcodes were preserved in batch_deleted_barcodes and blocked from sale.'
                                : 'PO item is not linked to a product batch yet'),
                    ]);
                    continue;
                }

                foreach ($barcodes as $barcode) {
                    fputcsv($out, [
                        $po->po_number,
                        $po->status,
                        optional($po->vendor)->name ?? 'Unknown Vendor',
                        optional($po->store)->name ?? 'Unknown Store',
                        $item->product_id,
                        $item->product_name ?: optional($product)->name,
                        $item->product_sku ?: optional($product)->sku,
                        optional(optional($product)->category)->title ?? 'Uncategorized',
                        $item->id,
                        $item->product_batch_id,
                        $item->batch_number ?: optional($batch)->batch_number,
                        $barcode->id,
                        "\t" . $barcode->barcode,
                        optional($barcode->currentStore)->name ?? optional($po->store)->name,
                        $barcode->current_status,
                        $barcode->is_active ? 'Yes' : 'No',
                        $barcode->is_defective ? 'Yes' : 'No',
                        optional($barcode->generated_at)->format('Y-m-d H:i:s'),
                        optional($barcode->location_updated_at)->format('Y-m-d H:i:s'),
                        (float) ($item->quantity_ordered ?? 0),
                        (float) ($item->quantity_received ?? 0),
                        '',
                    ]);
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

}
