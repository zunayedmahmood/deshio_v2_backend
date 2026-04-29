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
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'saved'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for better performance
            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'product_id', 'status']);
            
            // Unique constraint to prevent duplicate active items
            $table->unique(['customer_id', 'product_id', 'status'], 'unique_customer_product_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
