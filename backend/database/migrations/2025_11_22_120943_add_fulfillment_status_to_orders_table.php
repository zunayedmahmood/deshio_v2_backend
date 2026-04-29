<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add fulfillment status for social commerce orders.
     * Counter orders: fulfilled immediately (null/not_applicable)
     * Social commerce orders: pending_fulfillment → fulfilled (when barcodes scanned)
     * E-commerce orders: pending_fulfillment → fulfilled (when barcodes scanned)
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment_status', 30)
                ->nullable()
                ->after('status')
                ->comment('null=not_applicable (counter), pending_fulfillment, fulfilled');
            
            $table->timestamp('fulfilled_at')->nullable()->after('confirmed_at');
            $table->foreignId('fulfilled_by')->nullable()->constrained('employees')->after('fulfilled_at');
            
            $table->index(['fulfillment_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        Schema::table('orders', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropForeign(['fulfilled_by']);
            }
            $table->dropColumn(['fulfillment_status', 'fulfilled_at', 'fulfilled_by']);
        });
    }
};
