<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Vendor Payment Items - link payments to specific purchase orders
     * Supports partial payments: e.g., $10,000 PO can be paid $7,000 now and $3,000 later
     */
    public function up(): void
    {
        Schema::create('vendor_payment_items', function (Blueprint $table) {
            $table->id();
            
            // Relationships
            $table->foreignId('vendor_payment_id')->constrained('vendor_payments')->onDelete('cascade');
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->onDelete('restrict');
            
            // Payment allocation details
            $table->decimal('allocated_amount', 15, 2); // Amount from this payment applied to this PO
            $table->decimal('po_total_at_payment', 15, 2); // Snapshot of PO total at payment time
            $table->decimal('po_outstanding_before', 15, 2); // Outstanding before this payment
            $table->decimal('po_outstanding_after', 15, 2); // Outstanding after this payment
            
            // Status
            $table->enum('allocation_type', [
                'full',    // Paying full outstanding amount
                'partial', // Paying part of outstanding
                'over'     // Overpayment (creates credit)
            ])->default('partial');
            
            // Additional info
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['vendor_payment_id']);
            $table->index(['purchase_order_id']);
            $table->index(['allocation_type']);
            
            // Unique constraint: prevent duplicate allocations in same payment
            $table->unique(['vendor_payment_id', 'purchase_order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_items');
    }
};
