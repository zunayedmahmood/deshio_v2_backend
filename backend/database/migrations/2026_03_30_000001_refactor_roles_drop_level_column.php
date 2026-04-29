<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('roles', 'level')) {
            try {
                Schema::table('roles', function (Blueprint $table) {
                    $table->dropIndex('roles_level_index');
                });
            } catch (\Throwable $e) {
                // ignore if index does not exist
            }

            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('level');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('roles', 'level')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->integer('level')
                    ->default(0)
                    ->after('guard_name')
                    ->comment('Role hierarchy level (deprecated, kept for rollback only)');
            });
        }
    }
};