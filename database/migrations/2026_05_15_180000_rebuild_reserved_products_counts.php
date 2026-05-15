<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Repair existing reserved_products drift once during deployment.
     *
     * The application now keeps this table synced through InventoryReservationService,
     * but old duplicate observer/direct decrement issues may have already created
     * negative or stale rows. This migration rebuilds them from current batches and
     * live not-yet-deducted order items.
     */
    public function up(): void
    {
        $reservationStatuses = [
            'pending',
            'pending_assignment',
            'assigned_to_store',
            'picking',
            'processing',
            'ready_for_pickup',
            'ready_for_shipment',
            'shipped',
            'confirmed',
        ];

        $totals = DB::table('product_batches')
            ->select('product_id', DB::raw('COALESCE(SUM(quantity), 0) as total_inventory'))
            ->where('is_active', true)
            ->groupBy('product_id')
            ->pluck('total_inventory', 'product_id');

        $reserved = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->select('order_items.product_id', DB::raw('COALESCE(SUM(order_items.quantity), 0) as reserved_inventory'))
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', $reservationStatuses)
            ->where(function ($q) {
                $q->whereNull('orders.order_type')
                  ->orWhere('orders.order_type', '!=', 'preorder');
            })
            ->where(function ($q) {
                $q->whereNull('order_items.is_inventory_deducted')
                  ->orWhere('order_items.is_inventory_deducted', false);
            })
            ->groupBy('order_items.product_id')
            ->pluck('reserved_inventory', 'product_id');

        DB::table('products')
            ->select('id')
            ->orderBy('id')
            ->chunk(500, function ($products) use ($totals, $reserved) {
                foreach ($products as $product) {
                    $totalInventory = max(0, (int) ($totals[$product->id] ?? 0));
                    $reservedInventory = max(0, (int) ($reserved[$product->id] ?? 0));

                    $payload = [
                        'total_inventory' => $totalInventory,
                        'reserved_inventory' => $reservedInventory,
                        'available_inventory' => max(0, $totalInventory - $reservedInventory),
                        'updated_at' => now(),
                    ];

                    if (!DB::table('reserved_products')->where('product_id', $product->id)->exists()) {
                        $payload['created_at'] = now();
                    }

                    DB::table('reserved_products')->updateOrInsert(
                        ['product_id' => $product->id],
                        $payload
                    );
                }
            });
    }

    public function down(): void
    {
        // No rollback: this migration only repairs derived inventory counters.
    }
};
