<?php

namespace App\Http\Controllers;

use App\Models\VendorPayment;
use App\Models\VendorPaymentItem;
use App\Models\PurchaseOrder;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorPaymentController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * Create a vendor payment
     * Supports partial payments: e.g., $10,000 bill can be paid $7,000 now
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'account_id' => 'nullable|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_type' => 'required|in:purchase_order,advance,refund,adjustment',
            'reference_number' => 'nullable|string',
            'transaction_id' => 'nullable|string',
            'cheque_number' => 'nullable|string',
            'cheque_date' => 'nullable|date',
            'bank_name' => 'nullable|string',
            'notes' => 'nullable|string',
            'allocations' => 'required_if:payment_type,purchase_order|array',
            'allocations.*.purchase_order_id' => 'required|exists:purchase_orders,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
            'allocations.*.notes' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            // Validate total allocation doesn't exceed payment amount
            if (isset($validated['allocations'])) {
                $totalAllocated = array_sum(array_column($validated['allocations'], 'amount'));
                if ($totalAllocated > $validated['amount']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Total allocated amount cannot exceed payment amount'
                    ], 422);
                }
            }

            // Create payment
            $payment = VendorPayment::create([
                'payment_number' => VendorPayment::generatePaymentNumber(),
                'reference_number' => $validated['reference_number'] ?? null,
                'vendor_id' => $validated['vendor_id'],
                'payment_method_id' => $validated['payment_method_id'],
                'account_id' => $validated['account_id'] ?? null,
                'employee_id' => auth()->id(),
                'amount' => $validated['amount'],
                'allocated_amount' => 0,
                'unallocated_amount' => $validated['amount'],
                'status' => 'pending',
                'payment_type' => $validated['payment_type'],
                'transaction_id' => $validated['transaction_id'] ?? null,
                'cheque_number' => $validated['cheque_number'] ?? null,
                'cheque_date' => $validated['cheque_date'] ?? null,
                'bank_name' => $validated['bank_name'] ?? null,
                'payment_date' => $validated['payment_date'],
                'notes' => $validated['notes'] ?? null,
            ]);

            // Allocate to purchase orders if applicable
            if ($validated['payment_type'] === 'purchase_order' && isset($validated['allocations'])) {
                $payment->allocateToPurchaseOrders($validated['allocations']);
            }

            // Complete the payment
            $payment->complete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor payment created successfully',
                'data' => $payment->load('paymentItems.purchaseOrder', 'vendor', 'paymentMethod')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all vendor payments with filters
     */
    public function index(Request $request)
    {
        $query = VendorPayment::with(['vendor', 'paymentMethod', 'employee']);

        // Filters
        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_type')) {
            $query->where('payment_type', $request->payment_type);
        }

        if ($request->has('search')) {
            $this->whereAnyLike($query, ['payment_number', 'reference_number', 'transaction_id'], $request->search);
        }

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('payment_date', [$request->from_date, $request->to_date]);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        $payments = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }

    /**
     * Get single vendor payment with details
     */
    public function show($id)
    {
        $payment = VendorPayment::with([
            'vendor',
            'paymentMethod',
            'account',
            'employee',
            'paymentItems.purchaseOrder'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $payment
        ]);
    }

    /**
     * Get payments for a specific purchase order
     */
    public function getByPurchaseOrder($purchaseOrderId)
    {
        $po = PurchaseOrder::findOrFail($purchaseOrderId);
        
        $payments = $po->getPaymentHistory();

        return response()->json([
            'success' => true,
            'data' => [
                'purchase_order' => [
                    'id' => $po->id,
                    'po_number' => $po->po_number,
                    'total_amount' => $po->total_amount,
                    'paid_amount' => $po->paid_amount,
                    'outstanding_amount' => $po->outstanding_amount,
                    'payment_status' => $po->payment_status,
                ],
                'payments' => $payments
            ]
        ]);
    }

    /**
     * Allocate advance payment to purchase orders
     */
    public function allocateAdvance(Request $request, $id)
    {
        $payment = VendorPayment::findOrFail($id);

        if ($payment->payment_type !== 'advance') {
            return response()->json([
                'success' => false,
                'message' => 'Can only allocate advance payments'
            ], 422);
        }

        if ($payment->unallocated_amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'No unallocated amount available'
            ], 422);
        }

        $validated = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.purchase_order_id' => 'required|exists:purchase_orders,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
            'allocations.*.notes' => 'nullable|string',
        ]);

        $totalAllocating = array_sum(array_column($validated['allocations'], 'amount'));
        
        if ($totalAllocating > $payment->unallocated_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Allocation amount exceeds unallocated balance'
            ], 422);
        }

        try {
            $payment->allocateToPurchaseOrders($validated['allocations']);

            return response()->json([
                'success' => true,
                'message' => 'Advance payment allocated successfully',
                'data' => $payment->load('paymentItems.purchaseOrder')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to allocate payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a vendor payment
     */
    public function cancel($id)
    {
        $payment = VendorPayment::findOrFail($id);

        if ($payment->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Payment is already cancelled'
            ], 422);
        }

        if ($payment->status === 'refunded') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel a refunded payment'
            ], 422);
        }

        try {
            $payment->cancel();

            return response()->json([
                'success' => true,
                'message' => 'Payment cancelled successfully',
                'data' => $payment
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refund a vendor payment
     */
    public function refund($id)
    {
        $payment = VendorPayment::findOrFail($id);

        if ($payment->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Can only refund completed payments'
            ], 422);
        }

        try {
            $payment->refund();

            return response()->json([
                'success' => true,
                'message' => 'Payment refunded successfully',
                'data' => $payment
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to refund payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get vendor payment statistics
     */
    public function statistics(Request $request)
    {
        $query = VendorPayment::query();

        // Date range filter
        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('payment_date', [$request->from_date, $request->to_date]);
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        $stats = [
            'total_payments' => $query->count(),
            'total_amount_paid' => (clone $query)->where('status', 'completed')->sum('amount'),
            'by_status' => (clone $query)->selectRaw('status, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('status')
                ->get(),
            'by_payment_type' => (clone $query)->selectRaw('payment_type, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('payment_type')
                ->get(),
            'advance_payments' => VendorPayment::advancePayments()->sum('unallocated_amount'),
            'recent_payments' => VendorPayment::with('vendor')
                ->where('status', 'completed')
                ->orderBy('payment_date', 'desc')
                ->limit(10)
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get outstanding payments for a vendor
     */
    public function getOutstanding($vendorId)
    {
        $purchaseOrders = PurchaseOrder::where('vendor_id', $vendorId)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->with('items')
            ->get();

        $totalOutstanding = $purchaseOrders->sum('outstanding_amount');
        $advancePayments = VendorPayment::byVendor($vendorId)
            ->advancePayments()
            ->sum('unallocated_amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_outstanding' => $totalOutstanding,
                'advance_payments_available' => $advancePayments,
                'net_outstanding' => $totalOutstanding - $advancePayments,
                'purchase_orders' => $purchaseOrders
            ]
        ]);
    }
}
