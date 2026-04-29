<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_attendance_policies', function (Blueprint $table) {
            $table->time('fixed_start_time')->nullable()->after('fixed_days_off');
            $table->time('fixed_end_time')->nullable()->after('fixed_start_time');
        });

        Schema::create('employee_overtimes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->date('overtime_date');
            $table->unsignedInteger('overtime_minutes');
            $table->decimal('overtime_hours', 8, 2);
            $table->string('overtime_hhmm', 5);
            $table->text('notes')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->boolean('is_modified')->default(false);
            $table->timestamps();

            $table->unique(['employee_id', 'overtime_date']);
            $table->index(['store_id', 'overtime_date']);
            $table->index(['store_id', 'employee_id', 'overtime_date']);
        });

        Schema::create('employee_overtime_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('overtime_id')->constrained('employee_overtimes')->onDelete('cascade');
            $table->date('old_overtime_date')->nullable();
            $table->date('new_overtime_date')->nullable();
            $table->unsignedInteger('old_overtime_minutes')->nullable();
            $table->unsignedInteger('new_overtime_minutes')->nullable();
            $table->decimal('old_overtime_hours', 8, 2)->nullable();
            $table->decimal('new_overtime_hours', 8, 2)->nullable();
            $table->string('old_overtime_hhmm', 5)->nullable();
            $table->string('new_overtime_hhmm', 5)->nullable();
            $table->text('reason')->nullable();
            $table->text('change_note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['overtime_id', 'changed_at']);
            $table->index(['changed_by', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_overtime_histories');
        Schema::dropIfExists('employee_overtimes');

        Schema::table('store_attendance_policies', function (Blueprint $table) {
            $table->dropColumn(['fixed_start_time', 'fixed_end_time']);
        });
    }
};