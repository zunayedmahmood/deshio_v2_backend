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
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'sqlite') {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->change();
            });
        } else {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropForeign(['store_id']);
                $table->foreignId('store_id')->nullable()->change();
                $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'sqlite') {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreignId('store_id')->nullable(false)->change();
            });
        } else {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropForeign(['store_id']);
                $table->foreignId('store_id')->nullable(false)->change();
                $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            });
        }
    }
};
