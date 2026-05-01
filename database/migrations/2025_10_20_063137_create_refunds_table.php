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
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number')->unique();
            $table->foreignId('return_id')->constrained('product_returns')->onDelete('cascade');
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');

            // Refund details
            $table->enum('refund_type', ['full', 'percentage', 'partial_amount'])->default('full');
            $table->decimal('refund_percentage', 5, 2)->nullable(); // For percentage refunds (0-100)
            $table->decimal('original_amount', 10, 2); // Amount before refund calculation
            $table->decimal('refund_amount', 10, 2); // Actual refund amount
            $table->decimal('processing_fee', 10, 2)->default(0); // Fee deducted from refund

            // Refund method
            $table->enum('refund_method', [
                'cash',
                'bank_transfer',
                'card_refund',
                'store_credit',
                'gift_card',
                'digital_wallet',
                'check',
                'other'
            ]);

            // Payment method details (for refunds to original payment method)
            $table->string('payment_reference')->nullable(); // Original payment transaction ID
            $table->json('refund_method_details')->nullable(); // Bank details, card info, etc.

            // Status and processing
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled'
            ])->default('pending');

            // Processing details
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Personnel
            $table->foreignId('processed_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');

            // Transaction details
            $table->string('transaction_reference')->nullable(); // Bank transaction ID, etc.
            $table->string('bank_reference')->nullable();
            $table->string('gateway_reference')->nullable();

            // Additional information
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('failure_reason')->nullable(); // If refund failed

            // Store credit details (if applicable)
            $table->timestamp('store_credit_expires_at')->nullable();
            $table->string('store_credit_code')->nullable();

            // Audit trail
            $table->json('status_history')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['return_id']);
            $table->index(['order_id']);
            $table->index(['customer_id']);
            $table->index(['status']);
            $table->index(['refund_method']);
            $table->index(['refund_type']);
            $table->index(['processed_at']);
            $table->index(['completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
