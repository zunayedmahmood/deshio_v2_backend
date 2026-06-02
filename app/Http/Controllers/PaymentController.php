<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

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
            'transaction_reference' => 'nullable|string|max:255',
            'external_reference' => 'nullable|string|max:255',
            'collected_by_name' => 'nullable|string|max:255',
            'next_collection_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $order->refresh();

            // Check if order can accept installment payment before opening a transaction.
            if (!$order->canAcceptInstallmentPayment()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot accept installment payments',
                ], 400);
            }

            // Allow partial installment payments (e.g., 700 now, 1200 later) as long as it does not exceed outstanding amount.
            $amount = (float) $request->amount;
            if ($amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment amount must be greater than 0',
                ], 422);
            }

            if ($amount > (float) $order->outstanding_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment amount exceeds outstanding amount',
                ], 422);
            }

            DB::beginTransaction();

            // Next installment number is derived from paid progress, not from the number of payment rows.
            $nextInstallment = $order->paid_installments + 1;

            $paymentMeta = $request->payment_data ?? [];
            if ($request->filled('transaction_reference') && empty($paymentMeta['transaction_reference'])) {
                $paymentMeta['transaction_reference'] = $request->transaction_reference;
            }
            if ($request->filled('external_reference') && empty($paymentMeta['external_reference'])) {
                $paymentMeta['external_reference'] = $request->external_reference;
            }
            if ($request->filled('collected_by_name')) {
                $paymentMeta['collected_by_name'] = $request->collected_by_name;
            }
            if ($request->filled('next_collection_date')) {
                $paymentMeta['next_collection_date'] = $request->next_collection_date;
            }

            $payment = $order->addInstallmentPayment($amount, [
                'payment_method_id' => $request->payment_method_id,
                'payment_data' => $paymentMeta,
                'notes' => $request->notes,
                'payment_due_date' => $request->next_collection_date,
            ]);

            if ($payment) {
                // Process the payment
                $transactionReference = $paymentMeta['transaction_reference'] ?? null;
                $externalReference = $paymentMeta['external_reference'] ?? null;

                if ($order->processPayment($payment, $transactionReference, $externalReference)) {
                    if ($request->filled('next_collection_date') && (float) $order->fresh()->outstanding_amount > 0) {
                        $order->update([
                            'next_payment_due' => $request->next_collection_date,
                        ]);
                    }

                    $order->refresh();
                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => "Installment {$nextInstallment} payment processed successfully",
                        'data' => [
                            'payment' => $payment->fresh()->load('paymentMethod'),
                            'order_summary' => $order->payment_summary,
                            'next_installment_due' => $order->next_payment_due ? $order->next_payment_due->format('Y-m-d') : null,
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
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

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

    /**
     * Import Pathao paid invoice CSV and record COD settlements safely.
     *
     * POST /api/payments/pathao-paid-invoice-csv
     * multipart/form-data: csv=<file>
     */
    public function importPathaoPaidInvoiceCsv(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'csv' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('csv');
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            return response()->json([
                'success' => false,
                'message' => 'Could not read uploaded CSV file.',
            ], 422);
        }

        $rawHeader = fgetcsv($handle);
        if (!$rawHeader) {
            fclose($handle);
            return response()->json([
                'success' => false,
                'message' => 'CSV file is empty.',
            ], 422);
        }

        $headers = array_map(fn ($h) => $this->normalizeCsvHeader((string) $h), $rawHeader);
        $merchantIdx = $this->firstHeaderIndex($headers, ['merchant_order_id', 'merchantorderid', 'order_id', 'order_number']);
        $amountIdx = $this->firstHeaderIndex($headers, ['collectable_amount', 'collectible_amount', 'amount_to_collect', 'cod_amount', 'amount', 'paid_amount']);
        $consignmentIdx = $this->firstHeaderIndex($headers, ['consignment_id', 'pathao_consignment_id', 'consignment']);
        $invoiceIdx = $this->firstHeaderIndex($headers, ['invoice_id', 'pathao_invoice_id', 'invoice']);
        $paidDateIdx = $this->firstHeaderIndex($headers, ['paid_date', 'payment_date', 'settlement_date', 'invoice_date', 'date']);

        if ($merchantIdx === null || $amountIdx === null) {
            fclose($handle);
            return response()->json([
                'success' => false,
                'message' => 'CSV must contain Merchant_Order_ID/order number and Collectable_Amount/amount columns.',
                'detected_headers' => $rawHeader,
            ], 422);
        }

        $paymentMethod = $this->getOrCreatePathaoPaymentMethod();
        $summary = [
            'processed' => 0,
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'rows' => [],
        ];

        $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $summary['processed']++;

            $merchantOrderId = trim((string) ($row[$merchantIdx] ?? ''));
            $amount = $this->parseMoney($row[$amountIdx] ?? null);
            $consignmentId = $consignmentIdx !== null ? trim((string) ($row[$consignmentIdx] ?? '')) : '';
            $invoiceId = $invoiceIdx !== null ? trim((string) ($row[$invoiceIdx] ?? '')) : '';
            $paidDate = $paidDateIdx !== null ? $this->parseCsvDate($row[$paidDateIdx] ?? null) : now()->toDateString();

            if ($merchantOrderId === '' || $amount <= 0) {
                $summary['failed']++;
                $summary['rows'][] = $this->pathaoCsvRowResult($rowNumber, $merchantOrderId, false, 'Missing order ID or invalid amount.');
                continue;
            }

            try {
                DB::beginTransaction();

                $order = Order::with(['customer', 'store', 'payments', 'shipments'])
                    ->where('order_number', $merchantOrderId)
                    ->first();

                if (!$order && ctype_digit($merchantOrderId)) {
                    $order = Order::with(['customer', 'store', 'payments', 'shipments'])->find((int) $merchantOrderId);
                }

                if (!$order) {
                    throw new \Exception('Order not found.');
                }

                if (in_array($order->status, ['cancelled', 'refunded', 'returned'], true)) {
                    throw new \Exception("Order status is {$order->status}; payment import blocked.");
                }

                $shipmentQuery = Shipment::where('order_id', $order->id)->whereNotNull('pathao_consignment_id');
                if ($consignmentId !== '') {
                    $shipmentQuery->where('pathao_consignment_id', $consignmentId);
                }
                $pathaoShipment = $shipmentQuery->latest()->first();

                if (!$pathaoShipment) {
                    throw new \Exception($consignmentId !== ''
                        ? 'No matching Pathao consignment found for this order.'
                        : 'Order has no Pathao consignment. Import blocked to avoid paying a non-Pathao order.');
                }

                $externalReference = 'PATHAO-PAID-INVOICE-' . ($consignmentId ?: $pathaoShipment->pathao_consignment_id) . '-' . ($invoiceId ?: 'NOINVOICE');
                $duplicateQuery = OrderPayment::where('external_reference', $externalReference);
                if ($consignmentId !== '') {
                    $duplicateQuery->orWhere(function ($q) use ($consignmentId, $invoiceId) {
                        $q->where('transaction_reference', $consignmentId);
                        if ($invoiceId !== '') {
                            $q->where('external_reference', 'like', '%' . $invoiceId . '%');
                        }
                    });
                }
                $alreadyImported = $duplicateQuery->exists();

                if ($alreadyImported) {
                    DB::rollBack();
                    $summary['skipped']++;
                    $summary['rows'][] = $this->pathaoCsvRowResult($rowNumber, $order->order_number, true, 'Already imported earlier.', 'skipped');
                    continue;
                }

                $remainingAmount = max(0, (float) $order->getRemainingAmount());
                if ($remainingAmount <= 0) {
                    DB::rollBack();
                    $summary['skipped']++;
                    $summary['rows'][] = $this->pathaoCsvRowResult($rowNumber, $order->order_number, true, 'Order already fully paid.', 'skipped');
                    continue;
                }

                if ($amount - $remainingAmount > 0.01) {
                    throw new \Exception("CSV amount {$amount} exceeds remaining balance {$remainingAmount}.");
                }

                $paymentType = $amount + 0.01 >= $remainingAmount
                    ? ((float) $order->getTotalPaidAmount() > 0 ? 'final' : 'full')
                    : 'partial';

                $payment = OrderPayment::create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentMethod->id,
                    'customer_id' => $order->customer_id,
                    'store_id' => $order->store_id,
                    'processed_by' => auth()->id(),
                    'amount' => $amount,
                    'fee_amount' => 0,
                    'net_amount' => $amount,
                    'is_partial_payment' => $paymentType !== 'full',
                    'payment_type' => $paymentType,
                    'payment_received_date' => $paidDate,
                    'order_balance_before' => $remainingAmount,
                    'order_balance_after' => max(0, $remainingAmount - $amount),
                    'status' => 'pending',
                    'transaction_reference' => $consignmentId ?: $pathaoShipment->pathao_consignment_id,
                    'external_reference' => $externalReference,
                    'payment_data' => [
                        'source' => 'pathao_paid_invoice_csv',
                        'invoice_id' => $invoiceId,
                        'csv_row' => $rowNumber,
                    ],
                    'metadata' => [
                        'pathao_consignment_id' => $pathaoShipment->pathao_consignment_id,
                        'uploaded_filename' => $file->getClientOriginalName(),
                        'raw_row' => array_combine($headers, array_pad($row, count($headers), null)),
                    ],
                    'notes' => 'Imported from Pathao paid invoice CSV.',
                ]);

                if (!$order->processPayment($payment, $payment->transaction_reference, $payment->external_reference)) {
                    throw new \Exception('Payment record was created but could not be completed.');
                }

                DB::commit();
                $summary['created']++;
                $summary['rows'][] = $this->pathaoCsvRowResult($rowNumber, $order->order_number, true, 'Payment imported.', 'created', $payment->id);
            } catch (\Exception $e) {
                DB::rollBack();
                $summary['failed']++;
                $summary['rows'][] = $this->pathaoCsvRowResult($rowNumber, $merchantOrderId, false, $e->getMessage());
            }
        }
        fclose($handle);

        return response()->json([
            'success' => true,
            'message' => "Pathao CSV import complete. Created {$summary['created']}, skipped {$summary['skipped']}, failed {$summary['failed']}.",
            'data' => $summary,
        ]);
    }

    protected function normalizeCsvHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header);
        return trim($header, '_');
    }

    protected function firstHeaderIndex(array $headers, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            $idx = array_search($candidate, $headers, true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }
        return null;
    }

    protected function parseMoney($value): float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
        return $clean === '' ? 0.0 : round((float) $clean, 2);
    }

    protected function parseCsvDate($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return now()->toDateString();
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Exception $e) {
            return now()->toDateString();
        }
    }

    protected function getOrCreatePathaoPaymentMethod(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => 'pathao_cod'],
            [
                'name' => 'Pathao COD Settlement',
                'description' => 'COD settlement received from Pathao paid invoice CSV.',
                'type' => 'online_banking',
                'allowed_customer_types' => ['social_commerce', 'ecommerce'],
                'is_active' => true,
                'requires_reference' => true,
                'supports_partial' => true,
                'fixed_fee' => 0,
                'percentage_fee' => 0,
                'sort_order' => 50,
            ]
        );
    }

    protected function pathaoCsvRowResult(int $rowNumber, ?string $orderNumber, bool $success, string $message, string $status = null, ?int $paymentId = null): array
    {
        return [
            'row' => $rowNumber,
            'order_number' => $orderNumber,
            'success' => $success,
            'status' => $status ?: ($success ? 'ok' : 'failed'),
            'message' => $message,
            'payment_id' => $paymentId,
        ];
    }

}