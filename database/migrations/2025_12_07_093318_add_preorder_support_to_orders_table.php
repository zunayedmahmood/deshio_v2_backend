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
        Schema::table('orders', function (Blueprint $table) {
            // Pre-order support
            $table->boolean('is_preorder')->default(false)->after('order_type');
            $table->timestamp('stock_available_at')->nullable()->after('is_preorder');
            $table->text('preorder_notes')->nullable()->after('stock_available_at');
            
            // Update status enum to include 'awaiting_stock' for pre-orders
            // Note: This requires dropping and recreating the enum in production
            // For now, we'll use 'pending_assignment' status for pre-orders
            
            // Add index for pre-orders
            $table->index(['is_preorder', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['is_preorder', 'status']);
            $table->dropColumn(['is_preorder', 'stock_available_at', 'preorder_notes']);
        });
    }
};
