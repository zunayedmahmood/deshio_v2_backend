<?php

namespace App\Observers;

use App\Models\Expense;
use App\Models\Transaction as AccountingTransaction;

class ExpenseObserver
{
    /**
     * Handle the Expense "created" event.
     * 
     * Note: We don't create transactions here because ExpensePayment observer
     * handles the actual cash flow when payment is made.
     */
    public function created(Expense $expense): void
    {
        // No transaction created - wait for actual payment via ExpensePayment
    }

    /**
     * Handle the Expense "updated" event.
     * 
     * Note: We don't create transactions here because ExpensePayment observer
     * handles the actual cash flow when payment is made.
     */
    public function updated(Expense $expense): void
    {
        // No transaction updates - ExpensePayment handles the cash flow
    }

    /**
     * Handle the Expense "deleted" event.
     */
    public function deleted(Expense $expense): void
    {
        // Mark related transactions as cancelled (if any were created via payments)
        AccountingTransaction::byReference(Expense::class, $expense->id)
            ->update(['status' => 'cancelled']);
    }

    /**
     * Handle the Expense "restored" event.
     */
    public function restored(Expense $expense): void
    {
        // Restore related transactions (if any)
        AccountingTransaction::byReference(Expense::class, $expense->id)
            ->update(['status' => 'completed']);
    }

    /**
     * Handle the Expense "force deleted" event.
     */
    public function forceDeleted(Expense $expense): void
    {
        // Permanently delete related transactions
        AccountingTransaction::byReference(Expense::class, $expense->id)->delete();
    }
}
