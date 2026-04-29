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
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->string('service_order_number')->unique();

            // Relationships
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('created_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->onDelete('set null');

            // Order details
            $table->enum('status', [
                'pending',
                'confirmed',
                'in_progress',
                'completed',
                'cancelled',
                'refunded'
            ])->default('pending');

            $table->enum('payment_status', [
                'unpaid',
                'partially_paid',
                'paid',
                'refunded',
                'partially_refunded'
            ])->default('unpaid');

            // Pricing
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('refunded_amount', 10, 2)->default(0);

            // Scheduling
            $table->timestamp('scheduled_date')->nullable();
            $table->timestamp('scheduled_time')->nullable();
            $table->timestamp('estimated_completion')->nullable();
            $table->timestamp('actual_completion')->nullable();

            // Customer information
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();
            $table->text('customer_address')->nullable();

            // Service details
            $table->text('special_instructions')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();

            // Tracking
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['customer_id']);
            $table->index(['store_id']);
            $table->index(['assigned_to']);
            $table->index(['status']);
            $table->index(['payment_status']);
            $table->index(['scheduled_date']);
            $table->index(['service_order_number']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
