<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * branch_cost_entries  — multiple entries per branch per day
 * admin_entries        — multiple entries per day (salary, cash_to_bank, sslzc, pathao)
 * owner_entries        — multiple entries per day (cash_invest, bank_invest, cash_cost, bank_cost)
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
