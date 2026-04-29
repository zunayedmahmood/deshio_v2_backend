<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add pathao_store_id to stores table.
     * Each store has its own Pathao Store ID for multi-store shipment creation.
     * 
     * When creating shipments for multi-store orders:
     * - Order has items from Store 1, Store 2, Store 3
     * - Create 3 separate Pathao shipments
     * - Each shipment uses the respective store's pathao_store_id
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('pathao_store_id', 50)
                  ->nullable()
                  ->after('pathao_key')
                  ->comment('Pathao Store ID for this store (required for shipment creation)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('pathao_store_id');
        });
    }
};
