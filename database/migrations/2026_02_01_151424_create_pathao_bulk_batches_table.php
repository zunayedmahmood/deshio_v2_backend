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
        Schema::create('pathao_bulk_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_code')->unique(); // e.g., PB-20260201-ABC123
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled'])->default('pending');
            $table->integer('total_shipments')->default(0);
            $table->integer('processed_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->json('shipment_ids')->nullable(); // Array of shipment IDs to process
            $table->json('results')->nullable(); // Detailed results per shipment
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pathao_bulk_batches');
    }
};
