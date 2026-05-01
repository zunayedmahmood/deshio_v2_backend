<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the three multi-entry tables for the cash sheet panels.
 *
 * branch_cost_entries  — branch managers log daily operational costs (multiple per day)
 * admin_entries        — admin logs salary set-asides, cash→bank transfers,
 *                        SSLZC & Pathao disbursements (multiple per day, typed)
 * owner_entries        — owner logs investments in and expenditures out (multiple per day, typed)
 *
 * NOTE: The old daily_cash_reports and owner_daily_entries tables (single-row-per-day)
 * are left untouched — they were created in migrations 000001 and 000002.
 * These new tables replace their function with a proper multi-entry design.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Branch managers log daily operational costs
        Schema::create('branch_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->text('details')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['entry_date', 'store_id']);
        });

        // Admin entries: salary set-aside, cash→bank transfers, SSLZC & Pathao disbursements
        Schema::create('admin_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->enum('type', ['salary_setaside', 'cash_to_bank', 'sslzc', 'pathao']);
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete(); // null for sslzc/pathao
            $table->decimal('amount', 14, 2);
            $table->text('details')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['entry_date', 'type']);
        });

        // Owner entries: investments in and costs out
        Schema::create('owner_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->enum('type', ['cash_invest', 'bank_invest', 'cash_cost', 'bank_cost']);
            $table->decimal('amount', 14, 2);
            $table->text('details')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index('entry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_entries');
        Schema::dropIfExists('admin_entries');
        Schema::dropIfExists('branch_cost_entries');
    }
};
