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
        Schema::table('orders', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['store_id']);
            
            // Modify store_id to be nullable
            $table->foreignId('store_id')->nullable()->change();
            
            // Re-add foreign key constraint with nullable and onDelete('set null')
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Drop the nullable foreign key
            $table->dropForeign(['store_id']);
            
            // Revert store_id to NOT NULL
            $table->foreignId('store_id')->nullable(false)->change();
            
            // Re-add original foreign key constraint
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }
};
