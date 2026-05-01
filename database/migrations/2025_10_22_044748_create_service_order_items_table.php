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
        Schema::create('service_order_items', function (Blueprint $table) {
            $table->id();

            // Relationships
            $table->foreignId('service_order_id')->constrained('service_orders')->onDelete('cascade');
            $table->foreignId('service_id')->constrained('services')->onDelete('restrict');
            $table->foreignId('service_field_id')->nullable()->constrained('service_fields')->onDelete('set null');

            // Service details
            $table->string('service_name');
            $table->string('service_code');
            $table->text('service_description')->nullable();

            // Quantity and pricing
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('base_price', 10, 2); // Service base price
            $table->decimal('total_price', 10, 2);

            // Service options and customizations
            $table->json('selected_options')->nullable(); // Selected service options with prices
            $table->json('field_values')->nullable(); // Dynamic field values
            $table->json('customizations')->nullable(); // Additional customizations

            // Status and tracking
            $table->enum('status', [
                'pending',
                'confirmed',
                'in_progress',
                'completed',
                'cancelled'
            ])->default('pending');

            // Scheduling (can override order level)
            $table->timestamp('scheduled_date')->nullable();
            $table->timestamp('scheduled_time')->nullable();
            $table->timestamp('estimated_duration')->nullable(); // in minutes
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Notes and special instructions
            $table->text('special_instructions')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('customer_notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['service_order_id']);
            $table->index(['service_id']);
            $table->index(['service_field_id']);
            $table->index(['status']);
            $table->index(['scheduled_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_order_items');
    }
};
