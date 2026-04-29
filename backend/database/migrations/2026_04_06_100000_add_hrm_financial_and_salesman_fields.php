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
        Schema::table('store_attendance_policies', function (Blueprint $table) {
            $table->decimal('late_fee_per_minute', 10, 2)->default(0)->after('notes');
            $table->decimal('overtime_rate_per_hour', 10, 2)->default(0)->after('late_fee_per_minute');
            $table->integer('grace_period_minutes')->default(0)->after('overtime_rate_per_hour');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('salesman_id')->nullable()->after('created_by')->constrained('employees')->nullOnDelete();
        });

        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->integer('late_minutes')->default(0)->after('is_modified');
            $table->decimal('late_fee', 10, 2)->default(0)->after('late_minutes');
        });

        Schema::table('employee_overtimes', function (Blueprint $table) {
            $table->decimal('overtime_pay', 10, 2)->default(0)->after('is_modified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_attendance_policies', function (Blueprint $table) {
            $table->dropColumn(['late_fee_per_minute', 'overtime_rate_per_hour', 'grace_period_minutes']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['salesman_id']);
            $table->dropColumn('salesman_id');
        });

        Schema::table('employee_attendances', function (Blueprint $table) {
            $table->dropColumn(['late_minutes', 'late_fee']);
        });

        Schema::table('employee_overtimes', function (Blueprint $table) {
            $table->dropColumn('overtime_pay');
        });
    }
};
