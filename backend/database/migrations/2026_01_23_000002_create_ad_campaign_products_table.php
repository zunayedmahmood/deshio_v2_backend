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
        Schema::create('ad_campaign_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            
            // Effective dating (critical for historical accuracy)
            // When a product is removed from a campaign, we set effective_to = now()
            // This preserves historical attribution - past sales still credit the campaign
            $table->datetime('effective_from');
            $table->datetime('effective_to')->nullable();
            
            // Audit
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            
            // Indexes for fast lookup during attribution
            $table->index(['product_id', 'effective_from', 'effective_to'], 'idx_product_effective_dates');
            $table->index(['campaign_id'], 'idx_campaign');
            
            // Prevent duplicate active mappings
            $table->unique(['campaign_id', 'product_id', 'effective_from'], 'unique_campaign_product_effective');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_campaign_products');
    }
};
