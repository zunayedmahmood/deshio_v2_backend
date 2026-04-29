<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration creates a table to track when a single order payment
     * is split across multiple payment methods. For example, a $1000 payment
     * can be split as: $300 cash + $500 bank transfer + $200 card.
     * 
     * This is different from multiple payments (installments) - this is for
     * splitting a single payment transaction across multiple methods.
     */
    public function up(): void
    {
        Schema::create('payment_splits', function (Blueprint $table) {
            $table->id();
            
            // Relationships
            $table->foreignId('order_payment_id')->constrained('order_payments')->onDelete('cascade');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->onDelete('restrict');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            
            // Split details
            $table->decimal('amount', 10, 2); // Amount paid via this method in this split
            $table->decimal('fee_amount', 10, 2)->default(0); // Fee for this specific method
            $table->decimal('net_amount', 10, 2); // Amount after fee for this method
            
            // Order in which this payment method was used (1, 2, 3...)
            $table->integer('split_sequence')->default(1);
            
            // Transaction reference for this specific payment method
            $table->string('transaction_reference')->nullable();
            $table->string('external_reference')->nullable(); // For external processors
            
            // Payment method specific data for this split
            $table->json('payment_data')->nullable(); // Card last 4 digits, bank account, etc.
            
            // Processing details
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled',
                'refunded',
                'partially_refunded'
            ])->default('pending');
            
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            
            // Refund tracking for this specific split
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->json('refund_history')->nullable();
            
            // Additional tracking
            $table->text('notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['order_payment_id', 'split_sequence']);
            $table->index(['payment_method_id']);
            $table->index(['store_id']);
            $table->index(['status']);
            $table->index(['completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_splits');
    }
};
