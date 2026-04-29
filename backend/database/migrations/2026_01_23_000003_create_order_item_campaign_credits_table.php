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
        Schema::create('order_item_campaign_credits', function (Blueprint $table) {
            $table->id();
            
            // Order references (denormalized for fast reporting)
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            
            // Attribution metadata
            $table->datetime('sale_time');
            $table->enum('credit_mode', ['FULL', 'SPLIT']);
            
            // Credited amounts (allow decimals for split mode)
            // FULL mode: Each campaign gets 100% of the sale
            // SPLIT mode: Credit divided by number of matching campaigns
            $table->decimal('credited_qty', 10, 4);
            $table->decimal('credited_revenue', 10, 2);
            $table->decimal('credited_profit', 10, 2)->nullable();
            
            // Reversal support (for refunds/cancellations)
            $table->boolean('is_reversed')->default(false);
            $table->datetime('reversed_at')->nullable();
            
            // Store match count for analysis
            $table->integer('matched_campaigns_count')->nullable();
            
            $table->timestamps();
            
            // Idempotency constraint (prevents duplicate credits)
            $table->unique(['order_item_id', 'campaign_id', 'credit_mode', 'sale_time'], 'unique_credit');
            
            // Performance indexes for reporting
            $table->index(['campaign_id', 'sale_time', 'credit_mode', 'is_reversed'], 'idx_campaign_reporting');
            $table->index(['sale_time', 'credit_mode', 'is_reversed'], 'idx_time_mode_reversed');
            $table->index(['order_id'], 'idx_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_item_campaign_credits');
    }
};
