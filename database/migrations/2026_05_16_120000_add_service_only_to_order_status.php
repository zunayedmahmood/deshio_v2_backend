<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $statuses = [
            'pending',
            'pending_assignment',
            'assigned_to_store',
            'service_only',
            'confirmed',
            'processing',
            'ready_for_pickup',
            'ready_for_shipment',
            'shipped',
            'delivered',
            'cancelled',
            'returned',
            'refunded'
        ];

        $statusList = "'" . implode("','", $statuses) . "'";
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM($statusList) NOT NULL DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text = ANY (ARRAY[$statusList]::character varying::text[]))");
        }
    }

    public function down(): void
    {
        $statuses = [
            'pending',
            'pending_assignment',
            'assigned_to_store',
            'confirmed',
            'processing',
            'ready_for_pickup',
            'ready_for_shipment',
            'shipped',
            'delivered',
            'cancelled',
            'returned',
            'refunded'
        ];

        $statusList = "'" . implode("','", $statuses) . "'";
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM($statusList) NOT NULL DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text = ANY (ARRAY[$statusList]::character varying::text[]))");
        }
    }
};
