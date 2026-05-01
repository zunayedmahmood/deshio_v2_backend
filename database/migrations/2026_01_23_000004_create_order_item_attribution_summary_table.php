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
        Schema::create('order_item_attribution_summary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained('order_items')->cascadeOnDelete();
            $table->datetime('sale_time');
            $table->integer('matched_campaigns_count')->default(0);
            $table->boolean('is_attributed')->default(false);
            $table->timestamps();
            
            // Index for quick attribution health lookups
            $table->index(['sale_time', 'is_attributed'], 'idx_sale_time_attributed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_item_attribution_summary');
    }
};
