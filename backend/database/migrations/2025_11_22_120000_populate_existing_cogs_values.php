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
        // Update all existing order_items with NULL cogs
        // Calculate COGS from batch cost_price * quantity
        
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'sqlite') {
            // SQLite doesn't support JOIN in UPDATE, use subquery instead
            $affectedRows = DB::update("
                UPDATE order_items
                SET cogs = ROUND((
                    SELECT cost_price * order_items.quantity
                    FROM product_batches
                    WHERE product_batches.id = order_items.product_batch_id
                ), 2)
                WHERE cogs IS NULL
                  AND product_batch_id IS NOT NULL
            ");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL uses FROM clause for UPDATE with JOIN
            $affectedRows = DB::update("
                UPDATE order_items oi
                SET cogs = ROUND(pb.cost_price * oi.quantity, 2)
                FROM product_batches pb
                WHERE oi.product_batch_id = pb.id
                  AND oi.cogs IS NULL
            ");
        } else {
            // MySQL supports JOIN in UPDATE
            $affectedRows = DB::update("
                UPDATE order_items oi
                JOIN product_batches pb ON oi.product_batch_id = pb.id
                SET oi.cogs = ROUND(pb.cost_price * oi.quantity, 2)
                WHERE oi.cogs IS NULL
            ");
        }
        
        \Log::info("Updated {$affectedRows} order items with calculated COGS");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Set COGS back to NULL for items that were updated
        DB::table('order_items')
            ->whereNotNull('cogs')
            ->update(['cogs' => null]);
    }
};
