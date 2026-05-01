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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_number')->unique();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');

            // Pathao Integration Fields
            $table->string('pathao_consignment_id')->nullable();
            $table->string('pathao_tracking_number')->nullable();
            $table->string('pathao_status')->nullable(); // pending, pickup_requested, picked_up, at_warehouse, in_transit, delivered, returned, cancelled
            $table->json('pathao_response')->nullable(); // Store full API response

            // Shipment Details
            $table->enum('status', ['pending', 'pickup_requested', 'picked_up', 'in_transit', 'delivered', 'returned', 'cancelled'])->default('pending');
            $table->enum('delivery_type', ['home_delivery', 'store_pickup', 'express'])->default('home_delivery');
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('cod_amount', 10, 2)->nullable(); // Cash on delivery
            $table->decimal('package_weight', 8, 2)->nullable(); // in kg
            $table->json('package_dimensions')->nullable(); // width, height, length
            $table->text('special_instructions')->nullable();

            // Addresses
            $table->json('pickup_address');
            $table->json('delivery_address');

            // Barcode Tracking
            $table->json('package_barcodes')->nullable(); // Array of product barcodes in this shipment

            // Timestamps
            $table->timestamp('pickup_requested_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('estimated_delivery_date')->nullable();

            // Personnel
            $table->foreignId('created_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('processed_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('delivered_by')->nullable()->constrained('employees')->onDelete('set null');

            // Additional tracking
            $table->text('delivery_notes')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_signature')->nullable(); // URL to signature image
            $table->json('status_history')->nullable(); // Track status changes with timestamps

            $table->timestamps();

            // Indexes
            $table->index(['order_id']);
            $table->index(['customer_id']);
            $table->index(['store_id']);
            $table->index(['status']);
            $table->index(['pathao_status']);
            $table->index(['delivery_type']);
            $table->index(['estimated_delivery_date']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
