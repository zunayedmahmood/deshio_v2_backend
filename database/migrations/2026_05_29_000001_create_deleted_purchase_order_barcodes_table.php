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
        Schema::create('deleted_purchase_order_barcodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deleted_purchase_order_id');
            $table->foreignId('product_barcode_id')->constrained('product_barcodes')->cascadeOnDelete();
            $table->unsignedBigInteger('deleted_product_batch_id')->nullable();
            $table->string('deleted_po_number')->nullable();
            $table->string('deleted_batch_number')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->timestamp('deleted_at')->useCurrent();
            $table->timestamps();

            $table->unique('product_barcode_id', 'dpob_barcode_unique');
            $table->index('deleted_purchase_order_id', 'dpob_po_id_index');
            $table->index('deleted_product_batch_id', 'dpob_batch_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deleted_purchase_order_barcodes');
    }
};
