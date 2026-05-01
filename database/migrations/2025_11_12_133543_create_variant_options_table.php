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
        Schema::create('variant_options', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Size", "Color"
            $table->string('value'); // e.g., "XL", "Red"
            $table->string('type')->default('text'); // text, color, image
            $table->string('display_value')->nullable(); // hex code for colors, image URL
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['name', 'value']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variant_options');
    }
};
