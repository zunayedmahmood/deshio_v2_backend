<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Raziul\Sslcommerz\Facades\Sslcommerz;
use App\Models\Order;
use App\Models\OrderPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SslcommerzController extends Controller
{
    /**
     * Get frontend URL from environment or fallback
     */
    private function getFrontendUrl(): string
    {
        return rtrim(env('FRONTEND_URL', 'https://errum-v2.vercel.app'), '/');
    }

    /**
     * Map SSLCommerz card_type to internal payment method
     * 
     * SSLCommerz returns card_type values like:
     * - "NAGADMFS" or "NAGAD-Nagad" for Nagad
     * - "ROCKETMFS" or "ROCKET-DBBL" for Rocket  
     * - "DBBLMOBILEBANKING" or "BKASH-BKash" for bKash
     * - "VISA-Dutch Bangla", "MASTERCARD", "AMEX" for cards
     * - "INTERNETBANKING" for online banking
     */
    private function detectPaymentMethod(string $cardType): string
    {
        $cardTypeUpper = strtoupper($cardType);
        
        // Mobile Banking Detection
        if (str_contains($cardTypeUpper, 'NAGAD')) {
            return 'nagad';
        }
        if (str_contains($cardTypeUpper, 'ROCKET')) {
            return 'rocket';
        }
        if (str_contains($cardTypeUpper, 'BKASH') || str_contains($cardTypeUpper, 'DBBLMOBILEBANKING')) {
            return 'bkash';
        }
        
        // Card Detection
        if (str_contains($cardTypeUpper, 'VISA') || 
            str_contains($cardTypeUpper, 'MASTERCARD') || 
            str_contains($cardTypeUpper, 'MASTER CARD') ||
            str_contains($cardTypeUpper, 'AMEX') || 
            str_contains($cardTypeUpper, 'AMERICANEXPRESS')) {
            return 'card';
        }
        
        // Internet Banking
        if (str_contains($cardTypeUpper, 'INTERNETBANKING') || str_contains($cardTypeUpper, 'ONLINE')) {
            return 'online_banking';
        }
        
        // Default fallback
        return 'sslcommerz';
    }
    public function success(Request $request)
    {
        // Verify hash
        if (!Sslcommerz::verifyHash($request->all())) {
            return response()->json(['message' => 'Invalid hash'], 400);
        }

        $transactionId = $request->input('tran_id');
        $amount = $request->input('amount');
        $valId = $request->input('val_id');

        // Validate payment with SSLCommerz
        $isValid = Sslcommerz::validatePayment($request->all(), $valId, $amount);

        if (!$isValid) {
            return response()->json(['message' => 'Payment validation failed'], 400);
        }

        DB::beginTransaction();
        try {
            // Find order by transaction_id stored in order_id field
            $order = Order::where('id', $request->input('value_a'))->firstOrFail();

            // Update payment status
            $payment = OrderPayment::where('order_id', $order->id)
                ->where('transaction_reference', $transactionId)
                ->first();

            if ($payment) {
                // Calculate fee from SSLCommerz response
                $amount = floatval($request->input('amount'));
                $storeAmount = floatval($request->input('store_amount', $amount));
                $fee = $amount - $storeAmount;

                // Detect actual payment method from card_type
                $cardType = $request->input('card_type', '');
                $actualPaymentMethod = $this->detectPaymentMethod($cardType);

                $payment->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'fee_amount' => $fee,
                    'net_amount' => $storeAmount,
                    'external_reference' => $request->input('bank_tran_id'),
                    'payment_data' => $request->all(),
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'actual_payment_method' => $actualPaymentMethod,
                        'card_type' => $cardType,
                        'card_issuer' => $request->input('card_issuer'),
                        'card_brand' => $request->input('card_brand'),
                    ])
                ]);

                // CRITICAL: Update order payment status and payment method
                $order->updatePaymentStatus();
                
                // Update order's payment_method to reflect actual method used
                $order->update(['payment_method' => $actualPaymentMethod]);
            }

            // Update order status only after verified payment
            $order->update(['status' => 'pending_assignment']);

            DB::commit();

            return redirect($this->getFrontendUrl() . '/e-commerce/order-confirmation/' . $order->order_number);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SSLCommerz success callback error: ' . $e->getMessage());
            return redirect($this->getFrontendUrl() . '/e-commerce/checkout?error=payment_processing_error');
        }
    }

    public function failure(Request $request)
    {
        $transactionId = $request->input('tran_id');
        
        DB::beginTransaction();
        try {
            $order = Order::where('id', $request->input('value_a'))->first();
            
            if ($order) {
                $payment = OrderPayment::where('order_id', $order->id)
                    ->where('transaction_reference', $transactionId)
                    ->first();

                if ($payment) {
                    $payment->update([
                        'status' => 'failed',
                        'failed_at' => now(),
                        'failure_reason' => $request->input('error', 'Payment failed'),
                        'payment_data' => $request->all()
                    ]);

                    // Update order payment status
                    $order->updatePaymentStatus();
                }

                // Keep order open for retry. Do NOT mark as paid/completed.
                $order->update([
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                ]);
            }

            DB::commit();

            return redirect($this->getFrontendUrl() . '/e-commerce/checkout?error=payment_failed&tran_id=' . $transactionId);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SSLCommerz failure callback error: ' . $e->getMessage());
            return redirect($this->getFrontendUrl() . '/e-commerce/checkout?error=payment_failure_processing_error');
        }
    }

    public function cancel(Request $request)
    {
        $transactionId = $request->input('tran_id');
        
        DB::beginTransaction();
        try {
            $order = Order::where('id', $request->input('value_a'))->first();
            
            if ($order) {
                $payment = OrderPayment::where('order_id', $order->id)
                    ->where('transaction_reference', $transactionId)
                    ->first();

                if ($payment) {
                    $payment->update([
                        'status' => 'cancelled',
                        'payment_data' => $request->all()
                    ]);

                    // Update order payment status
                    $order->updatePaymentStatus();
                }

                // User backed out; keep order open for retry.
                $order->update([
                    'status' => 'pending',
                    'payment_status' => 'unpaid',
                ]);
            }

            DB::commit();

            return redirect($this->getFrontendUrl() . '/e-commerce/checkout?error=payment_cancelled&tran_id=' . $transactionId);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SSLCommerz cancel callback error: ' . $e->getMessage());
            return redirect($this->getFrontendUrl() . '/e-commerce/checkout?error=payment_cancel_processing_error');
        }
    }

    public function ipn(Request $request)
    {
        // Verify hash
        if (!Sslcommerz::verifyHash($request->all())) {
            return response()->json(['message' => 'Invalid hash'], 400);
        }

        $transactionId = $request->input('tran_id');
        $status = $request->input('status');

        DB::beginTransaction();
        try {
            $order = Order::where('id', $request->input('value_a'))->first();
            
            if ($order) {
                $payment = OrderPayment::where('order_id', $order->id)
                    ->where('transaction_reference', $transactionId)
                    ->first();

                if ($payment) {
                    $paymentStatus = match($status) {
                        'VALID', 'VALIDATED' => 'completed',
                        'FAILED' => 'failed',
                        'CANCELLED' => 'cancelled',
                        default => 'pending'
                    };

                    // Calculate fee for completed payments
                    $updateData = [
                        'status' => $paymentStatus,
                        'payment_data' => $request->all()
                    ];

                    if ($paymentStatus === 'completed') {
                        $amount = floatval($request->input('amount'));
                        $storeAmount = floatval($request->input('store_amount', $amount));
                        $fee = $amount - $storeAmount;

                        // Detect actual payment method from card_type
                        $cardType = $request->input('card_type', '');
                        $actualPaymentMethod = $this->detectPaymentMethod($cardType);

                        $updateData['completed_at'] = now();
                        $updateData['fee_amount'] = $fee;
                        $updateData['net_amount'] = $storeAmount;
                        $updateData['external_reference'] = $request->input('bank_tran_id');
                        $updateData['metadata'] = array_merge($payment->metadata ?? [], [
                            'actual_payment_method' => $actualPaymentMethod,
                            'card_type' => $cardType,
                            'card_issuer' => $request->input('card_issuer'),
                            'card_brand' => $request->input('card_brand'),
                        ]);
                        
                        // Update order's payment_method
                        $order->update(['payment_method' => $actualPaymentMethod]);
                    } elseif ($paymentStatus === 'failed') {
                        $updateData['failed_at'] = now();
                        $updateData['failure_reason'] = $request->input('error', 'Payment failed');
                    }

                    $payment->update($updateData);

                    // Update order payment status
                    $order->updatePaymentStatus();
                }

                if ($status === 'VALID' || $status === 'VALIDATED') {
                    $order->update(['status' => 'pending_assignment']);
                } else {
                    // Keep order open for retry when payment is not completed
                    $order->update([
                        'status' => 'pending',
                        'payment_status' => 'unpaid',
                    ]);
                }
            }

            DB::commit();

            return response()->json(['message' => 'IPN processed']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SSLCommerz IPN error: ' . $e->getMessage());
            return response()->json(['message' => 'Error processing IPN'], 500);
        }
    }
}
