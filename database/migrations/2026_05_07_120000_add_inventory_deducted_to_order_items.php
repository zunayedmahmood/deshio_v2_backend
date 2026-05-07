<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $blueprint) {
            $blueprint->boolean('is_inventory_deducted')->default(false)->after('cogs');
        });

        // Backfill for confirmed orders and beyond.
        // Orders in these statuses already had their stock deducted in OrderController@complete
        // or through POS immediate completion.
        DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereIn('orders.status', ['confirmed', 'processing', 'ready_for_pickup', 'shipped', 'delivered', 'completed'])
            ->update(['order_items.is_inventory_deducted' => true]);
            
        Log::info('Migration: Backfilled is_inventory_deducted for existing confirmed orders.');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $blueprint) {
            $blueprint->dropColumn('is_inventory_deducted');
        });
    }
};
