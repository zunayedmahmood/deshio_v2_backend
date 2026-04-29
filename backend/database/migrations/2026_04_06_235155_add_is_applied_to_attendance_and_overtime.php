<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->boolean('is_applied')->default(false)->after('late_fee');
            $table->timestamp('applied_at')->nullable()->after('is_applied');
        });

        Schema::table('employee_overtimes', function (Blueprint $table) {
            $table->boolean('is_applied')->default(false)->after('overtime_pay');
            $table->timestamp('applied_at')->nullable()->after('is_applied');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropColumn(['is_applied', 'applied_at']);
        });

        Schema::table('employee_overtimes', function (Blueprint $table) {
            $table->dropColumn(['is_applied', 'applied_at']);
        });
    }
};
