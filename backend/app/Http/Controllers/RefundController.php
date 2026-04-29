<?php

namespace App\Http\Controllers;

use App\Models\Refund;
use App\Models\ProductReturn;
use App\Models\Order;
use App\Models\Employee;
use App\Models\Transaction;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class RefundController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * Get all refunds
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Refund::with([
                'returnRequest',
                'order',
                'customer',
                'processedBy',
                'approvedBy'
            ]);

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by refund method
            if ($request->has('refund_method')) {
                $query->where('refund_method', $request->refund_method);
            }

            // Filter by customer
            if ($request->has('customer_id')) {
                $query->where('customer_id', $request->customer_id);
            }

            // Filter by date range
            if ($request->has('from_date')) {
                $query->where('created_at', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->where('created_at', '<=', $request->to_date);
            }

            // Search by refund number
            if ($request->has('search')) {
                $this->whereLike($query, 'refund_number', $request->search);
            }

            // Sort
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $refunds = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $refunds,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch refunds: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific refund
     */
    public function show($id): JsonResponse
    {
        try {
            $refund = Refund::with([
                'returnRequest',
                'order',
                'customer',
                'processedBy',
                'approvedBy'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $refund,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Refund not found: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Create a new refund from a product return
     */
    public function store(Request $request): JsonResponse
    {
        // Initial validation
        $request->validate([
            'return_id' => 'required|exists:product_returns,id',
            'refund_type' => 'required|in:full,percentage,partial_amount',
            'refund_percentage' => 'required_if:refund_type,percentage|numeric|min:0|max:100',
            'refund_amount' => 'required_if:refund_type,partial_amount|numeric|min:0.01',
            'refund_method' => 'required|in:cash,bank_transfer,card_refund,store_credit,gift_card,digital_wallet,check,other',
            'payment_reference' => 'nullable|string',
            'refund_method_details' => 'nullable|array',
            'customer_notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
        ]);

        // Additional validation for partial amount based on available amount
        if ($request->refund_type === 'partial_amount') {
            $return = ProductReturn::findOrFail($request->return_id);
            $alreadyRefunded = $return->getTotalRefundedAmount();
            $remainingAmount = $return->total_refund_amount - $alreadyRefunded;
            
            if ($request->refund_amount > $remainingAmount) {
                return response()->json([
                    'success' => false,
                    'message' => "Refund amount ({$request->refund_amount}) exceeds remaining amount ({$remainingAmount})",
                    'errors' => [
                        'refund_amount' => ["The refund amount may not be greater than {$remainingAmount}."]
                    ]
                ], 422);
            }
        }

        DB::beginTransaction();
        try {
            $return = ProductReturn::findOrFail($request->return_id);

            // Check if return is ready for refund (processing or completed)
            if (!in_array($return->status, ['processing', 'completed'])) {
                throw new \Exception('Return must be processing or completed before creating refund');
            }

            // Check if already fully refunded
            if ($return->isFullyRefunded()) {
                throw new \Exception('Return is already fully refunded');
            }

            // Calculate refund amount
            $originalAmount = $return->total_refund_amount; // Employee-adjusted amount
            $processingFee = $request->get('processing_fee', 0);
            
            $refundAmount = match ($request->refund_type) {
                'full' => $originalAmount - $processingFee,
                'percentage' => ($originalAmount * $request->refund_percentage / 100) - $processingFee,
                'partial_amount' => $request->refund_amount,
                default => 0,
            };

            // Validate refund amount
            $alreadyRefunded = $return->getTotalRefundedAmount();
            $remainingAmount = $originalAmount - $alreadyRefunded;

            if ($refundAmount > $remainingAmount) {
                throw new \Exception("Refund amount ({$refundAmount}) exceeds remaining amount ({$remainingAmount})");
            }

            // Create refund
            $refund = Refund::create([
                'refund_number' => $this->generateRefundNumber(),
                'return_id' => $return->id,
                'order_id' => $return->order_id,
                'customer_id' => $return->customer_id,
                'refund_type' => $request->refund_type,
                'refund_percentage' => $request->get('refund_percentage'),
                'original_amount' => $originalAmount,
                'refund_amount' => $refundAmount,
                'processing_fee' => $processingFee,
                'refund_method' => $request->refund_method,
                'payment_reference' => $request->payment_reference,
                'refund_method_details' => $request->refund_method_details,
                'status' => 'pending',
                'customer_notes' => $request->customer_notes,
                'internal_notes' => $request->internal_notes,
            ]);

            // Generate store credit code if method is store credit
            if ($request->refund_method === 'store_credit') {
                $refund->store_credit_code = $refund->generateStoreCreditCode();
                $refund->store_credit_expires_at = now()->addYear(); // 1 year expiry
                $refund->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Refund created successfully',
                'data' => $refund->load(['returnRequest', 'order', 'customer']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create refund: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process a refund (mark as processing)
     */
    public function process($id): JsonResponse
    {
        DB::beginTransaction();
        try {
            $refund = Refund::findOrFail($id);

            if ($refund->status !== 'pending') {
                throw new \Exception('Can only process pending refunds');
            }

            $employee = auth()->user();
            if (!$employee) {
                throw new \Exception('Employee authentication required');
            }

            $refund->process($employee);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Refund processing started',
                'data' => $refund->load(['returnRequest', 'order', 'customer', 'processedBy']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to process refund: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete a refund (money transferred)
     */
    public function complete(Request $request, $id): JsonResponse
    {
        $request->validate([
            'transaction_reference' => 'nullable|string',
            'bank_reference' => 'nullable|string',
            'gateway_reference' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $refund = Refund::findOrFail($id);

            if ($refund->status !== 'processing') {
                throw new \Exception('Can only complete processing refunds');
            }

            // Complete the refund
            $transactionRef = $request->transaction_reference ?? $this->generateTransactionReference($refund);
            $refund->complete($transactionRef);

            // Update bank/gateway references
            if ($request->has('bank_reference')) {
                $refund->bank_reference = $request->bank_reference;
            }
            if ($request->has('gateway_reference')) {
                $refund->gateway_reference = $request->gateway_reference;
            }
            $refund->save();

            // Create double-entry transaction record for refund
            // Credit Cash (money going out)
            Transaction::create([
                'transaction_number' => $transactionRef . '-CASH',
                'type' => 'credit',
                'account_id' => \App\Models\Transaction::getCashAccountId(auth()->user()->store_id),
                'reference_type' => 'refund',
                'reference_id' => $refund->id,
                'amount' => $refund->refund_amount,
                'description' => "Cash refund for return: {$refund->returnRequest->return_number}",
                'store_id' => auth()->user()->store_id,
                'created_by' => auth()->id(),
                'status' => 'completed',
                'transaction_date' => now()->toDateString(),
                'metadata' => [
                    'refund_method' => $refund->refund_method,
                    'refund_id' => $refund->id,
                    'return_id' => $refund->return_id
                ]
            ]);

            // Debit Sales Revenue (revenue being reversed)
            Transaction::create([
                'transaction_number' => $transactionRef . '-REV',
                'type' => 'debit',
                'account_id' => \App\Models\Transaction::getSalesRevenueAccountId(),
                'reference_type' => 'refund',
                'reference_id' => $refund->id,
                'amount' => $refund->refund_amount,
                'description' => "Revenue reversal for return: {$refund->returnRequest->return_number}",
                'store_id' => auth()->user()->store_id,
                'created_by' => auth()->id(),
                'status' => 'completed',
                'transaction_date' => now()->toDateString(),
                'metadata' => [
                    'refund_method' => $refund->refund_method,
                    'refund_id' => $refund->id,
                    'return_id' => $refund->return_id
                ]
            ]);

            // Update return status if fully refunded
            $return = $refund->returnRequest;
            if ($return->isFullyRefunded()) {
                $return->markAsRefunded();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Refund completed successfully',
                'data' => $refund->load(['returnRequest', 'order', 'customer']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete refund: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fail a refund (transaction failed)
     */
    public function fail(Request $request, $id): JsonResponse
    {
        $request->validate([
            'failure_reason' => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            $refund = Refund::findOrFail($id);

            $refund->fail($request->failure_reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Refund marked as failed',
                'data' => $refund->load(['returnRequest', 'order', 'customer']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update refund: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel a refund
     */
    public function cancel(Request $request, $id): JsonResponse
    {
        $request->validate([
            'cancel_reason' => 'nullable|string',
        ]);

        DB::beginTransaction();
        try {
            $refund = Refund::findOrFail($id);

            $refund->cancel($request->cancel_reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Refund cancelled successfully',
                'data' => $refund->load(['returnRequest', 'order', 'customer']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel refund: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get refund statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $query = Refund::query();

            // Filter by date range
            if ($request->has('from_date')) {
                $query->where('created_at', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->where('created_at', '<=', $request->to_date);
            }

            $stats = [
                'total_refunds' => $query->count(),
                'pending' => (clone $query)->where('status', 'pending')->count(),
                'processing' => (clone $query)->where('status', 'processing')->count(),
                'completed' => (clone $query)->where('status', 'completed')->count(),
                'failed' => (clone $query)->where('status', 'failed')->count(),
                'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
                'total_refund_amount' => (clone $query)->where('status', 'completed')->sum('refund_amount'),
                'total_processing_fees' => $query->sum('processing_fee'),
                'by_method' => Refund::select('refund_method', DB::raw('count(*) as count'), DB::raw('sum(refund_amount) as total'))
                    ->where('status', 'completed')
                    ->groupBy('refund_method')
                    ->get(),
            ];

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
     * Helper: Generate refund number
     */
    private function generateRefundNumber(): string
    {
        return DB::transaction(function () {
            $date = now()->format('Ymd');
            $attempts = 0;
            $maxAttempts = 10;
            
            do {
                // Get count with lock to prevent race condition
                $count = DB::table('refunds')
                    ->whereDate('created_at', now())
                    ->lockForUpdate()
                    ->count() + 1;
                    
                $refundNumber = 'REF-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
                
                // Check if this number already exists
                $exists = Refund::where('refund_number', $refundNumber)->exists();
                
                if (!$exists) {
                    return $refundNumber;
                }
                
                $attempts++;
            } while ($attempts < $maxAttempts);
            
            // Fallback to UUID if all attempts fail
            return 'REF-' . $date . '-' . strtoupper(substr(uniqid(), -8));
        });
    }

    /**
     * Helper: Generate transaction reference
     */
    private function generateTransactionReference(Refund $refund): string
    {
        return 'TXN-REF-' . $refund->id . '-' . time();
    }
    
    /**
     * Get expired store credits
     */
    public function getExpiredStoreCredits(): JsonResponse
    {
        $expiredCredits = Refund::where('refund_method', 'store_credit')
            ->where('status', 'completed')
            ->whereNotNull('store_credit_expires_at')
            ->where('store_credit_expires_at', '<', now())
            ->with(['returnRequest', 'customer'])
            ->paginate(20);
            
        return response()->json([
            'success' => true,
            'message' => 'Expired store credits retrieved',
            'data' => $expiredCredits,
        ]);
    }
    
    /**
     * Mark expired store credits as expired (scheduled job helper)
     */
    public function markExpiredStoreCredits(): JsonResponse
    {
        $count = Refund::where('refund_method', 'store_credit')
            ->where('status', 'completed')
            ->whereNotNull('store_credit_expires_at')
            ->where('store_credit_expires_at', '<', now())
            ->update(['status' => 'expired']);
            
        return response()->json([
            'success' => true,
            'message' => "Marked {$count} store credits as expired",
            'expired_count' => $count,
        ]);
    }
}
