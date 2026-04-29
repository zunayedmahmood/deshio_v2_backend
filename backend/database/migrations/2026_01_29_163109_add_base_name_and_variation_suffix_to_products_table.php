<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds support for "common edit" functionality where:
     * - base_name: The core product name (e.g., "saree")
     * - variation_suffix: The variation identifier (e.g., "-red-30")
     * - name: Display name = base_name + variation_suffix (auto-computed)
     * 
     * When editing base_name for a SKU group, all products with that SKU
     * will automatically update their display names.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Base name for the product (common across variations)
            // e.g., "saree" for products "saree-red-30", "saree-green-40"
            $table->string('base_name')->nullable()->after('name');
            
            // Variation suffix (unique per variation within SKU group)
            // e.g., "-red-30", "-green-40", or empty string for non-variant products
            $table->string('variation_suffix')->nullable()->after('base_name');
            
            // Index for efficient SKU-based group queries
            $table->index(['sku', 'base_name']);
        });

        // Data migration: For existing products, set base_name = name, variation_suffix = ''
        // This preserves backward compatibility
        DB::statement("UPDATE products SET base_name = name, variation_suffix = '' WHERE base_name IS NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sku', 'base_name']);
            $table->dropColumn(['base_name', 'variation_suffix']);
        });
    }
};
