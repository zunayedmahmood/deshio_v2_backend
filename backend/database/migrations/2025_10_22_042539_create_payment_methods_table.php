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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // 'cash', 'card', 'bank_transfer', etc.
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['cash', 'card', 'bank_transfer', 'online_banking', 'mobile_banking', 'digital_wallet', 'other']);

            // Customer type restrictions
            $table->json('allowed_customer_types'); // ['counter', 'social_commerce', 'ecommerce']

            // Configuration
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_reference')->default(false); // Requires transaction reference
            $table->boolean('supports_partial')->default(true); // Can be used for partial payments
            $table->decimal('min_amount', 10, 2)->nullable(); // Minimum amount for this method
            $table->decimal('max_amount', 10, 2)->nullable(); // Maximum amount for this method

            // Processing details
            $table->string('processor')->nullable(); // Payment processor (stripe, bkash, etc.)
            $table->json('processor_config')->nullable(); // Processor-specific configuration
            $table->string('icon')->nullable(); // Icon for UI display

            // Fees
            $table->decimal('fixed_fee', 10, 2)->default(0);
            $table->decimal('percentage_fee', 5, 2)->default(0); // Percentage of transaction

            // Display order
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            // Indexes
            $table->index(['code']);
            $table->index(['type']);
            $table->index(['is_active']);
            $table->index(['sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
