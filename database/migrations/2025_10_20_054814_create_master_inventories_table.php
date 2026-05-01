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
        Schema::create('master_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('total_quantity')->default(0);
            $table->integer('available_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('damaged_quantity')->default(0);
            $table->integer('minimum_stock_level')->default(0);
            $table->integer('maximum_stock_level')->nullable();
            $table->integer('reorder_point')->default(0);
            $table->decimal('average_cost_price', 10, 2)->default(0);
            $table->decimal('average_sell_price', 10, 2)->default(0);
            $table->decimal('total_value', 10, 2)->default(0);
            $table->enum('stock_status', ['out_of_stock', 'low_stock', 'normal', 'overstocked'])->default('normal');
            $table->json('store_breakdown')->nullable(); // Quantity per store
            $table->json('batch_breakdown')->nullable(); // Quantity per batch
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamp('last_counted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('product_id');
            $table->index(['stock_status']);
            $table->index(['total_quantity']);
            $table->index(['last_updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_inventories');
    }
};
