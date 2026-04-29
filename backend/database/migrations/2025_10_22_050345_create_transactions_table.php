<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number')->unique();
            $table->date('transaction_date');
            $table->decimal('amount', 15, 2);
            $table->enum('type', ['debit', 'credit']); // debit = money coming in, credit = money going out
            $table->unsignedBigInteger('account_id')->nullable(); // Chart of accounts reference
            $table->string('reference_type'); // OrderPayment, ServiceOrderPayment, Refund, Expense
            $table->unsignedBigInteger('reference_id');
            $table->text('description');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->json('metadata')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('completed');
            $table->timestamps();

            // Indexes
            $table->index(['reference_type', 'reference_id']);
            $table->index(['account_id']);
            $table->index(['store_id']);
            $table->index(['transaction_date']);
            $table->index(['type']);
            $table->index(['status']);

            // Foreign keys
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('employees')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
