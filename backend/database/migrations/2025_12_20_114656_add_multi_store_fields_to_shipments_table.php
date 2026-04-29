<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add fields for multi-store Pathao shipment handling.
     * When order has items from multiple stores, separate shipment created per store.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Carrier name (Pathao, etc.)
            $table->string('carrier_name', 50)->nullable()->after('status');
            
            // Item quantity in this shipment
            $table->integer('item_quantity')->nullable()->after('carrier_name');
            
            // Item weight for this shipment
            $table->decimal('item_weight', 10, 2)->nullable()->after('item_quantity');
            
            // Amount to collect (COD) for this shipment
            $table->decimal('amount_to_collect', 10, 2)->nullable()->after('item_weight');
            
            // Recipient full address
            $table->text('recipient_address')->nullable()->after('recipient_phone');
            
            // Metadata (store pathao_store_id, items list, etc.)
            $table->json('metadata')->nullable()->after('status_history');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'carrier_name',
                'item_quantity',
                'item_weight',
                'amount_to_collect',
                'recipient_address',
                'metadata',
            ]);
        });
    }
};
