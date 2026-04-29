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
        Schema::create('inventory_rebalancings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('source_batch_id')->constrained('product_batches')->onDelete('cascade');
            $table->foreignId('source_store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('destination_store_id')->constrained('stores')->onDelete('cascade');
            $table->integer('quantity');
            $table->enum('status', ['pending', 'approved', 'in_transit', 'completed', 'cancelled'])->default('pending');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->text('reason')->nullable();
            $table->decimal('estimated_cost', 10, 2)->default(0);
            $table->decimal('actual_cost', 10, 2)->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('requested_by')->constrained('employees')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('completed_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['source_store_id', 'status']);
            $table->index(['destination_store_id', 'status']);
            $table->index(['priority', 'status']);
            $table->index(['requested_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_rebalancings');
    }
};
