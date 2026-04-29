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
        Schema::table('product_dispatches', function (Blueprint $table) {
            // Pathao delivery tracking fields
            $table->boolean('for_pathao_delivery')->default(false)->after('status');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->onDelete('set null')->after('for_pathao_delivery');
            $table->foreignId('order_id')->nullable()->constrained('orders')->onDelete('set null')->after('customer_id');
            $table->json('customer_delivery_info')->nullable()->after('order_id');
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->onDelete('set null')->after('customer_delivery_info');
            
            // Add index for performance
            $table->index('for_pathao_delivery');
            $table->index(['for_pathao_delivery', 'status', 'shipment_id'], 'idx_pathao_pending_shipment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        Schema::table('product_dispatches', function (Blueprint $table) use ($driver) {
            $table->dropIndex('idx_pathao_pending_shipment');
            $table->dropIndex(['for_pathao_delivery']);
            
            if ($driver !== 'sqlite') {
                $table->dropForeign(['shipment_id']);
                $table->dropForeign(['order_id']);
                $table->dropForeign(['customer_id']);
            }
            
            $table->dropColumn([
                'for_pathao_delivery',
                'customer_id',
                'order_id',
                'customer_delivery_info',
                'shipment_id',
            ]);
        });
    }
};
