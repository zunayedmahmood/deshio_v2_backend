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
        Schema::table('service_order_items', function (Blueprint $table) {
            // Make service_order_id nullable
            $table->foreignId('service_order_id')->nullable()->change();
            
            // Add order_id for regular orders
            $table->foreignId('order_id')->nullable()->after('service_order_id')->constrained('orders')->onDelete('cascade');

            // Add discount_amount
            $table->decimal('discount_amount', 10, 2)->default(0)->after('unit_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_order_items', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
            $table->dropColumn(['order_id', 'discount_amount']);
            $table->foreignId('service_order_id')->nullable(false)->change();
        });
    }
};
