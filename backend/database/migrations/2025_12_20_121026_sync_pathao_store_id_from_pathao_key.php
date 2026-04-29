<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Copy existing pathao_key values to pathao_store_id for all stores.
     * This is database agnostic (works with PostgreSQL and MySQL).
     */
    public function up(): void
    {
        // Use DB query builder for database-agnostic update
        DB::table('stores')
            ->whereNotNull('pathao_key')
            ->whereNull('pathao_store_id')
            ->update([
                'pathao_store_id' => DB::raw('pathao_key')
            ]);
    }

    /**
     * Reverse the migrations.
     * 
     * Clear pathao_store_id values (set back to NULL).
     */
    public function down(): void
    {
        DB::table('stores')
            ->whereNotNull('pathao_store_id')
            ->update([
                'pathao_store_id' => null
            ]);
    }
};
