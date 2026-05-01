<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add weight field to products table for shipment calculations.
     * Nullable with sensible default - won't break existing products.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Product weight in kg (nullable, defaults to 0.5kg if not specified)
            $table->decimal('weight', 8, 2)->nullable()->default(0.5)->after('description');
            
            $table->index('weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['weight']);
            $table->dropColumn('weight');
        });
    }
};
