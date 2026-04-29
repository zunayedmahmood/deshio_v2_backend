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
        Schema::create('product_dispatch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_dispatch_id')->constrained('product_dispatches')->onDelete('cascade');
            $table->foreignId('product_batch_id')->constrained('product_batches')->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('unit_cost', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_cost', 10, 2);
            $table->decimal('total_value', 10, 2);
            $table->enum('status', ['pending', 'dispatched', 'received', 'damaged', 'missing'])->default('pending');
            $table->integer('received_quantity')->nullable();
            $table->integer('damaged_quantity')->nullable();
            $table->integer('missing_quantity')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_dispatch_id', 'status']);
            $table->index(['product_batch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_dispatch_items');
    }
};
