<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_payments') || !Schema::hasColumn('order_payments', 'payment_type')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE order_payments MODIFY payment_type ENUM('full','installment','partial','final','advance','exchange_balance','store_credit','balance_carryover','exchange_surplus') NOT NULL DEFAULT 'full'");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_payments') || !Schema::hasColumn('order_payments', 'payment_type')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('order_payments')
                ->whereIn('payment_type', ['exchange_balance', 'store_credit', 'balance_carryover', 'exchange_surplus'])
                ->update(['payment_type' => 'full']);

            DB::statement("ALTER TABLE order_payments MODIFY payment_type ENUM('full','installment','partial','final','advance') NOT NULL DEFAULT 'full'");
        }
    }
};
