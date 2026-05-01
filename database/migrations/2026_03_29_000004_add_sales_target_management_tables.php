<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->date('target_month');
            $table->decimal('target_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'target_month']);
            $table->index(['store_id', 'target_month']);
            $table->index(['employee_id', 'target_month']);
        });

        Schema::create('employee_sales_target_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_target_id')->constrained('employee_sales_targets')->onDelete('cascade');
            $table->enum('action', ['created', 'updated']);
            $table->date('old_target_month')->nullable();
            $table->date('new_target_month')->nullable();
            $table->decimal('old_target_amount', 12, 2)->nullable();
            $table->decimal('new_target_amount', 12, 2)->nullable();
            $table->text('old_notes')->nullable();
            $table->text('new_notes')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['sales_target_id', 'changed_at']);
            $table->index(['changed_by', 'changed_at']);
        });

        Schema::create('employee_daily_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->date('sales_date');
            $table->unsignedInteger('order_count')->default(0);
            $table->decimal('total_sales_amount', 12, 2)->default(0);
            $table->timestamp('last_computed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'sales_date']);
            $table->index(['store_id', 'sales_date']);
            $table->index(['employee_id', 'sales_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_daily_sales');
        Schema::dropIfExists('employee_sales_target_histories');
        Schema::dropIfExists('employee_sales_targets');
    }
};