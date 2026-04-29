<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration adds comprehensive location and status tracking for individual barcodes.
     * Each physical product unit (barcode) will have its current location and status tracked.
     */
    public function up(): void
    {
        // Add product_barcode_id to product_dispatch_items for individual unit tracking
        Schema::table('product_dispatch_items', function (Blueprint $table) {
            $table->foreignId('product_barcode_id')
                  ->nullable()
                  ->after('product_batch_id')
                  ->constrained('product_barcodes')
                  ->onDelete('set null')
                  ->comment('Track specific physical unit being dispatched');
            
            $table->index(['product_barcode_id']);
        });

        // Add location and status tracking to product_barcodes
        Schema::table('product_barcodes', function (Blueprint $table) {
            // Current physical location
            $table->foreignId('current_store_id')
                  ->nullable()
                  ->after('batch_id')
                  ->constrained('stores')
                  ->onDelete('set null')
                  ->comment('Current physical location of this unit');

            // Current status/state of the physical unit
            $table->enum('current_status', [
                'in_warehouse',      // Stored in warehouse
                'in_shop',           // Available in retail shop
                'on_display',        // Currently displayed on shop floor
                'in_transit',        // Being moved between locations
                'in_shipment',       // Packaged for customer delivery
                'with_customer',     // Sold and delivered to customer
                'in_return',         // Being returned by customer
                'defective',         // Marked as defective
                'repair',            // Sent for repair
                'vendor_return',     // Returned to vendor
                'disposed',          // Disposed/written off
            ])->default('in_warehouse')
              ->after('current_store_id')
              ->comment('Current state of this physical unit');

            // Track when location/status last changed
            $table->timestamp('location_updated_at')
                  ->nullable()
                  ->after('current_status')
                  ->comment('When location/status was last updated');

            // Additional tracking metadata
            $table->json('location_metadata')
                  ->nullable()
                  ->after('location_updated_at')
                  ->comment('Additional location info like shelf, bin, display section');

            // Indexes for efficient queries
            $table->index(['current_store_id']);
            $table->index(['current_status']);
            $table->index(['current_store_id', 'current_status']);
            $table->index(['location_updated_at']);
        });

        // Add reference columns to product_movements for better tracking
        Schema::table('product_movements', function (Blueprint $table) {
            $table->string('reference_type')
                  ->nullable()
                  ->after('reference_number')
                  ->comment('Type: order, dispatch, return, shipment, adjustment');
            
            $table->unsignedBigInteger('reference_id')
                  ->nullable()
                  ->after('reference_type')
                  ->comment('ID of the referenced record');

            $table->enum('status_before', [
                'in_warehouse', 'in_shop', 'on_display', 'in_transit', 
                'in_shipment', 'with_customer', 'in_return', 'defective', 
                'repair', 'vendor_return', 'disposed'
            ])->nullable()
              ->after('reference_id')
              ->comment('Status before movement');

            $table->enum('status_after', [
                'in_warehouse', 'in_shop', 'on_display', 'in_transit', 
                'in_shipment', 'with_customer', 'in_return', 'defective', 
                'repair', 'vendor_return', 'disposed'
            ])->nullable()
              ->after('status_before')
              ->comment('Status after movement');

            $table->index(['reference_type', 'reference_id']);
            $table->index(['status_before']);
            $table->index(['status_after']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        Schema::table('product_dispatch_items', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropForeign(['product_barcode_id']);
            }
            $table->dropIndex(['product_barcode_id']);
            $table->dropColumn('product_barcode_id');
        });

        Schema::table('product_barcodes', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropForeign(['current_store_id']);
            }
            $table->dropIndex(['current_store_id']);
            $table->dropIndex(['current_status']);
            $table->dropIndex(['current_store_id', 'current_status']);
            $table->dropIndex(['location_updated_at']);
            $table->dropColumn([
                'current_store_id',
                'current_status',
                'location_updated_at',
                'location_metadata',
            ]);
        });

        Schema::table('product_movements', function (Blueprint $table) {
            $table->dropIndex(['reference_type', 'reference_id']);
            $table->dropIndex(['status_before']);
            $table->dropIndex(['status_after']);
            $table->dropColumn([
                'reference_type',
                'reference_id',
                'status_before',
                'status_after',
            ]);
        });
    }
};
