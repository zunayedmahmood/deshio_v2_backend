<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Make store_id nullable in order_payments for social_commerce/ecommerce orders.
     * These orders don't have store_id at creation (assigned later during fulfillment).
     */
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            // Drop existing foreign key
            $table->dropForeign(['store_id']);
            
            // Make store_id nullable
            $table->foreignId('store_id')->nullable()->change();
            
            // Re-add foreign key with nullable constraint
            $table->foreign('store_id')
                  ->references('id')
                  ->on('stores')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            // Drop foreign key
            $table->dropForeign(['store_id']);
            
            // Make store_id NOT nullable
            $table->foreignId('store_id')->nullable(false)->change();
            
            // Re-add foreign key
            $table->foreign('store_id')
                  ->references('id')
                  ->on('stores')
                  ->onDelete('cascade');
        });
    }
};
