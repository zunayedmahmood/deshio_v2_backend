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
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')->constrained('categories')->onDelete('cascade');
            $table->integer('level')->default(0)->after('parent_id');
            $table->string('path')->nullable()->after('level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        Schema::table('categories', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropForeign(['parent_id']);
            }
            $table->dropColumn(['parent_id', 'level', 'path']);
        });
    }
};
