<?php

namespace App\Observers;

use App\Models\ProductReturn;
use App\Models\Transaction as AccountingTransaction;

class ProductReturnObserver
{
    /**
     * Handle the ProductReturn "updated" event.
     *
     * This fires when a return is updated. The key trigger from the accounting plan is:
     * When `quality_check_passed` is set to `true`, the items are going back into stock.
     * We must record the COGS/Inventory reversal at this point.
     */
    public function updated(ProductReturn $productReturn): void
    {
        // Trigger COGS/Inventory reversal when QC passes (items restored to stock)
        if ($productReturn->wasChanged('quality_check_passed') && $productReturn->quality_check_passed === true) {
            $this->createCOGSReversalIfNeeded($productReturn);
        }

        // Also trigger if status changes to 'completed' (another indicator of restocking)
        if ($productReturn->wasChanged('status') && $productReturn->status === 'completed') {
            $this->createCOGSReversalIfNeeded($productReturn);
        }
    }

    /**
     * Handle the ProductReturn "created" event.
     * No accounting entries needed at creation — only when QC passes or status completes.
     */
    public function created(ProductReturn $productReturn): void
    {
        // No accounting entries on creation — wait for QC or completion.
    }

    /**
     * Create COGS/Inventory reversal entries if items are being restocked
     * and the entry hasn't been created yet (idempotency guard).
     */
    private function createCOGSReversalIfNeeded(ProductReturn $productReturn): void
    {
        $returnValue = (float)($productReturn->total_return_value ?? 0);

        if ($returnValue <= 0) {
            return; // No cost value to reverse
        }

        // Idempotency: don't create duplicate entries
        $alreadyExists = AccountingTransaction::where('reference_type', ProductReturn::class)
            ->where('reference_id', $productReturn->id)
            ->where('type', 'debit') // Inventory debit = restocking entry
            ->exists();

        if (!$alreadyExists) {
            AccountingTransaction::createFromRefundCOGS($productReturn);
        }
    }
}
