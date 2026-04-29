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
        Schema::create('product_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('destination_store_id')->constrained('stores')->onDelete('cascade');
            $table->string('dispatch_number')->unique();
            $table->enum('status', ['pending', 'in_transit', 'delivered', 'cancelled'])->default('pending');
            $table->dateTime('dispatch_date');
            $table->dateTime('expected_delivery_date')->nullable();
            $table->dateTime('actual_delivery_date')->nullable();
            $table->string('carrier_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->decimal('total_cost', 10, 2)->default(0);
            $table->decimal('total_value', 10, 2)->default(0);
            $table->integer('total_items')->default(0);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->constrained('employees')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['source_store_id', 'status']);
            $table->index(['destination_store_id', 'status']);
            $table->index(['dispatch_date']);
            $table->index(['expected_delivery_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_dispatches');
    }
};
