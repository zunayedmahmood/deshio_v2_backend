<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
}
