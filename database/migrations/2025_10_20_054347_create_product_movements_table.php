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
        Schema::create('product_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_batch_id')->constrained('product_batches')->onDelete('cascade');
            $table->foreignId('product_barcode_id')->constrained('product_barcodes')->onDelete('cascade');
            $table->foreignId('from_store_id')->nullable()->constrained('stores')->onDelete('set null');
            $table->foreignId('to_store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('product_dispatch_id')->nullable()->constrained('product_dispatches')->onDelete('set null');
            $table->enum('movement_type', ['dispatch', 'transfer', 'return', 'adjustment']);
            $table->integer('quantity');
            $table->decimal('unit_cost', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_cost', 10, 2);
            $table->decimal('total_value', 10, 2);
            $table->dateTime('movement_date');
            $table->string('reference_number')->nullable(); // Dispatch number, transfer number, etc.
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('employees')->onDelete('cascade');
            $table->timestamps();

            $table->index(['product_batch_id', 'movement_date']);
            $table->index(['product_barcode_id', 'movement_date']);
            $table->index(['from_store_id', 'movement_date']);
            $table->index(['to_store_id', 'movement_date']);
            $table->index(['movement_type', 'movement_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_movements');
    }
};
