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
        // Add 'assigned_to_store' to the status enum in orders table
        // We include ALL existing and new statuses to ensure completeness and avoid truncation
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to the previous known state (from 2025_12_21_000004)
        $statuses = [
            'pending',
            'pending_assignment',
            'confirmed',
            'processing',
            'ready_for_pickup',
            'shipped',
            'delivered',
            'cancelled',
            'refunded'
        ];

        $statusList = "'" . implode("','", $statuses) . "'";

        $driver = DB::getDriverName();
        
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM($statusList) NOT NULL DEFAULT 'pending'");
        }
    }
};
