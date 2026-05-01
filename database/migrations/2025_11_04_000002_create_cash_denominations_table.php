<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration creates a table to track the actual cash notes/coins
     * received and given as change during cash transactions.
     * 
     * Example scenarios:
     * 1. Customer pays $220 with exact denominations
     * 2. Customer pays $300 (3 x $100 notes) for a $220 bill
     *    - System records: 3 x $100 received, 1 x $50 + 1 x $20 + 1 x $10 given as change
     */
    public function up(): void
    {
        Schema::create('cash_denominations', function (Blueprint $table) {
            $table->id();
            
            // Relationships - can be linked to either payment_splits or order_payments
            $table->foreignId('payment_split_id')->nullable()->constrained('payment_splits')->onDelete('cascade');
            $table->foreignId('order_payment_id')->nullable()->constrained('order_payments')->onDelete('cascade');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('recorded_by')->nullable()->constrained('employees')->onDelete('set null');
            
            // Type: received (cash given by customer) or change (cash returned to customer)
            $table->enum('type', ['received', 'change'])->default('received');
            
            // Denomination details
            $table->string('currency')->default('USD'); // USD, BDT, etc.
            $table->decimal('denomination_value', 10, 2); // Value of the note/coin (100, 50, 20, 10, 5, 1, 0.25, etc.)
            $table->integer('quantity'); // Number of notes/coins of this denomination
            $table->decimal('total_amount', 10, 2); // denomination_value * quantity
            
            // Note/Coin type
            $table->enum('cash_type', ['note', 'coin'])->default('note');
            
            // Tracking
            $table->text('notes')->nullable(); // Any special notes about condition, etc.
            $table->json('metadata')->nullable(); // Additional tracking data
            
            $table->timestamps();
            
            // Indexes
            $table->index(['payment_split_id']);
            $table->index(['order_payment_id']);
            $table->index(['store_id']);
            $table->index(['type']);
            $table->index(['denomination_value']);
            $table->index(['created_at']);
            
            // Ensure at least one foreign key is set
            $table->index(['payment_split_id', 'order_payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_denominations');
    }
};
