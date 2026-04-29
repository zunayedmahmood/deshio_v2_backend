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
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('platform', ['facebook', 'instagram', 'google', 'tiktok', 'youtube', 'other'])->default('other');
            $table->enum('status', ['DRAFT', 'RUNNING', 'PAUSED', 'ENDED'])->default('DRAFT');
            $table->datetime('starts_at');
            $table->datetime('ends_at')->nullable();
            
            // Optional budget planning
            $table->enum('budget_type', ['DAILY', 'LIFETIME'])->nullable();
            $table->decimal('budget_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            
            // Audit fields
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['status', 'starts_at', 'ends_at'], 'idx_campaign_status_dates');
            $table->index(['platform', 'status'], 'idx_campaign_platform_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
