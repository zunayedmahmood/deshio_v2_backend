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
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('batch_number')->unique();
            $table->integer('quantity');
            $table->decimal('cost_price', 10, 2);
            $table->decimal('sell_price', 10, 2);
            $table->boolean('availability')->default(true);
            $table->date('manufactured_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->foreignId('barcode_id')->nullable()->constrained('product_barcodes')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id']);
            $table->index(['batch_number']);
            $table->index(['store_id']);
            $table->index(['barcode_id']);
            $table->index(['expiry_date']);
            $table->index(['availability']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
