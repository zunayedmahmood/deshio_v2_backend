<?php

namespace App\Observers;

use App\Models\VendorPayment;
use App\Models\Transaction as AccountingTransaction;

class VendorPaymentObserver
{
    /**
     * Handle the VendorPayment "created" event.
     */
    public function created(VendorPayment $vendorPayment): void
    {
        // Create transaction when vendor payment is created
        if ($vendorPayment->status === 'completed') {
            AccountingTransaction::createFromVendorPayment($vendorPayment);
        }
    }

    /**
     * Handle the VendorPayment "updated" event.
     */
    public function updated(VendorPayment $vendorPayment): void
    {
        // Check if status changed to completed
        if ($vendorPayment->wasChanged('status') && $vendorPayment->status === 'completed') {
            // Find existing transaction or create new one
            $transaction = AccountingTransaction::byReference(VendorPayment::class, $vendorPayment->id)->first();

            if ($transaction) {
                // Update existing transaction
                $transaction->update([
                    'status' => 'completed',
                    'transaction_date' => $vendorPayment->processed_at ?? now(),
                ]);
            } else {
                // Create new transaction if it doesn't exist
                AccountingTransaction::createFromVendorPayment($vendorPayment);
            }
        }

        // Handle vendor refunds - when vendor payment is refunded, money comes back (debit cash)
        // and inventory is returned to vendor (credit inventory)
        if ($vendorPayment->wasChanged('status') && $vendorPayment->status === 'refunded') {
            $metadata = [
                'payment_method' => $vendorPayment->paymentMethod->name ?? 'Unknown',
                'vendor_name' => $vendorPayment->vendor->name ?? null,
                'refund_reason' => 'Vendor payment refund',
            ];

            // 1. Debit Cash (money coming back to business)
            AccountingTransaction::create([
                'transaction_date' => now(),
                'amount' => $vendorPayment->amount,
                'type' => 'debit',
                'account_id' => AccountingTransaction::getCashAccountId(),
                'reference_type' => VendorPayment::class,
                'reference_id' => $vendorPayment->id,
                'description' => "Vendor Refund (Cash Received) - {$vendorPayment->payment_number}",
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => 'completed',
            ]);

            // 2. Credit Inventory (stock returned to vendor — asset decreases)
            AccountingTransaction::create([
                'transaction_date' => now(),
                'amount' => $vendorPayment->amount,
                'type' => 'credit',
                'account_id' => AccountingTransaction::getInventoryAccountId(),
                'reference_type' => VendorPayment::class,
                'reference_id' => $vendorPayment->id,
                'description' => "Vendor Refund (Inventory Returned) - {$vendorPayment->payment_number}",
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => 'completed',
            ]);
        }
    }

    /**
     * Handle the VendorPayment "deleted" event.
     */
    public function deleted(VendorPayment $vendorPayment): void
    {
        // Mark related transactions as cancelled
        AccountingTransaction::byReference(VendorPayment::class, $vendorPayment->id)
            ->update(['status' => 'cancelled']);
    }

    /**
     * Handle the VendorPayment "restored" event.
     */
    public function restored(VendorPayment $vendorPayment): void
    {
        // Restore related transactions
        $status = $vendorPayment->status === 'completed' ? 'completed' : 'pending';
        AccountingTransaction::byReference(VendorPayment::class, $vendorPayment->id)
            ->update(['status' => $status]);
    }

    /**
     * Handle the VendorPayment "force deleted" event.
     */
    public function forceDeleted(VendorPayment $vendorPayment): void
    {
        // Permanently delete related transactions
        AccountingTransaction::byReference(VendorPayment::class, $vendorPayment->id)->delete();
    }
}
