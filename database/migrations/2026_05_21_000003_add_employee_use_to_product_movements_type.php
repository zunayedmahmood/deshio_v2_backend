<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $typesWithEmployeeUse = [
        'dispatch', 'transfer', 'return', 'adjustment', 'defective', 'sale', 'employee_use',
    ];

    private array $typesWithoutEmployeeUse = [
        'dispatch', 'transfer', 'return', 'adjustment', 'defective', 'sale',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasColumn('product_movements', 'movement_type')) {
            return;
        }

        $this->modifyMovementTypeEnum($this->typesWithEmployeeUse);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasColumn('product_movements', 'movement_type')) {
            return;
        }

        DB::table('product_movements')
            ->where('movement_type', 'employee_use')
            ->update(['movement_type' => 'adjustment']);

        $this->modifyMovementTypeEnum($this->typesWithoutEmployeeUse);
    }

    private function modifyMovementTypeEnum(array $types): void
    {
        $enumValues = collect($types)
            ->map(fn ($type) => "'{$type}'")
            ->implode(',');

        DB::statement("ALTER TABLE product_movements MODIFY movement_type ENUM({$enumValues}) NOT NULL");
    }
};
