<?php

namespace App\Observers;

use App\Models\ExpensePayment;
use App\Models\Transaction as AccountingTransaction;

class ExpensePaymentObserver
{
    /**
     * Handle the ExpensePayment "created" event.
     */
    public function created(ExpensePayment $expensePayment): void
    {
        // Create transaction when expense payment is created (if completed)
        if ($expensePayment->status === 'completed') {
            AccountingTransaction::createFromExpensePayment($expensePayment);
        }
    }

    /**
     * Handle the ExpensePayment "updated" event.
     */
    public function updated(ExpensePayment $expensePayment): void
    {
        // Check if status changed to completed
        if ($expensePayment->wasChanged('status') && $expensePayment->status === 'completed') {
            // Find existing transaction or create new one
            $transaction = AccountingTransaction::byReference(ExpensePayment::class, $expensePayment->id)->first();

            if ($transaction) {
                // Update existing transaction
                $transaction->update([
                    'status' => 'completed',
                    'transaction_date' => $expensePayment->completed_at ?? now(),
                ]);
            } else {
                // Create new transaction if it doesn't exist
                AccountingTransaction::createFromExpensePayment($expensePayment);
            }
        }

        // Handle refunds - when expense payment is refunded, money comes back (debit)
        if ($expensePayment->wasChanged('status') && in_array($expensePayment->status, ['refunded', 'partially_refunded'])) {
            $refundAmount = $expensePayment->refunded_amount;
            
            // Create debit transaction for refund received
            AccountingTransaction::create([
                'transaction_date' => now(),
                'amount' => $refundAmount,
                'type' => 'debit', // Money coming back to business
                'account_id' => AccountingTransaction::getCashAccountId($expensePayment->store_id),
                'reference_type' => ExpensePayment::class,
                'reference_id' => $expensePayment->id,
                'description' => "Refund from Expense Payment - {$expensePayment->payment_number}",
                'store_id' => $expensePayment->store_id,
                'created_by' => auth()->id(),
                'metadata' => [
                    'payment_method' => $expensePayment->paymentMethod->name ?? 'Unknown',
                    'expense_number' => $expensePayment->expense->expense_number ?? null,
                    'refund_amount' => $refundAmount,
                ],
                'status' => 'completed',
            ]);
        }
    }

    /**
     * Handle the ExpensePayment "deleted" event.
     */
    public function deleted(ExpensePayment $expensePayment): void
    {
        // Mark related transactions as cancelled
        AccountingTransaction::byReference(ExpensePayment::class, $expensePayment->id)
            ->update(['status' => 'cancelled']);
    }

    /**
     * Handle the ExpensePayment "restored" event.
     */
    public function restored(ExpensePayment $expensePayment): void
    {
        // Restore related transactions
        $status = $expensePayment->status === 'completed' ? 'completed' : 'pending';
        AccountingTransaction::byReference(ExpensePayment::class, $expensePayment->id)
            ->update(['status' => $status]);
    }

    /**
     * Handle the ExpensePayment "force deleted" event.
     */
    public function forceDeleted(ExpensePayment $expensePayment): void
    {
        // Permanently delete related transactions
        AccountingTransaction::byReference(ExpensePayment::class, $expensePayment->id)->delete();
    }
}
