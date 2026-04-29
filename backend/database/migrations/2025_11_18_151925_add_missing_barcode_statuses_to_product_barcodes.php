<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add 'available' and 'sold' status values to product_barcodes.current_status enum
     */
    public function up(): void
    {
        // Update existing statuses before schema change
        DB::table('product_barcodes')
            ->whereIn('current_status', ['in_warehouse', 'in_shop'])
            ->update(['current_status' => 'available']);

        // Drop and recreate enum columns with new values (database-agnostic)
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->dropColumn('current_status');
        });
        
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->enum('current_status', [
                'available', 'in_warehouse', 'in_shop', 'on_display',
                'in_transit', 'in_shipment', 'sold', 'with_customer',
                'in_return', 'defective', 'repair', 'vendor_return', 'disposed'
            ])->default('available')->after('barcode')
              ->comment('Current state of this physical unit');
        });

        // Update product_movements enum columns
        Schema::table('product_movements', function (Blueprint $table) {
            $table->dropColumn(['status_before', 'status_after']);
        });
        
        Schema::table('product_movements', function (Blueprint $table) {
            $table->enum('status_before', [
                'available', 'in_warehouse', 'in_shop', 'on_display',
                'in_transit', 'in_shipment', 'sold', 'with_customer',
                'in_return', 'defective', 'repair', 'vendor_return', 'disposed'
            ])->nullable()->after('movement_type')
              ->comment('Status before movement');
              
            $table->enum('status_after', [
                'available', 'in_warehouse', 'in_shop', 'on_display',
                'in_transit', 'in_shipment', 'sold', 'with_customer',
                'in_return', 'defective', 'repair', 'vendor_return', 'disposed'
            ])->nullable()->after('status_before')
              ->comment('Status after movement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert status changes
        DB::table('product_barcodes')
            ->where('current_status', 'available')
            ->update(['current_status' => 'in_warehouse']);

        DB::table('product_barcodes')
            ->where('current_status', 'sold')
            ->update(['current_status' => 'with_customer']);

        // Drop and recreate with old enum values
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->dropColumn('current_status');
        });
        
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->enum('current_status', [
                'in_warehouse', 'in_shop', 'on_display', 'in_transit',
                'in_shipment', 'with_customer', 'in_return', 'defective',
                'repair', 'vendor_return', 'disposed'
            ])->default('in_warehouse')->after('barcode')
              ->comment('Current state of this physical unit');
        });

        Schema::table('product_movements', function (Blueprint $table) {
            $table->dropColumn(['status_before', 'status_after']);
        });
        
        Schema::table('product_movements', function (Blueprint $table) {
            $table->enum('status_before', [
                'in_warehouse', 'in_shop', 'on_display', 'in_transit',
                'in_shipment', 'with_customer', 'in_return', 'defective',
                'repair', 'vendor_return', 'disposed'
            ])->nullable()->after('movement_type')
              ->comment('Status before movement');
              
            $table->enum('status_after', [
                'in_warehouse', 'in_shop', 'on_display', 'in_transit',
                'in_shipment', 'with_customer', 'in_return', 'defective',
                'repair', 'vendor_return', 'disposed'
            ])->nullable()->after('status_before')
              ->comment('Status after movement');
        });
    }
};
