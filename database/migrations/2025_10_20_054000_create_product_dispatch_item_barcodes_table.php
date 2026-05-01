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
        Schema::create('product_dispatch_item_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_dispatch_item_id')->constrained('product_dispatch_items')->cascadeOnDelete();
            $table->foreignId('product_barcode_id')->constrained('product_barcodes')->cascadeOnDelete();
            $table->timestamp('scanned_at')->useCurrent();
            $table->foreignId('scanned_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            // Prevent duplicate barcode scanning for same dispatch item
            $table->unique(['product_dispatch_item_id', 'product_barcode_id'], 'dispatch_item_barcode_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_dispatch_item_barcodes');
    }
};
