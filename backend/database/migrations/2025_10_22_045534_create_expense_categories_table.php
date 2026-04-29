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
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique(); // Unique code for identification
            $table->text('description')->nullable();

            // Category type classification
            $table->enum('type', [
                'operational',     // Day-to-day business expenses
                'capital',         // Long-term investments
                'personnel',       // Employee-related expenses
                'marketing',       // Advertising and promotion
                'administrative',  // Office and management expenses
                'logistics',       // Shipping and transportation
                'utilities',       // Electricity, water, internet, etc.
                'maintenance',     // Equipment and facility maintenance
                'taxes',          // Tax payments
                'insurance',      // Insurance premiums
                'other'           // Miscellaneous expenses
            ])->default('operational');

            // Hierarchy support
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->onDelete('set null');

            // Budget and control
            $table->decimal('monthly_budget', 12, 2)->nullable(); // Monthly budget limit
            $table->decimal('yearly_budget', 12, 2)->nullable(); // Yearly budget limit
            $table->boolean('requires_approval')->default(false); // Whether expenses need approval
            $table->decimal('approval_threshold', 10, 2)->nullable(); // Amount above which approval is required

            // Status and display
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->string('icon')->nullable(); // Icon for UI display
            $table->string('color')->nullable(); // Color for UI display

            // Tracking
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['code']);
            $table->index(['type']);
            $table->index(['parent_id']);
            $table->index(['is_active']);
            $table->index(['requires_approval']);
            $table->index(['sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
