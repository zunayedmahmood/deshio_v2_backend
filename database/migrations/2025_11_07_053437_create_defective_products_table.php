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
        Schema::create('defective_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('product_barcode_id')->constrained('product_barcodes')->onDelete('cascade');
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->onDelete('set null');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            
            // Defect information
            $table->string('defect_type'); // physical_damage, malfunction, cosmetic, missing_parts, etc.
            $table->text('defect_description');
            $table->json('defect_images')->nullable(); // Photos of the defect
            $table->enum('severity', ['minor', 'moderate', 'major', 'critical'])->default('moderate');
            
            // Original product info
            $table->decimal('original_price', 10, 2);
            $table->decimal('suggested_selling_price', 10, 2)->nullable();
            $table->decimal('minimum_selling_price', 10, 2)->nullable();
            
            // Tracking
            $table->enum('status', ['identified', 'inspected', 'available_for_sale', 'sold', 'disposed', 'returned_to_vendor'])->default('identified');
            $table->foreignId('identified_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('inspected_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('sold_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->timestamp('identified_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            
            // Sale information (when sold as defective)
            $table->foreignId('order_id')->nullable()->constrained('orders')->onDelete('set null');
            $table->decimal('actual_selling_price', 10, 2)->nullable();
            $table->text('sale_notes')->nullable();
            
            // Disposal/Return information
            $table->text('disposal_notes')->nullable();
            $table->timestamp('disposed_at')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->onDelete('set null');
            $table->timestamp('returned_to_vendor_at')->nullable();
            $table->text('vendor_notes')->nullable();
            
            // Additional notes
            $table->text('internal_notes')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('product_barcode_id');
            $table->index('status');
            $table->index('defect_type');
            $table->index(['store_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('defective_products');
    }
};
