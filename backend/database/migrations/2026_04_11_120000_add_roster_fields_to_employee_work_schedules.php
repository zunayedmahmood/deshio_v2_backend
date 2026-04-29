<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_work_schedules', function (Blueprint $table) {
            $table->string('duty_mode')->default('all_days')->after('effective_to');
            $table->json('weekly_days')->nullable()->after('duty_mode');
            $table->json('duty_dates')->nullable()->after('weekly_days');
        });
    }

    public function down(): void
    {
        Schema::table('employee_work_schedules', function (Blueprint $table) {
            $table->dropColumn(['duty_mode', 'weekly_days', 'duty_dates']);
        });
    }
};
