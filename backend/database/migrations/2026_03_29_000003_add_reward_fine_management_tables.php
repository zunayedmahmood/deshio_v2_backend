<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_reward_fines')) {
            Schema::create('employee_reward_fines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->date('entry_date');
            $table->enum('entry_type', ['reward', 'fine']);
            $table->decimal('amount', 10, 2);
            $table->string('title');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('is_applied')->default(false);
            $table->date('applied_month')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['store_id', 'entry_date']);
            $table->index(['employee_id', 'entry_date']);
            $table->index(['employee_id', 'is_applied']);
            $table->index(['entry_type', 'entry_date']);
            });
        }

        if (!Schema::hasTable('employee_reward_fine_histories')) {
            Schema::create('employee_reward_fine_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_fine_id')->constrained('employee_reward_fines')->onDelete('cascade');
            $table->enum('action', ['created', 'updated', 'deleted', 'applied']);
            $table->date('old_entry_date')->nullable();
            $table->date('new_entry_date')->nullable();
            $table->string('old_entry_type')->nullable();
            $table->string('new_entry_type')->nullable();
            $table->decimal('old_amount', 10, 2)->nullable();
            $table->decimal('new_amount', 10, 2)->nullable();
            $table->string('old_title')->nullable();
            $table->string('new_title')->nullable();
            $table->text('old_notes')->nullable();
            $table->text('new_notes')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reward_fine_id', 'changed_at']);
            $table->index(['changed_by', 'changed_at']);
            $table->index(['action', 'changed_at']);
            });
        }

        if (!Schema::hasTable('employee_salary_adjustments')) {
            Schema::create('employee_salary_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->date('adjustment_month');
            $table->enum('source', ['reward_fine']);
            $table->decimal('total_reward', 10, 2)->default(0);
            $table->decimal('total_fine', 10, 2)->default(0);
            $table->decimal('net_adjustment', 10, 2)->default(0);
            $table->foreignId('applied_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'adjustment_month', 'source'], 'uniq_emp_month_source_adj');
            $table->index(['store_id', 'adjustment_month']);
            $table->index(['employee_id', 'adjustment_month']);
            });
        }

        if (!Schema::hasTable('employee_salary_adjustment_items')) {
            Schema::create('employee_salary_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('employee_salary_adjustments')->onDelete('cascade');
            $table->foreignId('reward_fine_id')->constrained('employee_reward_fines')->onDelete('cascade');
            $table->timestamps();

            $table->unique('reward_fine_id');
            $table->index(['adjustment_id', 'reward_fine_id'], 'idx_adj_item_adj_rf');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_adjustment_items');
        Schema::dropIfExists('employee_salary_adjustments');
        Schema::dropIfExists('employee_reward_fine_histories');
        Schema::dropIfExists('employee_reward_fines');
    }
};