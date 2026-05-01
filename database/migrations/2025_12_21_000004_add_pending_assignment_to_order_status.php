<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'pending_assignment' to orders.status enum
        // This status is used for ecommerce/social_commerce orders that haven't been assigned to a store yet
        
        $driver = DB::getDriverName();
        
        if ($driver === 'mysql') {
            // MySQL: Modify ENUM
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'pending_assignment', 'confirmed', 'processing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled', 'refunded') DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL: Drop and recreate check constraint
            DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying::text, 'pending_assignment'::character varying::text, 'confirmed'::character varying::text, 'processing'::character varying::text, 'ready_for_pickup'::character varying::text, 'shipped'::character varying::text, 'delivered'::character varying::text, 'cancelled'::character varying::text, 'refunded'::character varying::text]))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot easily remove enum values in PostgreSQL
        // For MySQL, we can revert the enum
        
        $driver = DB::getDriverName();
        
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN status ENUM('pending', 'confirmed', 'processing', 'ready_for_pickup', 'shipped', 'delivered', 'cancelled', 'refunded') DEFAULT 'pending'");
        }
        // PostgreSQL: Cannot remove enum values safely, leave as is
    }
};
