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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('service_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable(); // e.g., 'laundry', 'cleaning', 'repair', etc.

            // Pricing
            $table->decimal('base_price', 10, 2)->default(0);
            $table->decimal('min_price', 10, 2)->nullable();
            $table->decimal('max_price', 10, 2)->nullable();
            $table->string('pricing_type')->default('fixed'); // 'fixed', 'per_unit', 'hourly', 'custom'

            // Service details
            $table->integer('estimated_duration')->nullable(); // in minutes
            $table->string('unit')->nullable(); // 'piece', 'kg', 'hour', etc.
            $table->integer('min_quantity')->default(1);
            $table->integer('max_quantity')->nullable();

            // Status and availability
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_featured')->default(false);

            // Images and media
            $table->json('images')->nullable();
            $table->string('icon')->nullable();

            // Additional configuration
            $table->json('options')->nullable(); // Additional service options
            $table->json('requirements')->nullable(); // Special requirements
            $table->text('instructions')->nullable(); // Service instructions

            // Metadata
            $table->json('metadata')->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            // Indexes
            $table->index(['service_code']);
            $table->index(['category']);
            $table->index(['is_active']);
            $table->index(['is_featured']);
            $table->index(['sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
