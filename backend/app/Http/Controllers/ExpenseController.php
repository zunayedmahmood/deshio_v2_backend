<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\ExpenseReceipt;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ExpenseController extends Controller
{
    use DatabaseAgnosticSearch;
    public function index(Request $request)
    {
        $query = Expense::with(['category', 'vendor', 'employee', 'store', 'createdBy', 'approvedBy', 'payments']);

        // Filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }
        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }
        if ($request->has('expense_type')) {
            $query->where('expense_type', $request->expense_type);
        }

        // Date range
        if ($request->has('date_from')) {
            $query->whereDate('expense_date', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->whereDate('expense_date', '<=', $request->date_to);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['expense_number', 'description', 'reference_number'], $search);
        }

        $sortBy = $request->get('sort_by', 'expense_date');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        $perPage = $request->get('per_page', 15);
        $expenses = $query->paginate($perPage);

        return response()->json($expenses);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'category_id' => 'required|exists:expense_categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'employee_id' => 'nullable|exists:employees,id',
            'store_id' => 'nullable|exists:stores,id',
            'amount' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'expense_date' => 'required|date',
            'due_date' => 'nullable|date',
            'description' => 'required|string',
            'reference_number' => 'nullable|string',
            'vendor_invoice_number' => 'nullable|string',
            'expense_type' => 'required|in:one_time,recurring',
            'attachments' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $request->all();
        $data['created_by'] = Auth::id();
        $data['status'] = 'pending';
        $data['payment_status'] = 'unpaid';
        
        // If store_id not provided, use authenticated employee's store
        if (!isset($data['store_id'])) {
            $employee = Auth::user();
            $data['store_id'] = $employee->store_id ?? null;
        }
        
        // Calculate total
        $data['total_amount'] = $data['amount'] + ($data['tax_amount'] ?? 0) - ($data['discount_amount'] ?? 0);
        $data['outstanding_amount'] = $data['total_amount'];

        $expense = Expense::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Expense created successfully',
            'data' => $expense->load(['category', 'vendor'])
        ], 201);
    }

    public function show($id)
    {
        $expense = Expense::with([
            'category',
            'vendor',
            'employee',
            'store',
            'createdBy',
            'approvedBy',
            'processedBy',
            'payments',
            'receipts.uploadedBy'
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $expense]);
    }

    public function update(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);

        if (in_array($expense->status, ['approved', 'completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update expense in current status'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'category_id' => 'sometimes|exists:expense_categories,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'amount' => 'sometimes|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'expense_date' => 'sometimes|date',
            'due_date' => 'nullable|date',
            'description' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $request->except(['expense_number', 'status', 'payment_status']);
        
        if (isset($data['amount']) || isset($data['tax_amount']) || isset($data['discount_amount'])) {
            $amount = $data['amount'] ?? $expense->amount;
            $tax = $data['tax_amount'] ?? $expense->tax_amount;
            $discount = $data['discount_amount'] ?? $expense->discount_amount;
            $data['total_amount'] = $amount + $tax - $discount;
            $data['outstanding_amount'] = $data['total_amount'] - $expense->paid_amount;
        }

        $expense->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Expense updated successfully',
            'data' => $expense
        ]);
    }

    public function destroy($id)
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Can only delete pending expenses'
            ], 400);
        }

        $expense->delete();

        return response()->json([
            'success' => true,
            'message' => 'Expense deleted successfully'
        ]);
    }

    public function approve(Request $request, $id)
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Expense is not in pending status'
            ], 400);
        }

        $expense->update([
            'status' => 'approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'approval_notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense approved successfully',
            'data' => $expense
        ]);
    }

    public function reject(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $expense = Expense::findOrFail($id);

        if ($expense->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Expense is not in pending status'
            ], 400);
        }

        $expense->update([
            'status' => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Expense rejected successfully',
            'data' => $expense
        ]);
    }

    public function addPayment(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string',
            'payment_date' => 'required|date',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $expense = Expense::findOrFail($id);

        if ($expense->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Can only add payments to approved expenses'
            ], 400);
        }

        if ($request->amount > $expense->outstanding_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount exceeds outstanding amount'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $payment = ExpensePayment::create([
                'expense_id' => $expense->id,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'payment_date' => $request->payment_date,
                'reference_number' => $request->reference_number,
                'notes' => $request->notes,
                'processed_by' => Auth::id(),
            ]);

            $expense->paid_amount += $request->amount;
            $expense->outstanding_amount -= $request->amount;
            
            if ($expense->outstanding_amount <= 0) {
                $expense->payment_status = 'paid';
                $expense->status = 'completed';
                $expense->completed_at = now();
            } else {
                $expense->payment_status = 'partial';
            }
            
            $expense->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment added successfully',
                'data' => ['expense' => $expense, 'payment' => $payment]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add payment: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getStatistics(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth());
        $dateTo = $request->get('date_to', now()->endOfMonth());

        $stats = [
            'total_expenses' => Expense::whereBetween('expense_date', [$dateFrom, $dateTo])->count(),
            'total_amount' => Expense::whereBetween('expense_date', [$dateFrom, $dateTo])->sum('total_amount'),
            'total_paid' => Expense::whereBetween('expense_date', [$dateFrom, $dateTo])->sum('paid_amount'),
            'total_outstanding' => Expense::whereBetween('expense_date', [$dateFrom, $dateTo])->sum('outstanding_amount'),
            'by_status' => Expense::whereBetween('expense_date', [$dateFrom, $dateTo])
                ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as total')
                ->groupBy('status')
                ->get(),
            'by_category' => Expense::whereBetween('expense_date', [$dateFrom, $dateTo])
                ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
                ->selectRaw('expense_categories.name, COUNT(*) as count, SUM(expenses.total_amount) as total')
                ->groupBy('expense_categories.id', 'expense_categories.name')
                ->orderByDesc('total')
                ->limit(10)
                ->get(),
            'pending_approval' => Expense::where('status', 'pending')->count(),
            'overdue' => Expense::where('due_date', '<', now())
                ->where('payment_status', '!=', 'paid')
                ->count(),
        ];

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function getOverdue(Request $request)
    {
        $expenses = Expense::with(['category', 'vendor', 'store'])
            ->where('due_date', '<', now())
            ->whereNotIn('payment_status', ['paid'])
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->orderBy('due_date', 'asc')
            ->paginate($request->get('per_page', 15));

        return response()->json(['success' => true, 'data' => $expenses]);
    }

    // ============================================
    // RECEIPT MANAGEMENT METHODS
    // ============================================

    /**
     * Upload receipt image for an expense
     */
    public function uploadReceipt(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'receipt' => 'required|file|mimes:jpeg,jpg,png,pdf|max:5120', // 5MB max
            'description' => 'nullable|string|max:500',
            'is_primary' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $expense = Expense::findOrFail($id);

        try {
            $file = $request->file('receipt');
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $fileName = 'expense_' . $expense->id . '_' . time() . '_' . uniqid() . '.' . $extension;
            
            // Store in expense-receipts directory
            $filePath = $file->storeAs('expense-receipts', $fileName, 'public');

            $receipt = ExpenseReceipt::create([
                'expense_id' => $expense->id,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_extension' => $extension,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'original_name' => $originalName,
                'uploaded_by' => Auth::id(),
                'description' => $request->description,
                'is_primary' => $request->boolean('is_primary', false),
                'metadata' => [
                    'uploaded_at' => now()->toDateTimeString(),
                    'ip_address' => $request->ip(),
                ],
            ]);

            // If marked as primary, unset other primary receipts
            if ($receipt->is_primary) {
                ExpenseReceipt::where('expense_id', $expense->id)
                    ->where('id', '!=', $receipt->id)
                    ->update(['is_primary' => false]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Receipt uploaded successfully',
                'data' => $receipt->load('uploadedBy')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload receipt: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all receipts for an expense
     */
    public function getReceipts($id)
    {
        $expense = Expense::findOrFail($id);
        $receipts = $expense->receipts()->with('uploadedBy')->orderBy('is_primary', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $receipts
        ]);
    }

    /**
     * Delete a receipt
     */
    public function deleteReceipt($expenseId, $receiptId)
    {
        $expense = Expense::findOrFail($expenseId);
        $receipt = ExpenseReceipt::where('expense_id', $expense->id)
            ->where('id', $receiptId)
            ->firstOrFail();

        try {
            // Delete file from storage
            if (Storage::disk('public')->exists($receipt->file_path)) {
                Storage::disk('public')->delete($receipt->file_path);
            }

            $receipt->delete();

            return response()->json([
                'success' => true,
                'message' => 'Receipt deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete receipt: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set a receipt as primary
     */
    public function setPrimaryReceipt($expenseId, $receiptId)
    {
        $expense = Expense::findOrFail($expenseId);
        $receipt = ExpenseReceipt::where('expense_id', $expense->id)
            ->where('id', $receiptId)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            // Unset all primary receipts
            ExpenseReceipt::where('expense_id', $expense->id)
                ->update(['is_primary' => false]);

            // Set this as primary
            $receipt->is_primary = true;
            $receipt->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Receipt set as primary',
                'data' => $receipt
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to set primary receipt: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download a receipt
     */
    public function downloadReceipt($expenseId, $receiptId)
    {
        $expense = Expense::findOrFail($expenseId);
        $receipt = ExpenseReceipt::where('expense_id', $expense->id)
            ->where('id', $receiptId)
            ->firstOrFail();

        $filePath = storage_path('app/public/' . $receipt->file_path);

        if (!file_exists($filePath)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found'
            ], 404);
        }

        return response()->download($filePath, $receipt->original_name);
    }
}

