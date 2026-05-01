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
        Schema::create('product_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');

            // Return details
            $table->enum('return_reason', [
                'defective_product',
                'wrong_item',
                'not_as_described',
                'customer_dissatisfaction',
                'size_issue',
                'color_issue',
                'quality_issue',
                'late_delivery',
                'changed_mind',
                'duplicate_order',
                'other'
            ]);
            $table->text('return_reason_details')->nullable();
            $table->enum('return_type', ['customer_return', 'store_return', 'warehouse_return'])->default('customer_return');

            // Status and workflow
            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'processing',
                'completed',
                'refunded'
            ])->default('pending');

            // Financial details
            $table->decimal('total_return_value', 10, 2)->default(0);
            $table->decimal('total_refund_amount', 10, 2)->default(0);
            $table->decimal('processing_fee', 10, 2)->default(0);

            // Items being returned (JSON structure)
            $table->json('return_items'); // Array of items with product_id, barcode_id, quantity, unit_price, reason

            // Processing details
            $table->timestamp('return_date');
            $table->timestamp('received_date')->nullable();
            $table->timestamp('approved_date')->nullable();
            $table->timestamp('processed_date')->nullable();
            $table->timestamp('rejected_date')->nullable();

            // Personnel
            $table->foreignId('created_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('processed_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('rejected_by')->nullable()->constrained('employees')->onDelete('set null');

            // Quality check
            $table->boolean('quality_check_passed')->nullable();
            $table->text('quality_check_notes')->nullable();

            // Additional information
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('attachments')->nullable(); // URLs to photos/videos of returned items

            // Tracking
            $table->string('tracking_number')->nullable();
            $table->json('status_history')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['order_id']);
            $table->index(['customer_id']);
            $table->index(['store_id']);
            $table->index(['status']);
            $table->index(['return_date']);
            $table->index(['approved_date']);
            $table->index(['return_reason']);
            $table->index(['return_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_returns');
    }
};
