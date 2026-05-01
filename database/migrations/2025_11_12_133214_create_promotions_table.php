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
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            
            // Discount details
            $table->enum('type', ['percentage', 'fixed', 'buy_x_get_y', 'free_shipping'])->default('percentage');
            $table->decimal('discount_value', 10, 2)->default(0); // percentage or fixed amount
            $table->integer('buy_quantity')->nullable(); // for buy_x_get_y
            $table->integer('get_quantity')->nullable(); // for buy_x_get_y
            
            // Conditions
            $table->decimal('minimum_purchase', 10, 2)->nullable();
            $table->decimal('maximum_discount', 10, 2)->nullable();
            $table->json('applicable_products')->nullable(); // product IDs
            $table->json('applicable_categories')->nullable(); // category IDs
            $table->json('applicable_customers')->nullable(); // customer IDs or types
            
            // Usage limits
            $table->integer('usage_limit')->nullable(); // total usage limit
            $table->integer('usage_per_customer')->nullable();
            $table->integer('usage_count')->default(0);
            
            // Validity
            $table->dateTime('start_date');
            $table->dateTime('end_date')->nullable();
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true); // visible to all vs targeted
            
            // Metadata
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index('code');
            $table->index('start_date');
            $table->index('end_date');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
