<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Fixes:
     * 1. Add missing 'order_date' column to purchase_orders (required, not in original migration)
     * 2. Make vendor credit_limit nullable instead of default 0
     */
    public function up(): void
    {
        // Fix purchase_orders table - add missing order_date if needed
        Schema::table('purchase_orders', function (Blueprint $table) {
            // Check if order_date column exists, if not add it
            if (!Schema::hasColumn('purchase_orders', 'order_date')) {
                $table->date('order_date')->default(now()->format('Y-m-d'))->after('received_by');
            }
        });

        // Fix vendors table - make credit_limit nullable
        Schema::table('vendors', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->decimal('credit_limit', 15, 2)->default(0)->change();
        });
    }
};
