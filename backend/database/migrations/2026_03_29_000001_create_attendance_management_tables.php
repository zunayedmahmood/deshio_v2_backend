<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->enum('mode', ['fixed_day_off', 'always_on_duty']);
            $table->json('fixed_days_off')->nullable();
            $table->string('timezone')->default('Asia/Dhaka');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('declared_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'effective_from']);
            $table->index(['store_id', 'effective_to']);
        });

        Schema::create('store_attendance_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('declared_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'start_date']);
            $table->index(['store_id', 'end_date']);
        });

        Schema::create('employee_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->time('start_time');
            $table->time('end_time');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('assigned_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
            $table->index(['employee_id', 'effective_to']);
            $table->index(['store_id', 'is_active']);
        });

        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->date('attendance_date');
            $table->enum('status', ['present', 'late', 'absent', 'leave', 'half_day', 'off_day_auto', 'holiday_auto']);
            $table->time('in_time')->nullable();
            $table->time('out_time')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_modified')->default(false);
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['store_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
        });

        Schema::create('employee_attendance_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('employee_attendances')->onDelete('cascade');
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->time('old_in_time')->nullable();
            $table->time('new_in_time')->nullable();
            $table->time('old_out_time')->nullable();
            $table->time('new_out_time')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['attendance_id', 'changed_at']);
            $table->index(['changed_by', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendance_histories');
        Schema::dropIfExists('employee_attendances');
        Schema::dropIfExists('employee_work_schedules');
        Schema::dropIfExists('store_attendance_holidays');
        Schema::dropIfExists('store_attendance_policies');
    }
};