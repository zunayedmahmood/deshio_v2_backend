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
        Schema::table('products', function (Blueprint $table) {
            // Drop foreign key constraint first (using the exact constraint name)
            $table->dropForeign('products_vendor_id_foreign');
        });

        Schema::table('products', function (Blueprint $table) {
            // Make vendor_id nullable
            $table->bigInteger('vendor_id')->unsigned()->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            // Re-add foreign key with nullable support and SET NULL on delete
            $table->foreign('vendor_id')
                  ->references('id')
                  ->on('vendors')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop nullable foreign key
            $table->dropForeign('products_vendor_id_foreign');
        });

        Schema::table('products', function (Blueprint $table) {
            // Make vendor_id required again
            $table->bigInteger('vendor_id')->unsigned()->nullable(false)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            // Re-add foreign key with cascade
            $table->foreign('vendor_id')
                  ->references('id')
                  ->on('vendors')
                  ->onDelete('cascade');
        });
    }
};
