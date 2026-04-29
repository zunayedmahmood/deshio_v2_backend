<?php

namespace App\Observers;

use App\Models\OrderPayment;
use App\Models\Transaction as AccountingTransaction;
use Illuminate\Support\Str;

class OrderPaymentObserver
{
    /**
     * Handle the OrderPayment "created" event.
     */
    public function created(OrderPayment $orderPayment): void
    {
        // Skip exchange balance carryover and store credit payments — these are not
        // real cash inflows. The cash/revenue side is already handled by createFromExchange().
        $nonCashTypes = ['exchange_balance', 'store_credit', 'balance_carryover', 'exchange_surplus'];
        if (in_array($orderPayment->payment_type, $nonCashTypes)) {
            return;
        }

        // Create transaction when payment is created
        AccountingTransaction::createFromOrderPayment($orderPayment);
    }

    /**
     * Handle the OrderPayment "updated" event.
     */
    public function updated(OrderPayment $orderPayment): void
    {
        // Check if status changed to completed
        if ($orderPayment->wasChanged('status') && $orderPayment->status === 'completed') {
            // Find existing transaction or create new one
            $transaction = AccountingTransaction::byReference(OrderPayment::class, $orderPayment->id)->first();

            if ($transaction) {
                // Update existing transaction
                $transaction->update([
                    'status' => 'completed',
                    'transaction_date' => $orderPayment->completed_at ?? now(),
                ]);
            } else {
                // Create new transaction if it doesn't exist
                AccountingTransaction::createFromOrderPayment($orderPayment);
            }
        }

        // Handle payment-level refunds (partial refund applied directly to this payment record)
        if ($orderPayment->wasChanged('refunded_amount') && $orderPayment->refunded_amount > 0) {
            $refundAmount  = (float) $orderPayment->refunded_amount;
            $order         = $orderPayment->order;
            $groupId       = (string) \Illuminate\Support\Str::uuid();

            // Calculate proportional tax reversal (inclusive tax system)
            if ($order && $order->total_amount > 0 && $order->tax_amount > 0) {
                $taxRatio  = (float) $order->tax_amount / (float) $order->total_amount;
                $taxAmount = round($refundAmount * $taxRatio, 2);
            } else {
                $taxAmount = 0;
            }
            $revenueAmount = $refundAmount - $taxAmount;

            $metadata = [
                'payment_method'  => $orderPayment->paymentMethod->name ?? 'Unknown',
                'order_number'    => $order->order_number ?? null,
                'refund_reason'   => 'Payment refund',
                'includes_tax_reversal' => $taxAmount > 0,
                'tax_amount_reversed'   => $taxAmount,
                'group_id'        => $groupId,
            ];

            // 1. Credit Cash (asset decreases — money returned to customer)
            AccountingTransaction::create([
                'transaction_date' => now(),
                'amount'           => $refundAmount,
                'type'             => 'credit',
                'account_id'       => AccountingTransaction::getCashAccountId($orderPayment->store_id),
                'reference_type'   => OrderPayment::class,
                'reference_id'     => $orderPayment->id,
                'description'      => "Refund (Cash Out) - {$orderPayment->payment_number}",
                'store_id'         => $orderPayment->store_id,
                'created_by'       => auth()->id(),
                'metadata'         => $metadata,
                'status'           => 'completed',
            ]);

            // 2. Debit Sales Revenue (revenue reversal — net of tax)
            AccountingTransaction::create([
                'transaction_date' => now(),
                'amount'           => $revenueAmount,
                'type'             => 'debit',
                'account_id'       => AccountingTransaction::getSalesRevenueAccountId(),
                'reference_type'   => OrderPayment::class,
                'reference_id'     => $orderPayment->id,
                'description'      => "Refund - Revenue Reversal (excl. tax) - {$orderPayment->payment_number}",
                'store_id'         => $orderPayment->store_id,
                'created_by'       => auth()->id(),
                'metadata'         => $metadata,
                'status'           => 'completed',
            ]);

            // 3. Debit Tax Liability (tax reversal — reduce collected tax)
            if ($taxAmount > 0) {
                AccountingTransaction::create([
                    'transaction_date' => now(),
                    'amount'           => $taxAmount,
                    'type'             => 'debit',
                    'account_id'       => AccountingTransaction::getTaxLiabilityAccountId(),
                    'reference_type'   => OrderPayment::class,
                    'reference_id'     => $orderPayment->id,
                    'description'      => "Refund - Tax Reversal - {$orderPayment->payment_number}",
                    'store_id'         => $orderPayment->store_id,
                    'created_by'       => auth()->id(),
                    'metadata'         => $metadata,
                    'status'           => 'completed',
                ]);
            }
        }
    }

    /**
     * Handle the OrderPayment "deleted" event.
     */
    public function deleted(OrderPayment $orderPayment): void
    {
        // Mark related transactions as cancelled
        AccountingTransaction::byReference(OrderPayment::class, $orderPayment->id)
            ->update(['status' => 'cancelled']);
    }

    /**
     * Handle the OrderPayment "restored" event.
     */
    public function restored(OrderPayment $orderPayment): void
    {
        // Restore related transactions
        AccountingTransaction::byReference(OrderPayment::class, $orderPayment->id)
            ->update(['status' => 'completed']);
    }

    /**
     * Handle the OrderPayment "force deleted" event.
     */
    public function forceDeleted(OrderPayment $orderPayment): void
    {
        // Permanently delete related transactions
        AccountingTransaction::byReference(OrderPayment::class, $orderPayment->id)->delete();
    }
}
