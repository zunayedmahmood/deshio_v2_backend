<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('refunds') || !Schema::hasColumn('refunds', 'refund_type')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE refunds MODIFY refund_type ENUM('full','percentage','partial_amount','exchange_refund') NOT NULL DEFAULT 'full'");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('refunds') || !Schema::hasColumn('refunds', 'refund_type')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('refunds')
                ->where('refund_type', 'exchange_refund')
                ->update(['refund_type' => 'partial_amount']);

            DB::statement("ALTER TABLE refunds MODIFY refund_type ENUM('full','percentage','partial_amount') NOT NULL DEFAULT 'full'");
        }
    }
};
