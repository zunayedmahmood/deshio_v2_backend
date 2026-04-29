<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add payment tracking fields to shipments table for Pathao COD sync
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // COD collection tracking
            $table->boolean('cod_collected')->default(false)->after('cod_amount');
            $table->decimal('cod_collected_amount', 10, 2)->nullable()->after('cod_collected');
            $table->timestamp('cod_collected_at')->nullable()->after('cod_collected_amount');
            
            // Payment sync tracking
            $table->timestamp('pathao_last_synced_at')->nullable()->after('pathao_response');
            $table->string('pathao_payment_status')->nullable()->after('pathao_last_synced_at');
            
            // Index for faster sync queries
            $table->index(['pathao_consignment_id', 'status']);
            $table->index(['cod_collected', 'status']);
            $table->index(['pathao_last_synced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['pathao_consignment_id', 'status']);
            $table->dropIndex(['cod_collected', 'status']);
            $table->dropIndex(['pathao_last_synced_at']);
            
            $table->dropColumn([
                'cod_collected',
                'cod_collected_amount',
                'cod_collected_at',
                'pathao_last_synced_at',
                'pathao_payment_status',
            ]);
        });
    }
};
