<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add Pathao location IDs to customer_addresses table.
     * These are optional fields - existing addresses will continue to work.
     * Frontend can implement Pathao location selection gradually.
     */
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            // Pathao location IDs (all nullable - won't break existing data)
            $table->unsignedInteger('pathao_city_id')->nullable()->after('country');
            $table->unsignedInteger('pathao_zone_id')->nullable()->after('pathao_city_id');
            $table->unsignedInteger('pathao_area_id')->nullable()->after('pathao_zone_id');
            
            // Add indexes for performance
            $table->index('pathao_city_id');
            $table->index('pathao_area_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropIndex(['pathao_city_id']);
            $table->dropIndex(['pathao_area_id']);
            
            $table->dropColumn(['pathao_city_id', 'pathao_zone_id', 'pathao_area_id']);
        });
    }
};
