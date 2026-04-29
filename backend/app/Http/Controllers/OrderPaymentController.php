<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\PaymentSplit;
use App\Models\CashDenomination;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderPaymentController extends Controller
{
    /**
     * Get all payments for an order
     */
    public function index(Request $request, $orderId)
    {
        $order = Order::with(['payments.paymentMethod', 'payments.paymentSplits.paymentMethod'])
            ->findOrFail($orderId);

        $payments = $order->payments()
            ->with(['paymentMethod', 'processedBy', 'paymentSplits.paymentMethod', 'cashDenominations'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_amount' => $order->total_amount,
                    'paid_amount' => $order->paid_amount,
                    'outstanding_amount' => $order->outstanding_amount,
                    'payment_status' => $order->payment_status,
                ],
                'payments' => $payments,
                'summary' => $order->getPaymentSummaryAttribute(),
            ],
        ]);
    }

    /**
     * Create a simple payment (single payment method, no splits)
     * 
     * Use this endpoint when:
     * - Customer pays with ONE payment method
     * - Making multiple separate payments over time (installments)
     * - Each payment is a separate transaction
     * 
     * Example: Customer pays $500 cash today, then $500 card next week
     * Result: 2 separate OrderPayment records, NO payment splits
     */
    public function store(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_type' => 'nullable|in:full,installment,partial,final,advance',
            'transaction_reference' => 'nullable|string|max:255',
            'external_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'payment_data' => 'nullable|array',
            'auto_complete' => 'nullable|boolean',
            'store_credit_code' => 'nullable|string|max:50', // For store credit payments
            
            // Cash denomination tracking (optional)
            'cash_received' => 'nullable|array',
            'cash_received.*.denomination' => 'required|numeric|min:0.01',
            'cash_received.*.quantity' => 'required|integer|min:1',
            'cash_received.*.type' => 'nullable|in:note,coin',
            
            'cash_change' => 'nullable|array',
            'cash_change.*.denomination' => 'required|numeric|min:0.01',
            'cash_change.*.quantity' => 'required|integer|min:1',
            'cash_change.*.type' => 'nullable|in:note,coin',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $order = Order::findOrFail($orderId);
            $paymentMethod = PaymentMethod::findOrFail($request->payment_method_id);
            $employee = auth()->user();
            
            // Validate store credit if payment method is store credit
            if ($paymentMethod->code === 'store_credit' && $request->has('store_credit_code')) {
                $this->validateStoreCreditCode($request->store_credit_code, $request->amount);
            }
            
            // Validate store credit if payment method is store credit
            if ($paymentMethod->code === 'store_credit' && $request->has('store_credit_code')) {
                $this->validateStoreCreditCode($request->store_credit_code, $request->amount);
            }

            // Create the payment
            $payment = OrderPayment::createPayment(
                $order,
                $paymentMethod,
                $request->amount,
                $request->payment_data ?? [],
                $employee
            );

            // Set additional fields
            $payment->update([
                'payment_type' => $request->payment_type ?? 'full',
                'transaction_reference' => $request->transaction_reference,
                'external_reference' => $request->external_reference,
                'notes' => $request->notes,
                'order_balance_before' => $order->outstanding_amount,
                'order_balance_after' => max(0, $order->outstanding_amount - $request->amount),
            ]);

            // Record cash denominations if provided
            if ($request->has('cash_received') && $paymentMethod->type === 'cash') {
                $this->recordCashDenominations(
                    $payment->id,
                    'order_payment',
                    $order->store_id,
                    $request->cash_received,
                    'received'
                );
            }

            if ($request->has('cash_change') && $paymentMethod->type === 'cash') {
                $this->recordCashDenominations(
                    $payment->id,
                    'order_payment',
                    $order->store_id,
                    $request->cash_change,
                    'change'
                );
            }

            // Auto-complete if requested (for in-person payments)
            if ($request->input('auto_complete', false)) {
                $payment->process($employee);
                $payment->complete(
                    $request->transaction_reference,
                    $request->external_reference
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment created successfully',
                'data' => $payment->load(['paymentMethod', 'processedBy', 'cashDenominations']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a split payment (single payment split across multiple methods)
     * 
     * Use this endpoint when:
     * - Customer pays with MULTIPLE payment methods AT THE SAME TIME
     * - One transaction split into parts (e.g., $300 cash + $700 card)
     * 
     * Example: Customer pays $1000 total: $300 cash + $500 bank + $200 card
     * Result: 1 OrderPayment (payment_method_id=null) + 3 PaymentSplit records
     * 
     * IMPORTANT: This is different from multiple separate payments!
     * Split = Single transaction with multiple methods
     * Multiple payments = Different transactions over time
     */
    public function storeSplitPayment(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'total_amount' => 'required|numeric|min:0.01',
            'payment_type' => 'nullable|in:full,installment,partial,final,advance',
            'notes' => 'nullable|string',
            'auto_complete' => 'nullable|boolean',
            
            'splits' => 'required|array|min:2',
            'splits.*.payment_method_id' => 'required|exists:payment_methods,id',
            'splits.*.amount' => 'required|numeric|min:0.01',
            'splits.*.transaction_reference' => 'nullable|string|max:255',
            'splits.*.external_reference' => 'nullable|string|max:255',
            'splits.*.payment_data' => 'nullable|array',
            
            // Cash denominations for each split
            'splits.*.cash_received' => 'nullable|array',
            'splits.*.cash_received.*.denomination' => 'required|numeric|min:0.01',
            'splits.*.cash_received.*.quantity' => 'required|integer|min:1',
            'splits.*.cash_received.*.type' => 'nullable|in:note,coin',
            
            'splits.*.cash_change' => 'nullable|array',
            'splits.*.cash_change.*.denomination' => 'required|numeric|min:0.01',
            'splits.*.cash_change.*.quantity' => 'required|integer|min:1',
            'splits.*.cash_change.*.type' => 'nullable|in:note,coin',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Validate total split amount
        $totalSplitAmount = collect($request->splits)->sum('amount');
        if (abs($totalSplitAmount - $request->total_amount) > 0.01) {
            return response()->json([
                'success' => false,
                'message' => "Total split amount ($totalSplitAmount) does not match total payment amount ({$request->total_amount})",
            ], 422);
        }

        DB::beginTransaction();
        try {
            $order = Order::findOrFail($orderId);
            $employee = auth()->user();

            // Create parent payment record (without specific payment method)
            $payment = OrderPayment::create([
                'order_id' => $order->id,
                'payment_method_id' => null, // No single method for split payments
                'customer_id' => $order->customer_id,
                'store_id' => $order->store_id,
                'processed_by' => $employee->id,
                'amount' => $request->total_amount,
                'fee_amount' => 0, // Will be calculated from splits
                'net_amount' => 0, // Will be calculated from splits
                'payment_type' => $request->payment_type ?? 'full',
                'notes' => $request->notes,
                'order_balance_before' => $order->outstanding_amount,
                'order_balance_after' => max(0, $order->outstanding_amount - $request->total_amount),
                'status' => 'pending',
            ]);

            // Create payment splits
            $totalFees = 0;
            $sequence = 1;

            foreach ($request->splits as $splitData) {
                $method = PaymentMethod::find($splitData['payment_method_id']);
                
                $split = PaymentSplit::createSplit(
                    $payment,
                    $method,
                    $splitData['amount'],
                    $sequence++,
                    $splitData['payment_data'] ?? []
                );

                // Update split with references
                $split->update([
                    'transaction_reference' => $splitData['transaction_reference'] ?? null,
                    'external_reference' => $splitData['external_reference'] ?? null,
                ]);

                $totalFees += $split->fee_amount;

                // Record cash denominations for this split if provided
                if (isset($splitData['cash_received']) && $method->type === 'cash') {
                    $this->recordCashDenominations(
                        $split->id,
                        'payment_split',
                        $order->store_id,
                        $splitData['cash_received'],
                        'received'
                    );
                }

                if (isset($splitData['cash_change']) && $method->type === 'cash') {
                    $this->recordCashDenominations(
                        $split->id,
                        'payment_split',
                        $order->store_id,
                        $splitData['cash_change'],
                        'change'
                    );
                }

                // Auto-complete split if requested
                if ($request->input('auto_complete', false)) {
                    $split->complete(
                        $splitData['transaction_reference'] ?? null,
                        $splitData['external_reference'] ?? null
                    );
                }
            }

            // Update parent payment with calculated fees
            $payment->update([
                'fee_amount' => $totalFees,
                'net_amount' => $request->total_amount - $totalFees,
            ]);

            // Auto-complete parent payment if all splits completed
            if ($request->input('auto_complete', false)) {
                $payment->process($employee);
                $payment->updateSplitStatus();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Split payment created successfully',
                'data' => $payment->load([
                    'paymentSplits.paymentMethod',
                    'paymentSplits.cashDenominations',
                    'processedBy'
                ]),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create split payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper method to record cash denominations
     */
    private function recordCashDenominations($parentId, $parentType, $storeId, $denominations, $type)
    {
        foreach ($denominations as $denom) {
            $method = $type === 'received' 
                ? 'recordReceived' 
                : 'recordChange';

            CashDenomination::$method(
                $parentId,
                $parentType,
                $storeId,
                $denom['denomination'],
                $denom['quantity'],
                $denom['currency'] ?? 'USD',
                $denom['type'] ?? 'note'
            );
        }
    }

    /**
     * Get payment details including splits and cash denominations
     */
    public function show($orderId, $paymentId)
    {
        $payment = OrderPayment::with([
            'order',
            'paymentMethod',
            'processedBy',
            'paymentSplits.paymentMethod',
            'paymentSplits.cashDenominations',
            'cashDenominations'
        ])->findOrFail($paymentId);

        // Ensure payment belongs to the order
        if ($payment->order_id != $orderId) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found for this order',
            ], 404);
        }

        $data = $payment->toArray();
        
        // Add additional computed data
        $data['is_split_payment'] = $payment->isSplitPayment();
        $data['has_cash_denominations'] = $payment->cashDenominations()->exists() || 
            $payment->paymentSplits()->whereHas('cashDenominations')->exists();
        
        if ($payment->hasSplits()) {
            $data['split_summary'] = $payment->getSplitSummary();
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Process a pending payment
     */
    public function process($orderId, $paymentId)
    {
        DB::beginTransaction();
        try {
            $payment = OrderPayment::findOrFail($paymentId);
            $employee = auth()->user();

            if ($payment->order_id != $orderId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found for this order',
                ], 404);
            }

            if (!$payment->canBeProcessed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment cannot be processed in its current status',
                ], 422);
            }

            $payment->process($employee);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment processing started',
                'data' => $payment->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete a payment
     */
    public function complete(Request $request, $orderId, $paymentId)
    {
        $validator = Validator::make($request->all(), [
            'transaction_reference' => 'nullable|string|max:255',
            'external_reference' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $payment = OrderPayment::findOrFail($paymentId);

            if ($payment->order_id != $orderId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found for this order',
                ], 404);
            }

            if (!$payment->canBeCompleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment cannot be completed in its current status',
                ], 422);
            }

            $payment->complete(
                $request->transaction_reference,
                $request->external_reference
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment completed successfully',
                'data' => $payment->fresh()->load('order'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Fail a payment
     */
    public function fail(Request $request, $orderId, $paymentId)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $payment = OrderPayment::findOrFail($paymentId);

            if ($payment->order_id != $orderId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found for this order',
                ], 404);
            }

            $payment->fail($request->reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment marked as failed',
                'data' => $payment->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refund a payment
     */
    public function refund(Request $request, $orderId, $paymentId)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $payment = OrderPayment::findOrFail($paymentId);

            if ($payment->order_id != $orderId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found for this order',
                ], 404);
            }

            if ($request->amount > $payment->getRefundableAmount()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund amount exceeds refundable amount',
                ], 422);
            }

            $payment->refund($request->amount, $request->reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment refunded successfully',
                'data' => $payment->fresh()->load('order'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to refund payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get cash denomination summary for a payment
     */
    public function getCashDenominations($orderId, $paymentId)
    {
        $payment = OrderPayment::with([
            'cashDenominations',
            'paymentSplits.cashDenominations'
        ])->findOrFail($paymentId);

        if ($payment->order_id != $orderId) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found for this order',
            ], 404);
        }

        $summary = [
            'payment_id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'has_splits' => $payment->hasSplits(),
        ];

        if ($payment->hasSplits()) {
            // Get denominations for each split
            $summary['splits'] = $payment->paymentSplits->map(function ($split) {
                $received = CashDenomination::getReceivedBreakdown($split->id, 'payment_split');
                $change = CashDenomination::getChangeBreakdown($split->id, 'payment_split');
                
                return [
                    'split_id' => $split->id,
                    'sequence' => $split->split_sequence,
                    'payment_method' => $split->paymentMethod->name,
                    'amount' => $split->amount,
                    'cash_received' => $received,
                    'total_received' => CashDenomination::getTotalReceived($split->id, 'payment_split'),
                    'cash_change' => $change,
                    'total_change' => CashDenomination::getTotalChange($split->id, 'payment_split'),
                ];
            });
        } else {
            // Get denominations for direct payment
            $received = CashDenomination::getReceivedBreakdown($payment->id, 'order_payment');
            $change = CashDenomination::getChangeBreakdown($payment->id, 'order_payment');
            
            $summary['cash_received'] = $received;
            $summary['total_received'] = CashDenomination::getTotalReceived($payment->id, 'order_payment');
            $summary['cash_change'] = $change;
            $summary['total_change'] = CashDenomination::getTotalChange($payment->id, 'order_payment');
        }

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Calculate optimal change for a given amount
     */
    public function calculateChange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|in:USD,BDT',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $change = CashDenomination::calculateOptimalChange(
            $request->amount,
            $request->input('currency', 'USD')
        );

        return response()->json([
            'success' => true,
            'data' => [
                'amount' => $request->amount,
                'currency' => $request->input('currency', 'USD'),
                'change_breakdown' => $change,
                'total_denominations' => count($change),
            ],
        ]);
    }
    
    /**
     * Validate store credit code and expiration
     */
    private function validateStoreCreditCode(string $storeCreditCode, float $amount, int $storeId = null): void
    {
        $query = \App\Models\Refund::where('store_credit_code', $storeCreditCode)
            ->where('refund_method', 'store_credit')
            ->where('status', 'completed');
            
        // If store_id provided, validate store credit is from same store
        if ($storeId) {
            $query->whereHas('order', function($q) use ($storeId) {
                $q->where('store_id', $storeId);
            });
        }
        
        $refund = $query->first();
            
        if (!$refund) {
            throw new \Exception('Invalid store credit code or not available for this store');
        }
        
        if ($refund->isExpiredStoreCredit()) {
            throw new \Exception('Store credit has expired');
        }
        
        if ($amount > $refund->refund_amount) {
            throw new \Exception("Store credit amount ($refund->refund_amount) is less than requested amount ($amount)");
        }
    }
}
