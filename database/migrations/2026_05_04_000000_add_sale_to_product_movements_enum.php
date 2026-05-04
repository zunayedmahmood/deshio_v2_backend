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
        // Add 'sale' to the movement_type enum in product_movements table
        // Database-agnostic approach: Drop and recreate column (as seen in previous migrations)
        Schema::table('product_movements', function (Blueprint $table) {
            $table->dropColumn('movement_type');
        });
        
        Schema::table('product_movements', function (Blueprint $table) {
            $table->enum('movement_type', ['dispatch', 'transfer', 'return', 'adjustment', 'defective', 'sale'])
                ->after('product_dispatch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_movements', function (Blueprint $table) {
            $table->dropColumn('movement_type');
        });
        
        Schema::table('product_movements', function (Blueprint $table) {
            $table->enum('movement_type', ['dispatch', 'transfer', 'return', 'adjustment', 'defective'])
                ->after('product_dispatch_id');
        });
    }
};
