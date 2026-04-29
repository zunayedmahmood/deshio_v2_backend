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
        // Update payment_status enum to include 'unpaid' and 'partial'
        // Using raw SQL for better compatibility
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending', 'unpaid', 'paid', 'partial', 'failed', 'refunded') DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL doesn't have ENUM built-in, check if using CHECK constraint
            DB::statement("
                ALTER TABLE orders 
                DROP CONSTRAINT IF EXISTS orders_payment_status_check
            ");
            
            DB::statement("
                ALTER TABLE orders 
                ADD CONSTRAINT orders_payment_status_check 
                CHECK (payment_status IN ('pending', 'unpaid', 'paid', 'partial', 'failed', 'refunded'))
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending'");
        } elseif ($driver === 'pgsql') {
            DB::statement("
                ALTER TABLE orders 
                DROP CONSTRAINT IF EXISTS orders_payment_status_check
            ");
            
            DB::statement("
                ALTER TABLE orders 
                ADD CONSTRAINT orders_payment_status_check 
                CHECK (payment_status IN ('pending', 'paid', 'failed', 'refunded'))
            ");
        }
    }
};
