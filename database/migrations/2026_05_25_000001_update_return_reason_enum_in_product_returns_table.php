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
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'mysql') {
            DB::statement("
                ALTER TABLE product_returns 
                MODIFY COLUMN return_reason ENUM(
                    'defective_product',
                    'wrong_item',
                    'not_as_described',
                    'customer_dissatisfaction',
                    'size_issue',
                    'color_issue',
                    'quality_issue',
                    'late_delivery',
                    'changed_mind',
                    'duplicate_order',
                    'wrong_product',
                    'wrong_customer',
                    'other'
                ) NOT NULL
            ");
        } elseif ($driver === 'pgsql') {
            DB::statement("
                ALTER TABLE product_returns 
                DROP CONSTRAINT IF EXISTS product_returns_return_reason_check
            ");
            
            DB::statement("
                ALTER TABLE product_returns 
                ADD CONSTRAINT product_returns_return_reason_check 
                CHECK (return_reason IN (
                    'defective_product',
                    'wrong_item',
                    'not_as_described',
                    'customer_dissatisfaction',
                    'size_issue',
                    'color_issue',
                    'quality_issue',
                    'late_delivery',
                    'changed_mind',
                    'duplicate_order',
                    'wrong_product',
                    'wrong_customer',
                    'other'
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
                ALTER TABLE product_returns 
                MODIFY COLUMN return_reason ENUM(
                    'defective_product',
                    'wrong_item',
                    'not_as_described',
                    'customer_dissatisfaction',
                    'size_issue',
                    'color_issue',
                    'quality_issue',
                    'late_delivery',
                    'changed_mind',
                    'duplicate_order',
                    'other'
                ) NOT NULL
            ");
        } elseif ($driver === 'pgsql') {
            DB::statement("
                ALTER TABLE product_returns 
                DROP CONSTRAINT IF EXISTS product_returns_return_reason_check
            ");
            
            DB::statement("
                ALTER TABLE product_returns 
                ADD CONSTRAINT product_returns_return_reason_check 
                CHECK (return_reason IN (
                    'defective_product',
                    'wrong_item',
                    'not_as_described',
                    'customer_dissatisfaction',
                    'size_issue',
                    'color_issue',
                    'quality_issue',
                    'late_delivery',
                    'changed_mind',
                    'duplicate_order',
                    'other'
                ))
            ");
        }
    }
};
