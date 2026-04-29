<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add account_id to expense_categories so each category can be mapped to a specific
 * chart-of-accounts entry. This lets Transaction::getExpenseAccountId() resolve the
 * correct ledger account per category instead of falling back to a hardcoded ID.
 *
 * If account_id is null for a category, the code falls back to account_code 5001
 * (Operating Expenses) — so this column is optional per-category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->unsignedBigInteger('account_id')
                  ->nullable()
                  ->after('metadata')
                  ->comment('Chart-of-accounts account to debit when this category is used');

            $table->foreign('account_id')
                  ->references('id')
                  ->on('accounts')
                  ->onDelete('set null');

            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropIndex(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
