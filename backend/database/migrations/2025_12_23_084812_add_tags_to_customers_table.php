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
        // Use jsonb for PostgreSQL (supports GIN indexing), json for MySQL
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");
        
        Schema::table('customers', function (Blueprint $table) use ($connection) {
            if ($connection === 'pgsql') {
                $table->jsonb('tags')->nullable()->after('notes');
            } else {
                $table->json('tags')->nullable()->after('notes');
            }
        });
        
        // Create GIN index for PostgreSQL JSON queries (supports contains/overlap operations)
        if ($connection === 'pgsql') {
            DB::statement('CREATE INDEX customers_tags_gin_index ON customers USING GIN (tags);');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = config('database.default');
        $connection = config("database.connections.{$driver}.driver");
        
        if ($connection === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS customers_tags_gin_index;');
        }
        
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
