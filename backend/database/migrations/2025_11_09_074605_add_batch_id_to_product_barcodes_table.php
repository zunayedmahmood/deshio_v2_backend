<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add batch_id to product_barcodes table to link each barcode to its batch.
     * This enables individual tracking of every physical unit within a batch.
     */
    public function up(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            // Add batch_id foreign key after product_id
            $table->foreignId('batch_id')
                  ->nullable()
                  ->after('product_id')
                  ->constrained('product_batches')
                  ->onDelete('cascade')
                  ->comment('Batch this barcode belongs to');
            
            // Add index for faster queries
            $table->index(['batch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        Schema::table('product_barcodes', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropForeign(['batch_id']);
            }
            $table->dropColumn('batch_id');
        });
    }
};
