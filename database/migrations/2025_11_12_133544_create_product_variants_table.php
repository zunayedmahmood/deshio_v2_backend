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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('barcode')->unique()->nullable();
            
            // Variant combination (e.g., {"Size": "XL", "Color": "Red"})
            $table->json('attributes');
            
            // Pricing (override product base price if needed)
            $table->decimal('price_adjustment', 10, 2)->default(0); // +/- from base price
            $table->decimal('cost_price', 10, 2)->nullable();
            
            // Stock tracking per variant
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('reorder_point')->nullable();
            
            // Images for this specific variant
            $table->string('image_url')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            
            $table->softDeletes();
            $table->timestamps();
            
            $table->index('product_id');
            $table->index('sku');
            $table->index('barcode');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
