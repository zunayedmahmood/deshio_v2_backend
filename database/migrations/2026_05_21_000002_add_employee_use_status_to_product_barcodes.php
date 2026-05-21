<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $statusesWithEmployeeUse = [
        'available', 'in_warehouse', 'in_shop', 'on_display',
        'in_transit', 'in_shipment', 'sold', 'with_customer',
        'in_return', 'defective', 'employee_use', 'repair', 'vendor_return', 'disposed',
    ];

    private array $statusesWithoutEmployeeUse = [
        'available', 'in_warehouse', 'in_shop', 'on_display',
        'in_transit', 'in_shipment', 'sold', 'with_customer',
        'in_return', 'defective', 'repair', 'vendor_return', 'disposed',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->modifyStatusEnums($this->statusesWithEmployeeUse);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::table('product_barcodes')
            ->where('current_status', 'employee_use')
            ->update(['current_status' => 'defective']);

        DB::table('product_movements')
            ->where('status_before', 'employee_use')
            ->update(['status_before' => 'defective']);

        DB::table('product_movements')
            ->where('status_after', 'employee_use')
            ->update(['status_after' => 'defective']);

        $this->modifyStatusEnums($this->statusesWithoutEmployeeUse);
    }

    private function modifyStatusEnums(array $statuses): void
    {
        $enumValues = collect($statuses)
            ->map(fn ($status) => "'{$status}'")
            ->implode(',');

        if (Schema::hasColumn('product_barcodes', 'current_status')) {
            DB::statement("ALTER TABLE product_barcodes MODIFY current_status ENUM({$enumValues}) NOT NULL DEFAULT 'available' COMMENT 'Current state of this physical unit'");
        }

        if (Schema::hasColumn('product_movements', 'status_before')) {
            DB::statement("ALTER TABLE product_movements MODIFY status_before ENUM({$enumValues}) NULL COMMENT 'Status before movement'");
        }

        if (Schema::hasColumn('product_movements', 'status_after')) {
            DB::statement("ALTER TABLE product_movements MODIFY status_after ENUM({$enumValues}) NULL COMMENT 'Status after movement'");
        }
    }
};
