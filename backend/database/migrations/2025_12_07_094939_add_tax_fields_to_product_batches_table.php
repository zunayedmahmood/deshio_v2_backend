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
        Schema::table('product_batches', function (Blueprint $table) {
            // Tax percentage for this batch (e.g., 2.00 for 2%)
            $table->decimal('tax_percentage', 5, 2)->default(0)->after('sell_price');
            
            // Base price (excluding tax) - calculated from inclusive sell_price
            $table->decimal('base_price', 10, 2)->nullable()->after('tax_percentage');
            
            // Tax amount (extracted from sell_price)
            $table->decimal('tax_amount', 10, 2)->default(0)->after('base_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropColumn(['tax_percentage', 'base_price', 'tax_amount']);
        });
    }
};
