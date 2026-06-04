<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill legacy/inconsistent online orders that already have a store assignment
     * but were missing fulfillment_status. Without this, the package queue can miss
     * assigned_to_store orders when the UI/API filters by pending fulfillment.
     */
    public function up(): void
    {
        DB::table('orders')
            ->whereIn('order_type', ['social_commerce', 'ecommerce'])
            ->where('status', 'assigned_to_store')
            ->whereNotNull('store_id')
            ->where(function ($query) {
                $query->whereNull('fulfillment_status')
                    ->orWhere('fulfillment_status', '');
            })
            ->update([
                'fulfillment_status' => 'pending_fulfillment',
            ]);

        DB::table('orders')
            ->whereIn('order_type', ['social_commerce', 'ecommerce'])
            ->where('status', 'assigned_to_store')
            ->whereNotNull('store_id')
            ->orderBy('id')
            ->select(['id', 'store_id'])
            ->chunk(200, function ($orders) {
                foreach ($orders as $order) {
                    DB::table('order_items')
                        ->where('order_id', $order->id)
                        ->where(function ($query) use ($order) {
                            $query->whereNull('store_id')
                                ->orWhere('store_id', '!=', $order->store_id);
                        })
                        ->update(['store_id' => $order->store_id]);
                }
            });
    }

    public function down(): void
    {
        // Data repair migration. Do not unset fulfillment/store assignment state on rollback.
    }
};
