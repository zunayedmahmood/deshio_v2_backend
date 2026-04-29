<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add Pathao store configuration fields to stores table.
     * These are optional - stores can continue operating without Pathao integration.
     * Admin can configure Pathao details when ready.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Pathao store contact information (all nullable)
            $table->string('pathao_contact_name')->nullable()->after('pathao_store_id');
            $table->string('pathao_contact_number', 20)->nullable()->after('pathao_contact_name');
            $table->string('pathao_secondary_contact', 20)->nullable()->after('pathao_contact_number');
            
            // Pathao location IDs for store address (all nullable)
            $table->unsignedInteger('pathao_city_id')->nullable()->after('pathao_secondary_contact');
            $table->unsignedInteger('pathao_zone_id')->nullable()->after('pathao_city_id');
            $table->unsignedInteger('pathao_area_id')->nullable()->after('pathao_zone_id');
            
            // Track if store is registered with Pathao
            $table->boolean('pathao_registered')->default(false)->after('pathao_area_id');
            $table->timestamp('pathao_registered_at')->nullable()->after('pathao_registered');
            
            // Add indexes
            $table->index('pathao_registered');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['pathao_registered']);
            
            $table->dropColumn([
                'pathao_contact_name',
                'pathao_contact_number',
                'pathao_secondary_contact',
                'pathao_city_id',
                'pathao_zone_id',
                'pathao_area_id',
                'pathao_registered',
                'pathao_registered_at',
            ]);
        });
    }
};
