<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add product_barcode_id to order_items table.
     * This enables tracking WHICH SPECIFIC PHYSICAL UNIT was sold.
     * 
     * Example: Customer buys "Jamdani Saree Red"
     * - Instead of just knowing they bought from Batch #45
     * - We now know they bought barcode 789012345023 specifically
     * - Enables precise returns, warranty tracking, and defect tracing
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Add barcode_id after product_batch_id
            $table->foreignId('product_barcode_id')
                  ->nullable()
                  ->after('product_batch_id')
                  ->constrained('product_barcodes')
                  ->onDelete('set null')
                  ->comment('Specific physical unit sold');
            
            // Add index for faster queries
            $table->index(['product_barcode_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        Schema::table('order_items', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropForeign(['product_barcode_id']);
            }
            $table->dropColumn('product_barcode_id');
        });
    }
};
