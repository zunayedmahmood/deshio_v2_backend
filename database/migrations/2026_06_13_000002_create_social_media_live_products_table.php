<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_live_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_displaying_now')->default(false);
            $table->timestamps();

            $table->index(['sort_order', 'id']);
            $table->index('is_displaying_now');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_live_products');
    }
};
