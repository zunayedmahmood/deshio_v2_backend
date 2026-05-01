<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EcommercePaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:customer');
    }

    /**
     * Get available payment methods
     */
    public function getPaymentMethods(): JsonResponse
    {
        try {
            $paymentMethods = [
                [
                    'id' => 'cash_on_delivery',
                    'name' => 'Cash on Delivery',
                    'description' => 'Pay with cash when your order is delivered',
                    'icon' => 'cash-icon',
                    'fee' => 0,
                    'is_online' => false,
                    'is_active' => true,
                ],
                [
                    'id' => 'bkash',
                    'name' => 'bKash',
                    'description' => 'Pay using bKash mobile banking',
                    'icon' => 'bkash-icon',
                    'fee' => 0,
                    'is_online' => true,
                    'is_active' => true,
                    'instructions' => 'You will be redirected to bKash payment page',
                ],
                [
                    'id' => 'nagad',
                    'name' => 'Nagad',
                    'description' => 'Pay using Nagad mobile banking',
                    'icon' => 'nagad-icon',
                    'fee' => 0,
                    'is_online' => true,
                    'is_active' => true,
                    'instructions' => 'You will be redirected to Nagad payment page',
                ],
                [
                    'id' => 'rocket',
                    'name' => 'Rocket',
                    'description' => 'Pay using Rocket mobile banking',
                    'icon' => 'rocket-icon',
                    'fee' => 0,
                    'is_online' => true,
                    'is_active' => true,
                ],
                [
                    'id' => 'credit_card',
                    'name' => 'Credit/Debit Card',
                    'description' => 'Pay using Visa, MasterCard, or American Express',
                    'icon' => 'card-icon',
                    'fee' => 0,
                    'is_online' => true,
                    'is_active' => true,
                    'instructions' => 'Secure payment processing via SSL encryption',
                ],
                [
                    'id' => 'bank_transfer',
                    'name' => 'Bank Transfer',
                    'description' => 'Direct bank transfer to our account',
                    'icon' => 'bank-icon',
                    'fee' => 0,
                    'is_online' => false,
                    'is_active' => true,
                    'instructions' => 'Bank details will be provided after order confirmation',
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_methods' => $paymentMethods,
                    'default_method' => 'cash_on_delivery',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment methods',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process payment for order
     */
    public function processPayment(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_number' => 'required|string',
                'payment_method' => 'required|string|in:cash_on_delivery,bkash,nagad,rocket,credit_card,bank_transfer',
                'payment_data' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();
            
            $order = Order::where('customer_id', $customerId)
                ->where('order_number', $request->order_number)
                ->firstOrFail();

            if ($order->payment_status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment already completed for this order',
                ], 400);
            }

            $paymentResult = $this->processPaymentByMethod(
                $order,
                $request->payment_method,
                $request->payment_data ?? []
            );

            return response()->json([
                'success' => true,
                'message' => 'Payment processed successfully',
                'data' => $paymentResult,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify payment status
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_number' => 'required|string',
                'transaction_id' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();
            
            $order = Order::where('customer_id', $customerId)
                ->where('order_number', $request->order_number)
                ->with('orderPayments')
                ->firstOrFail();

            $verification = $this->verifyPaymentStatus($order, $request->transaction_id);

            return response()->json([
                'success' => true,
                'data' => [
                    'order' => $order,
                    'payment_status' => $order->payment_status,
                    'verification' => $verification,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment verification failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment history for customer
     */
    public function paymentHistory(Request $request): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $perPage = $request->query('per_page', 15);
            $status = $request->query('status');

            $query = OrderPayment::whereHas('order', function($q) use ($customerId) {
                $q->where('customer_id', $customerId);
            })
            ->with(['order', 'paymentMethod'])
            ->orderBy('created_at', 'desc');

            if ($status) {
                $query->where('status', $status);
            }

            $payments = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'payments' => $payments->items(),
                    'pagination' => [
                        'current_page' => $payments->currentPage(),
                        'total_pages' => $payments->lastPage(),
                        'per_page' => $payments->perPage(),
                        'total' => $payments->total(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Request refund
     */
    public function requestRefund(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_number' => 'required|string',
                'reason' => 'required|string|max:500',
                'refund_method' => 'required|string|in:original_payment,bank_transfer,mobile_banking',
                'bank_details' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();
            
            $order = Order::where('customer_id', $customerId)
                ->where('order_number', $request->order_number)
                ->firstOrFail();

            if (!$this->canRequestRefund($order)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund cannot be requested for this order',
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Create refund request
                $refundData = [
                    'order_id' => $order->id,
                    'customer_id' => $customerId,
                    'amount' => $order->total_amount,
                    'reason' => $request->reason,
                    'refund_method' => $request->refund_method,
                    'status' => 'pending',
                    'requested_at' => now(),
                ];

                if ($request->bank_details) {
                    $refundData['bank_details'] = json_encode($request->bank_details);
                }

                // In a real app, you'd save to a refunds table
                // For demo purposes, we'll update the order
                $order->update([
                    'refund_status' => 'requested',
                    'refund_reason' => $request->reason,
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Refund request submitted successfully',
                    'data' => [
                        'refund_request' => $refundData,
                        'estimated_processing_days' => $this->getRefundProcessingDays($request->refund_method),
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process refund request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Private helper methods

    private function processPaymentByMethod(Order $order, string $method, array $paymentData): array
    {
        switch ($method) {
            case 'cash_on_delivery':
                return $this->processCashOnDelivery($order);
                
            case 'bkash':
                return $this->processBkashPayment($order, $paymentData);
                
            case 'nagad':
                return $this->processNagadPayment($order, $paymentData);
                
            case 'rocket':
                return $this->processRocketPayment($order, $paymentData);
                
            case 'credit_card':
                return $this->processCreditCardPayment($order, $paymentData);
                
            case 'bank_transfer':
                return $this->processBankTransfer($order);
                
            default:
                throw new \Exception('Invalid payment method');
        }
    }

    private function processCashOnDelivery(Order $order): array
    {
        $order->update([
            'payment_status' => 'pending',
            'status' => 'confirmed',
        ]);

        $this->createPaymentRecord($order, [
            'payment_method' => 'cash_on_delivery',
            'status' => 'pending',
            'amount' => $order->total_amount,
        ]);

        return [
            'payment_method' => 'cash_on_delivery',
            'status' => 'pending',
            'message' => 'Order confirmed. Pay when delivered.',
            'order_status' => 'confirmed',
        ];
    }

    private function processBkashPayment(Order $order, array $data): array
    {
        // In real implementation, integrate with bKash API
        $transactionId = 'BKT' . time() . random_int(1000, 9999);
        
        $order->update([
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);

        $this->createPaymentRecord($order, [
            'payment_method' => 'bkash',
            'status' => 'completed',
            'amount' => $order->total_amount,
            'transaction_id' => $transactionId,
            'gateway_response' => json_encode(['status' => 'success', 'trx_id' => $transactionId]),
        ]);

        return [
            'payment_method' => 'bkash',
            'status' => 'completed',
            'transaction_id' => $transactionId,
            'message' => 'Payment successful via bKash',
            'order_status' => 'confirmed',
        ];
    }

    private function processNagadPayment(Order $order, array $data): array
    {
        // In real implementation, integrate with Nagad API
        $transactionId = 'NGD' . time() . random_int(1000, 9999);
        
        $order->update([
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);

        $this->createPaymentRecord($order, [
            'payment_method' => 'nagad',
            'status' => 'completed',
            'amount' => $order->total_amount,
            'transaction_id' => $transactionId,
            'gateway_response' => json_encode(['status' => 'success', 'trx_id' => $transactionId]),
        ]);

        return [
            'payment_method' => 'nagad',
            'status' => 'completed',
            'transaction_id' => $transactionId,
            'message' => 'Payment successful via Nagad',
            'order_status' => 'confirmed',
        ];
    }

    private function processRocketPayment(Order $order, array $data): array
    {
        // In real implementation, integrate with Rocket API
        $transactionId = 'RKT' . time() . random_int(1000, 9999);
        
        $order->update([
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);

        $this->createPaymentRecord($order, [
            'payment_method' => 'rocket',
            'status' => 'completed',
            'amount' => $order->total_amount,
            'transaction_id' => $transactionId,
        ]);

        return [
            'payment_method' => 'rocket',
            'status' => 'completed',
            'transaction_id' => $transactionId,
            'message' => 'Payment successful via Rocket',
            'order_status' => 'confirmed',
        ];
    }

    private function processCreditCardPayment(Order $order, array $data): array
    {
        // In real implementation, integrate with payment gateway like Stripe or local provider
        $transactionId = 'CC' . time() . random_int(1000, 9999);
        
        $order->update([
            'payment_status' => 'paid',
            'status' => 'confirmed',
        ]);

        $this->createPaymentRecord($order, [
            'payment_method' => 'credit_card',
            'status' => 'completed',
            'amount' => $order->total_amount,
            'transaction_id' => $transactionId,
            'gateway_response' => json_encode(['status' => 'success', 'card_last4' => '****']),
        ]);

        return [
            'payment_method' => 'credit_card',
            'status' => 'completed',
            'transaction_id' => $transactionId,
            'message' => 'Payment successful via Credit Card',
            'order_status' => 'confirmed',
        ];
    }

    private function processBankTransfer(Order $order): array
    {
        $order->update([
            'payment_status' => 'pending',
            'status' => 'confirmed',
        ]);

        $this->createPaymentRecord($order, [
            'payment_method' => 'bank_transfer',
            'status' => 'pending',
            'amount' => $order->total_amount,
        ]);

        return [
            'payment_method' => 'bank_transfer',
            'status' => 'pending',
            'message' => 'Transfer funds to provided bank details',
            'bank_details' => $this->getBankDetails(),
            'order_status' => 'confirmed',
        ];
    }

    private function createPaymentRecord(Order $order, array $data): void
    {
        OrderPayment::create([
            'order_id' => $order->id,
            'payment_method' => $data['payment_method'],
            'amount' => $data['amount'],
            'status' => $data['status'],
            'transaction_id' => $data['transaction_id'] ?? null,
            'gateway_response' => $data['gateway_response'] ?? null,
        ]);
    }

    private function verifyPaymentStatus(Order $order, ?string $transactionId): array
    {
        // In real implementation, verify with respective payment gateways
        $lastPayment = $order->orderPayments()->latest()->first();
        
        if (!$lastPayment) {
            return [
                'status' => 'not_found',
                'message' => 'No payment record found',
            ];
        }

        // Simulate verification
        $isValid = $lastPayment->status === 'completed' || 
                   ($transactionId && $lastPayment->transaction_id === $transactionId);

        return [
            'status' => $isValid ? 'verified' : 'failed',
            'transaction_id' => $lastPayment->transaction_id,
            'payment_method' => $lastPayment->payment_method,
            'amount' => $lastPayment->amount,
            'verified_at' => now(),
        ];
    }

    private function canRequestRefund(Order $order): bool
    {
        return $order->payment_status === 'paid' &&
               in_array($order->status, ['completed', 'cancelled']) &&
               $order->created_at->diffInDays(now()) <= 30;
    }

    private function getRefundProcessingDays(string $method): int
    {
        switch ($method) {
            case 'original_payment':
                return 3;
            case 'mobile_banking':
                return 1;
            case 'bank_transfer':
                return 5;
            default:
                return 7;
        }
    }

    private function getBankDetails(): array
    {
        return [
            'bank_name' => 'Dutch-Bangla Bank Limited',
            'account_name' => 'Deshio ERP Ltd',
            'account_number' => '1234567890123',
            'routing_number' => '090272626',
            'reference' => 'Order payment - Include order number in reference',
        ];
    }
}