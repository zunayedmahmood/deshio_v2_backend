<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fix: Remove global unique constraint on batch_number
     * and add compound unique constraint on (product_id, batch_number, store_id)
     * to allow same batch_number across different stores (cross-store returns)
     */
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            // Drop the existing unique constraint on batch_number
            $table->dropUnique(['batch_number']);
            
            // Add compound unique index: same batch_number can exist in different stores
            // but NOT duplicate within the same store for the same product
            $table->unique(['product_id', 'batch_number', 'store_id'], 'product_batch_store_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            // Drop the compound unique index
            $table->dropUnique('product_batch_store_unique');
            
            // Restore the original unique constraint on batch_number
            // WARNING: This will fail if there are duplicate batch_numbers across stores
            $table->unique('batch_number');
        });
    }
};
