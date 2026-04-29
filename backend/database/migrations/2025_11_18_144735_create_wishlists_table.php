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
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->string('wishlist_name', 100)->default('default');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for better performance
            $table->index(['customer_id', 'wishlist_name']);
            $table->index(['customer_id', 'product_id']);
            
            // Unique constraint to prevent duplicate items in same wishlist
            $table->unique(['customer_id', 'product_id', 'wishlist_name'], 'unique_customer_product_wishlist');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wishlists');
    }
};
