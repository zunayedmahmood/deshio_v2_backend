<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Purchase Order Items - individual products in a purchase order
     */
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            
            // Relationships
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->onDelete('set null');
            
            // Item details
            $table->string('product_name'); // Snapshot at order time
            $table->string('product_sku')->nullable();
            $table->integer('quantity_ordered');
            $table->integer('quantity_received')->default(0);
            $table->integer('quantity_pending')->default(0); // ordered - received
            
            // Pricing
            $table->decimal('unit_cost', 10, 2); // Cost price from vendor
            $table->decimal('unit_sell_price', 10, 2)->nullable(); // Intended sell price
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_cost', 15, 2); // (unit_cost * quantity) - discount + tax
            
            // Batch information (will be filled when received)
            $table->string('batch_number')->nullable();
            $table->date('manufactured_date')->nullable();
            $table->date('expiry_date')->nullable();
            
            // Status
            $table->enum('receive_status', [
                'pending',
                'partially_received',
                'fully_received',
                'cancelled'
            ])->default('pending');
            
            // Additional info
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['purchase_order_id']);
            $table->index(['product_id']);
            $table->index(['product_batch_id']);
            $table->index(['batch_number']);
            $table->index(['receive_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
