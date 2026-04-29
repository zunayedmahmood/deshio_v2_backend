<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Purchase Orders represent orders placed to vendors for purchasing products.
     * Only warehouses can receive products from vendors.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->unique(); // PO-YYYYMMDD-XXXXXX
            
            // Relationships
            $table->foreignId('vendor_id')->constrained('vendors')->onDelete('restrict');
            $table->foreignId('store_id')->constrained('stores')->onDelete('restrict'); // Must be warehouse
            $table->foreignId('created_by')->constrained('employees')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('received_by')->nullable()->constrained('employees')->onDelete('set null');
            
            // Order details
            $table->enum('status', [
                'draft',
                'pending_approval',
                'approved',
                'sent_to_vendor',
                'partially_received',
                'received',
                'cancelled',
                'returned'
            ])->default('draft');
            
            // Dates
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->date('actual_delivery_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            
            // Financial details
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('shipping_cost', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            
            // Payment tracking
            $table->enum('payment_status', [
                'unpaid',
                'partially_paid',
                'paid',
                'overdue'
            ])->default('unpaid');
            
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0);
            $table->date('payment_due_date')->nullable();
            
            // Additional info
            $table->string('reference_number')->nullable(); // Vendor's invoice/reference
            $table->text('terms_and_conditions')->nullable();
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['po_number']);
            $table->index(['vendor_id']);
            $table->index(['store_id']);
            $table->index(['status']);
            $table->index(['payment_status']);
            $table->index(['order_date']);
            $table->index(['expected_delivery_date']);
            $table->index(['payment_due_date']);
            $table->index(['created_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
