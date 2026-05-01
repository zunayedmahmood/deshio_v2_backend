<?php

namespace App\Observers;

use App\Models\Account;
use App\Models\DefectiveProduct;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class DefectiveProductObserver
{
    /**
     * TRIGGER: When a DefectiveProduct is CREATED.
     * This means a barcode/item has been marked as defective for the first time.
     *
     * ACCOUNTING: Debit "Inventory Write-off Loss" (Expense ↑) + Credit "Inventory" (Asset ↓)
     * This immediately recognises the impairment in the period it's identified.
     *
     * The write-off uses the original_price (cost) as the loss amount.
     */
    public function created(DefectiveProduct $defectiveProduct): void
    {
        try {
            $lossAmount = (float) ($defectiveProduct->original_price ?? 0);

            if ($lossAmount <= 0) {
                return; // No cost recorded — skip silently
            }

            // Idempotency: ensure no write-off entry already exists for this defective product
            $exists = Transaction::where('reference_type', DefectiveProduct::class)
                ->where('reference_id', $defectiveProduct->id)
                ->whereJsonContains('metadata->event', 'write_off')
                ->exists();

            if ($exists) {
                return;
            }

            $inventoryWriteoffAccountId = $this->getInventoryWriteoffAccountId();
            $inventoryAccountId         = Transaction::getInventoryAccountId();
            $transactionDate            = $defectiveProduct->identified_at ?? now();

            $metadata = [
                'event'               => 'write_off',
                'defective_product_id'=> $defectiveProduct->id,
                'product_id'          => $defectiveProduct->product_id,
                'product_barcode_id'  => $defectiveProduct->product_barcode_id,
                'severity'            => $defectiveProduct->severity,
                'defect_type'         => $defectiveProduct->defect_type,
                'original_price'      => $lossAmount,
            ];

            // DOUBLE-ENTRY:
            // 1. Debit Inventory Write-off Loss (Expense increases)
            Transaction::create([
                'transaction_date' => $transactionDate,
                'amount'           => $lossAmount,
                'type'             => 'debit',
                'account_id'       => $inventoryWriteoffAccountId,
                'reference_type'   => DefectiveProduct::class,
                'reference_id'     => $defectiveProduct->id,
                'description'      => "Defective Item Write-off — {$defectiveProduct->defect_type} ({$defectiveProduct->severity})",
                'store_id'         => $defectiveProduct->store_id,
                'created_by'       => $defectiveProduct->identified_by,
                'metadata'         => $metadata,
                'status'           => 'completed',
            ]);

            // 2. Credit Inventory (Asset decreases — item removed from inventory)
            Transaction::create([
                'transaction_date' => $transactionDate,
                'amount'           => $lossAmount,
                'type'             => 'credit',
                'account_id'       => $inventoryAccountId,
                'reference_type'   => DefectiveProduct::class,
                'reference_id'     => $defectiveProduct->id,
                'description'      => "Inventory Reduction — Defective Item {$defectiveProduct->id}",
                'store_id'         => $defectiveProduct->store_id,
                'created_by'       => $defectiveProduct->identified_by,
                'metadata'         => $metadata,
                'status'           => 'completed',
            ]);

        } catch (\Exception $e) {
            Log::error('DefectiveProductObserver@created failed', [
                'defective_product_id' => $defectiveProduct->id,
                'error'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * TRIGGER: When the DefectiveProduct's STATUS changes.
     * Handles two secondary accounting events:
     *   - 'returned_to_vendor' → Reverse the write-off (Debit Inventory + Credit Accounts Payable or Cash)
     *   - 'disposed'           → No additional entry needed (write-off was already booked on creation)
     */
    public function updated(DefectiveProduct $defectiveProduct): void
    {
        if (!$defectiveProduct->wasChanged('status')) {
            return;
        }

        $newStatus = $defectiveProduct->status;

        match ($newStatus) {
            'returned_to_vendor' => $this->handleReturnedToVendor($defectiveProduct),
            default              => null,
        };
    }

    /**
     * When a defective item is returned to the vendor, reverse the inventory write-off:
     *   Debit Inventory (Asset ↑ restored)  +  Credit Inventory Write-off Loss (Expense ↓ reversed)
     */
    private function handleReturnedToVendor(DefectiveProduct $defectiveProduct): void
    {
        try {
            $lossAmount = (float) ($defectiveProduct->original_price ?? 0);

            if ($lossAmount <= 0) {
                return;
            }

            // Idempotency check
            $exists = Transaction::where('reference_type', DefectiveProduct::class)
                ->where('reference_id', $defectiveProduct->id)
                ->whereJsonContains('metadata->event', 'vendor_return_reversal')
                ->exists();

            if ($exists) {
                return;
            }

            $inventoryWriteoffAccountId = $this->getInventoryWriteoffAccountId();
            $inventoryAccountId         = Transaction::getInventoryAccountId();
            $transactionDate            = $defectiveProduct->returned_to_vendor_at ?? now();

            $metadata = [
                'event'               => 'vendor_return_reversal',
                'defective_product_id'=> $defectiveProduct->id,
                'vendor_id'           => $defectiveProduct->vendor_id,
                'original_price'      => $lossAmount,
            ];

            // DOUBLE-ENTRY: Reverse the write-off
            // 1. Debit Inventory (restores asset)
            Transaction::create([
                'transaction_date' => $transactionDate,
                'amount'           => $lossAmount,
                'type'             => 'debit',
                'account_id'       => $inventoryAccountId,
                'reference_type'   => DefectiveProduct::class,
                'reference_id'     => $defectiveProduct->id,
                'description'      => "Reversal — Defective Item Returned to Vendor #{$defectiveProduct->vendor_id}",
                'store_id'         => $defectiveProduct->store_id,
                'metadata'         => $metadata,
                'status'           => 'completed',
            ]);

            // 2. Credit Inventory Write-off Loss (reduces the expense)
            Transaction::create([
                'transaction_date' => $transactionDate,
                'amount'           => $lossAmount,
                'type'             => 'credit',
                'account_id'       => $inventoryWriteoffAccountId,
                'reference_type'   => DefectiveProduct::class,
                'reference_id'     => $defectiveProduct->id,
                'description'      => "Write-off Reversal — Vendor Return for Defective Item {$defectiveProduct->id}",
                'store_id'         => $defectiveProduct->store_id,
                'metadata'         => $metadata,
                'status'           => 'completed',
            ]);

        } catch (\Exception $e) {
            Log::error('DefectiveProductObserver@handleReturnedToVendor failed', [
                'defective_product_id' => $defectiveProduct->id,
                'error'                => $e->getMessage(),
            ]);
        }
    }

    /**
     * Returns the account ID for "Inventory Write-off Loss" — an operating expense sub-account.
     * Falls back to the generic Operating Expenses account if a dedicated one doesn't exist.
     */
    private function getInventoryWriteoffAccountId(): int
    {
        // Look for a dedicated write-off/loss account first
        $account = Account::where('type', 'expense')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('name', 'like', '%Write-off%')
                  ->orWhere('name', 'like', '%Inventory Loss%')
                  ->orWhere('name', 'like', '%Shrinkage%');
            })
            ->first();

        if ($account) {
            return $account->id;
        }

        // Fallback 1: Operating Expenses
        $operatingExpenses = Account::where('type', 'expense')
            ->where('sub_type', 'operating_expense')
            ->where('is_active', true)
            ->first();

        if ($operatingExpenses) {
            return $operatingExpenses->id;
        }

        // Fallback 2: Any active expense account
        $anyExpense = Account::where('type', 'expense')
            ->where('is_active', true)
            ->first();

        return $anyExpense?->id ?? 5001; // 5001 = Operating Expenses from default chart
    }
}
