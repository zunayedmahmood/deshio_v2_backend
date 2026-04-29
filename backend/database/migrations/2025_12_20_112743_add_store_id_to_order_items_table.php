<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add store_id to order_items table to support multi-store fulfillment.
     * This enables orders where different items are fulfilled from different stores.
     * 
     * Example: Order with 3 products
     * - Product A fulfilled from Store 1
     * - Product B fulfilled from Store 2
     * - Product C fulfilled from Store 3
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('store_id')
                  ->nullable()
                  ->after('product_barcode_id')
                  ->constrained('stores')
                  ->onDelete('set null')
                  ->comment('Store fulfilling this specific item (for multi-store orders)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
