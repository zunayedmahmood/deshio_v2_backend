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
        // Add deleted_at to categories table
        Schema::table('categories', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add deleted_at to vendors table
        Schema::table('vendors', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add deleted_at to stores table
        Schema::table('stores', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add deleted_at to employees table
        Schema::table('employees', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add deleted_at to customers table
        Schema::table('customers', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add deleted_at to orders table
        Schema::table('orders', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Add deleted_at to fields table
        Schema::table('fields', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('stores', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('fields', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
