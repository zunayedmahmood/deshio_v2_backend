<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Vendor Payments - track payments made to vendors (can be partial)
     */
    public function up(): void
    {
        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            
            // Payment identification
            $table->string('payment_number')->unique(); // VP-YYYYMMDD-XXXXXX
            $table->string('reference_number')->nullable(); // External reference
            
            // Relationships
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('restrict');
            $table->foreignId('payment_method_id')->constrained('payment_methods')->onDelete('restrict');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->onDelete('set null'); // Paid from which account
            $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('set null'); // Who processed
            
            // Payment details
            $table->decimal('amount', 15, 2); // Total amount of this payment
            $table->decimal('allocated_amount', 15, 2)->default(0); // Amount allocated to POs
            $table->decimal('unallocated_amount', 15, 2)->default(0); // Advance/unallocated payment
            
            // Status
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
                'cancelled',
                'refunded'
            ])->default('pending');
            
            $table->enum('payment_type', [
                'purchase_order', // Payment against specific PO(s)
                'advance',        // Advance payment to vendor
                'refund',         // Refund from vendor
                'adjustment'      // Payment adjustment
            ])->default('purchase_order');
            
            // Transaction details
            $table->string('transaction_id')->nullable(); // Bank/gateway transaction ID
            $table->string('cheque_number')->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('bank_name')->nullable();
            
            // Dates
            $table->date('payment_date');
            $table->timestamp('processed_at')->nullable();
            
            // Additional info
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable(); // For storing additional payment details
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['vendor_id']);
            $table->index(['payment_method_id']);
            $table->index(['account_id']);
            $table->index(['employee_id']);
            $table->index(['payment_date']);
            $table->index(['status']);
            $table->index(['payment_type']);
            $table->index(['payment_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
    }
};
