<?php

namespace App\Observers;

use App\Models\Refund;
use App\Models\ProductReturn;
use App\Models\Transaction as AccountingTransaction;

class RefundObserver
{
    /**
     * Handle the Refund "created" event.
     */
    public function created(Refund $refund): void
    {
        // Skip ledger creation for exchange refunds as it's handled by Transaction::createFromExchange
        if ($refund->refund_type === 'exchange_refund') {
            return;
        }

        // Create cash/revenue transaction when refund is created
        AccountingTransaction::createFromRefund($refund);

        // Also create COGS/Inventory reversal if there is a linked restocked ProductReturn
        $this->createCOGSReversalIfApplicable($refund);
    }

    /**
     * Handle the Refund "updated" event.
     */
    public function updated(Refund $refund): void
    {
        // Check if status changed to completed
        if ($refund->wasChanged('status') && $refund->status === 'completed') {
            // Find existing transaction or create new one
            $transaction = AccountingTransaction::byReference(Refund::class, $refund->id)->first();

            if ($transaction) {
                // Update existing transaction to completed
                $transaction->update([
                    'status' => 'completed',
                    'transaction_date' => $refund->completed_at ?? now(),
                ]);
            } else {
                // Create new transaction if it doesn't exist
                AccountingTransaction::createFromRefund($refund);
            }

            // Create COGS/Inventory reversal if applicable (on completion)
            $this->createCOGSReversalIfApplicable($refund);
        }
    }

    /**
     * Handle the Refund "deleted" event.
     */
    public function deleted(Refund $refund): void
    {
        // Mark related transactions as cancelled
        AccountingTransaction::byReference(Refund::class, $refund->id)
            ->update(['status' => 'cancelled']);
    }

    /**
     * Handle the Refund "restored" event.
     */
    public function restored(Refund $refund): void
    {
        // Restore related transactions
        AccountingTransaction::byReference(Refund::class, $refund->id)
            ->update(['status' => 'completed']);
    }

    /**
     * Handle the Refund "force deleted" event.
     */
    public function forceDeleted(Refund $refund): void
    {
        // Permanently delete related transactions
        AccountingTransaction::byReference(Refund::class, $refund->id)->delete();
    }

    /**
     * Create COGS/Inventory reversal if the refund has a linked ProductReturn
     * with a positive total_return_value (items confirmed restocked).
     * Uses order_id to find the most recent completed ProductReturn for this order.
     */
    private function createCOGSReversalIfApplicable(Refund $refund): void
    {
        if (!$refund->order_id) {
            return;
        }

        // Find linked ProductReturn for the same order
        $productReturn = ProductReturn::where('order_id', $refund->order_id)
            ->whereIn('status', ['completed', 'refunded'])
            ->latest()
            ->first();

        if (!$productReturn || (float)$productReturn->total_return_value <= 0) {
            return;
        }

        // Avoid duplicate COGS reversal entries for this ProductReturn
        $existingCOGS = AccountingTransaction::where('reference_type', ProductReturn::class)
            ->where('reference_id', $productReturn->id)
            ->where('type', 'debit') // Inventory debit = restocking entry
            ->exists();

        if (!$existingCOGS) {
            AccountingTransaction::createFromRefundCOGS($productReturn);
        }
    }
}
