<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Database-agnostic approach: Drop and recreate column
        Schema::table('product_movements', function (Blueprint $table) {
            $table->dropColumn('movement_type');
        });
        
        Schema::table('product_movements', function (Blueprint $table) {
            $table->enum('movement_type', ['dispatch', 'transfer', 'return', 'adjustment', 'defective'])
                ->after('id');
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
            $table->enum('movement_type', ['dispatch', 'transfer', 'return', 'adjustment'])
                ->after('id');
        });
    }
};
