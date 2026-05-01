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
        Schema::create('expense_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();

            // Relationships
            $table->foreignId('expense_id')->constrained('expenses')->onDelete('cascade');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->onDelete('restrict');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('processed_by')->constrained('employees')->onDelete('cascade');

            // Payment details
            $table->decimal('amount', 10, 2);
            $table->decimal('fee_amount', 10, 2)->default(0); // Processing fee
            $table->decimal('net_amount', 10, 2); // Amount after fees

            // Status and processing
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled',
                'refunded',
                'partially_refunded'
            ])->default('pending');

            // Transaction details
            $table->string('transaction_reference')->nullable(); // Bank transaction ID, check number, etc.
            $table->string('external_reference')->nullable(); // External payment processor reference
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Payment method specific data
            $table->json('payment_data')->nullable(); // Check details, bank info, transfer details, etc.
            $table->json('metadata')->nullable(); // Additional payment metadata

            // Notes and tracking
            $table->text('notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('status_history')->nullable();

            // Refund tracking (for overpayments)
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->json('refund_history')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['expense_id']);
            $table->index(['payment_method_id']);
            $table->index(['store_id']);
            $table->index(['status']);
            $table->index(['processed_at']);
            $table->index(['completed_at']);
            $table->index(['payment_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
    }
};
