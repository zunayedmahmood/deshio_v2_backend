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
        Schema::table('orders', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['store_id']);
            
            // Make store_id nullable using Laravel's database-agnostic method
            $table->unsignedBigInteger('store_id')->nullable()->change();
            
            // Re-add the foreign key constraint
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Drop the foreign key
            $table->dropForeign(['store_id']);
            
            // Make store_id NOT NULL using Laravel's database-agnostic method
            $table->unsignedBigInteger('store_id')->nullable(false)->change();
            
            // Re-add the foreign key constraint with cascade
            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');
        });
    }
};
