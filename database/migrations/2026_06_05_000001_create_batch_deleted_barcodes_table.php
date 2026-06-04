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
        Schema::create('batch_deleted_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_barcode_id')->constrained('product_barcodes')->cascadeOnDelete();
            $table->unsignedBigInteger('deleted_product_batch_id')->nullable();
            $table->string('deleted_batch_number')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('store_name')->nullable();
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->string('purchase_order_number')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->useCurrent();
            $table->timestamps();

            $table->unique('product_barcode_id', 'bdb_barcode_unique');
            $table->index('deleted_product_batch_id', 'bdb_batch_id_index');
            $table->index('product_id', 'bdb_product_id_index');
            $table->index('purchase_order_id', 'bdb_po_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('batch_deleted_barcodes');
    }
};
