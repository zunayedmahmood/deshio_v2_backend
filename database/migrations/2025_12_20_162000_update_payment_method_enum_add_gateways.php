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
        // Update payment_method enum to include all payment gateways
        // Original: cash, card, bank_transfer, digital_wallet, cod
        // Adding: sslcommerz, bkash, nagad, rocket, credit_card, cash_on_delivery
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE orders 
                MODIFY COLUMN payment_method ENUM(
                    'cash',
                    'card', 
                    'credit_card',
                    'bank_transfer',
                    'digital_wallet',
                    'cod',
                    'cash_on_delivery',
                    'sslcommerz',
                    'bkash',
                    'nagad',
                    'rocket'
                ) NULL
            ");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL doesn't have ENUM built-in, check if using CHECK constraint
            DB::statement("
                ALTER TABLE orders 
                DROP CONSTRAINT IF EXISTS orders_payment_method_check
            ");
            
            DB::statement("
                ALTER TABLE orders 
                ADD CONSTRAINT orders_payment_method_check 
                CHECK (payment_method IN (
                    'cash',
                    'card',
                    'credit_card',
                    'bank_transfer',
                    'digital_wallet',
                    'cod',
                    'cash_on_delivery',
                    'sslcommerz',
                    'bkash',
                    'nagad',
                    'rocket'
                ))
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
            DB::statement("
                ALTER TABLE orders 
                MODIFY COLUMN payment_method ENUM(
                    'cash',
                    'card',
                    'bank_transfer',
                    'digital_wallet',
                    'cod'
                ) NULL
            ");
        } elseif ($driver === 'pgsql') {
            DB::statement("
                ALTER TABLE orders 
                DROP CONSTRAINT IF EXISTS orders_payment_method_check
            ");
            
            DB::statement("
                ALTER TABLE orders 
                ADD CONSTRAINT orders_payment_method_check 
                CHECK (payment_method IN (
                    'cash',
                    'card',
                    'bank_transfer',
                    'digital_wallet',
                    'cod'
                ))
            ");
        }
    }
};
