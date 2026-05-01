<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * Get available payment methods for an order
     */
    public function getAvailableMethods(Request $request, Order $order): JsonResponse
    {
        $customerType = $order->customer->customer_type;
        $methods = PaymentMethod::getAvailableMethodsForCustomerType($customerType);

        return response()->json([
            'success' => true,
            'data' => $methods,
        ]);
    }

    /**
     * Get all active payment methods (for vendor payments, expenses, etc.)
     * 
     * This endpoint returns ALL active payment methods without customer type filtering.
     * Used for:
     * - Vendor payments (purchase orders)
     * - Expense payments
     * - Internal transactions
     * - Any B2B payments
     * 
     * GET /api/payment-methods/all
     */
    public function getAllPaymentMethods(Request $request): JsonResponse
    {
        $methods = PaymentMethod::active()
            ->ordered()
            ->get([
                'id',
                'code',
                'name',
                'description',
                'type',
                'is_active',
                'requires_reference',
                'supports_partial',
                'min_amount',
                'max_amount',
                'fixed_fee',
                'percentage_fee',
                'icon',
                'sort_order'
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment methods retrieved successfully',
            'data' => [
                'payment_methods' => $methods,
                'total_count' => $methods->count(),
                'note' => 'All active payment methods - no customer type restrictions'
            ],
        ]);
    }

    /**
     * Get payment methods for a customer type
     * 
     * PUBLIC API - No authentication required
     * 
     * Customer Types:
     * - counter: POS/Counter sales (phone-only, no account needed)
     * - social_commerce: WhatsApp/Facebook sales (phone-only, no account needed)
     * - ecommerce: Website sales (requires account with email/password)
     * 
     * GET /api/payment-methods?customer_type=counter
     */
    public function getMethodsByCustomerType(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_type' => ['required', Rule::in(['counter', 'social_commerce', 'ecommerce'])],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $methods = PaymentMethod::getAvailableMethodsForCustomerType($request->customer_type);

        return response()->json([
            'success' => true,
            'data' => [
                'customer_type' => $request->customer_type,
                'payment_methods' => $methods,
                'note' => $request->customer_type === 'ecommerce' 
                    ? 'E-commerce customers require account registration'
                    : 'No customer account required - phone number only'
            ],
        ]);
    }

    /**
     * Process a payment for an order
     */
    public function processPayment(Request $request, Order $order): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_data' => 'nullable|array',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Check if order can accept payments
            if (!$order->canAcceptPayment()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot accept payments in its current state',
                ], 400);
            }

            // Check remaining amount
            $remainingAmount = $order->getRemainingAmount();
            if ($request->amount > $remainingAmount) {
                return response()->json([
                    'success' => false,
                    'message' => "Payment amount exceeds remaining balance of {$remainingAmount}",
                ], 400);
            }

            // Get payment method
            $paymentMethod = PaymentMethod::findOrFail($request->payment_method_id);

            // Validate payment method is allowed for customer type
            if (!$paymentMethod->isAllowedForCustomerType($order->customer->customer_type)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method not allowed for this customer type',
                ], 400);
            }

            // Create payment
            $payment = $order->addPayment(
                $paymentMethod,
                $request->amount,
                $request->payment_data ?? [],
                auth()->user() // Assuming employee is authenticated
            );

            // Process the payment
            $transactionReference = $request->payment_data['transaction_reference'] ?? null;
            $externalReference = $request->payment_data['external_reference'] ?? null;

            if ($order->processPayment($payment, $transactionReference, $externalReference)) {
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Payment processed successfully',
                    'data' => [
                        'payment' => $payment->load('paymentMethod'),
                        'order_summary' => $order->payment_summary,
                    ],
                ]);
            } else {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process payment',
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process multiple payments for an order (fragmented payment)
     */
    public function processMultiplePayments(Request $request, Order $order): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'payments' => 'required|array|min:1',
            'payments.*.payment_method_id' => 'required|exists:payment_methods,id',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.payment_data' => 'nullable|array',
            'payments.*.notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Check if order can accept payments
            if (!$order->canAcceptPayment()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot accept payments in its current state',
                ], 400);
            }

            $totalPaymentAmount = collect($request->payments)->sum('amount');
            $remainingAmount = $order->getRemainingAmount();

            if ($totalPaymentAmount > $remainingAmount) {
                return response()->json([
                    'success' => false,
                    'message' => "Total payment amount exceeds remaining balance of {$remainingAmount}",
                ], 400);
            }

            $processedPayments = [];
            $failedPayments = [];

            foreach ($request->payments as $paymentData) {
                try {
                    $paymentMethod = PaymentMethod::findOrFail($paymentData['payment_method_id']);

                    // Validate payment method is allowed for customer type
                    if (!$paymentMethod->isAllowedForCustomerType($order->customer->customer_type)) {
                        $failedPayments[] = [
                            'payment_method' => $paymentMethod->name,
                            'amount' => $paymentData['amount'],
                            'error' => 'Payment method not allowed for this customer type',
                        ];
                        continue;
                    }

                    // Create payment
                    $payment = $order->addPayment(
                        $paymentMethod,
                        $paymentData['amount'],
                        $paymentData['payment_data'] ?? [],
                        auth()->user()
                    );

                    // Process the payment
                    $transactionReference = $paymentData['payment_data']['transaction_reference'] ?? null;
                    $externalReference = $paymentData['payment_data']['external_reference'] ?? null;

                    if ($order->processPayment($payment, $transactionReference, $externalReference)) {
                        $processedPayments[] = $payment->load('paymentMethod');
                    } else {
                        $failedPayments[] = [
                            'payment_method' => $paymentMethod->name,
                            'amount' => $paymentData['amount'],
                            'error' => 'Payment processing failed',
                        ];
                    }

                } catch (\Exception $e) {
                    $failedPayments[] = [
                        'payment_method' => $paymentData['payment_method_id'],
                        'amount' => $paymentData['amount'],
                        'error' => $e->getMessage(),
                    ];
                }
            }

            if (count($processedPayments) > 0) {
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => count($processedPayments) . ' payment(s) processed successfully',
                    'data' => [
                        'processed_payments' => $processedPayments,
                        'failed_payments' => $failedPayments,
                        'order_summary' => $order->payment_summary,
                    ],
                ]);
            } else {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'All payments failed to process',
                    'data' => [
                        'failed_payments' => $failedPayments,
                    ],
                ], 400);
            }

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Multiple payment processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payments for an order
     */
    public function getOrderPayments(Order $order): JsonResponse
    {
        $payments = $order->payments()->with('paymentMethod')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'payments' => $payments,
                'summary' => $order->payment_summary,
            ],
        ]);
    }

    /**
     * Refund a payment
     */
    public function refundPayment(Request $request, OrderPayment $payment): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'refund_amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Check if payment can be refunded
            if (!$payment->isCompleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only completed payments can be refunded',
                ], 400);
            }

            // Check refund amount
            if ($request->refund_amount > $payment->getRefundableAmount()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund amount exceeds refundable balance',
                ], 400);
            }

            if ($payment->refund($request->refund_amount, $request->reason)) {
                // Update order payment status
                $payment->order->updatePaymentStatus();

                return response()->json([
                    'success' => true,
                    'message' => 'Payment refunded successfully',
                    'data' => [
                        'payment' => $payment->load('paymentMethod'),
                        'order_summary' => $payment->order->payment_summary,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund processing failed',
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Refund failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Setup installment plan for an order
     */
    public function setupInstallmentPlan(Request $request, Order $order): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'total_installments' => 'required|integer|min:2|max:12',
            'installment_amount' => 'required|numeric|min:0.01',
            'start_date' => 'nullable|date|after:today',
            'allow_partial_payments' => 'boolean',
            'minimum_payment_amount' => 'nullable|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Check if order can have installment plan
            if ($order->is_installment_payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order already has an installment plan',
                ], 400);
            }

            // Validate installment amount
            $totalInstallmentAmount = $request->total_installments * $request->installment_amount;
            if ($totalInstallmentAmount < $order->outstanding_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total installment amount must be at least the outstanding balance',
                ], 400);
            }

            $startDate = $request->start_date ? \Carbon\Carbon::parse($request->start_date) : now();

            if ($order->setupInstallmentPlan(
                $request->total_installments,
                $request->installment_amount,
                $startDate->format('Y-m-d')
            )) {
                return response()->json([
                    'success' => true,
                    'message' => 'Installment plan created successfully',
                    'data' => [
                        'order' => $order->load('payments'),
                        'installment_schedule' => $order->payment_schedule,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create installment plan',
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Installment plan setup failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add installment payment to an order
     */
    public function addInstallmentPayment(Request $request, Order $order): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'payment_data' => 'nullable|array',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Check if order can accept installment payment
            if (!$order->canAcceptInstallmentPayment()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot accept installment payments',
                ], 400);
            }

            // Validate amount matches expected installment amount
            $nextInstallment = $order->paid_installments + 1;
            if ($request->amount != $order->installment_amount) {
                return response()->json([
                    'success' => false,
                    'message' => "Installment amount must be {$order->installment_amount}",
                ], 400);
            }

            $payment = $order->addInstallmentPayment($request->amount, [
                'payment_method_id' => $request->payment_method_id,
                'payment_data' => $request->payment_data ?? [],
                'notes' => $request->notes,
            ]);

            if ($payment) {
                // Process the payment
                $transactionReference = $request->payment_data['transaction_reference'] ?? null;
                $externalReference = $request->payment_data['external_reference'] ?? null;

                if ($order->processPayment($payment, $transactionReference, $externalReference)) {
                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => "Installment {$nextInstallment} payment processed successfully",
                        'data' => [
                            'payment' => $payment->load('paymentMethod'),
                            'order_summary' => $order->payment_summary,
                            'next_installment_due' => $order->next_payment_due,
                        ],
                    ]);
                } else {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to process installment payment',
                    ], 500);
                }
            } else {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create installment payment',
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Installment payment processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add partial payment to an order
     */
    public function addPartialPayment(Request $request, Order $order): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'payment_data' => 'nullable|array',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Check if order can accept partial payment
            if (!$order->canAcceptPartialPayment()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot accept partial payments',
                ], 400);
            }

            // Check minimum payment amount
            if ($order->minimum_payment_amount && $request->amount < $order->minimum_payment_amount) {
                return response()->json([
                    'success' => false,
                    'message' => "Minimum payment amount is {$order->minimum_payment_amount}",
                ], 400);
            }

            // Check remaining amount
            $remainingAmount = $order->outstanding_amount;
            if ($request->amount > $remainingAmount) {
                return response()->json([
                    'success' => false,
                    'message' => "Payment amount exceeds remaining balance of {$remainingAmount}",
                ], 400);
            }

            $payment = $order->addPartialPayment($request->amount, [
                'payment_method_id' => $request->payment_method_id,
                'payment_data' => $request->payment_data ?? [],
                'notes' => $request->notes,
            ]);

            if ($payment) {
                // Process the payment
                $transactionReference = $request->payment_data['transaction_reference'] ?? null;
                $externalReference = $request->payment_data['external_reference'] ?? null;

                if ($order->processPayment($payment, $transactionReference, $externalReference)) {
                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'Partial payment processed successfully',
                        'data' => [
                            'payment' => $payment->load('paymentMethod'),
                            'order_summary' => $order->payment_summary,
                            'remaining_balance' => $order->outstanding_amount,
                        ],
                    ]);
                } else {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to process partial payment',
                    ], 500);
                }
            } else {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create partial payment',
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Partial payment processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get overdue payments
     */
    public function getOverduePayments(Request $request): JsonResponse
    {
        $query = Order::where('payment_status', 'overdue')
            ->orWhere(function ($q) {
                $q->whereNotNull('next_payment_due')
                  ->where('next_payment_due', '<', now())
                  ->where('outstanding_amount', '>', 0);
            })
            ->with(['customer', 'store']);

        // Filter by store
        if ($request->has('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        $overdueOrders = $query->get();

        return response()->json([
            'success' => true,
            'data' => [
                'overdue_orders' => $overdueOrders,
                'total_overdue' => $overdueOrders->sum('outstanding_amount'),
                'count' => $overdueOrders->count(),
            ],
        ]);
    }
}