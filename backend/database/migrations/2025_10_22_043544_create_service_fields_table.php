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
        Schema::create('service_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->foreignId('field_id')->constrained('fields')->onDelete('cascade');

            // Field value storage
            $table->text('value')->nullable(); // For text, number, date, etc.
            $table->json('value_json')->nullable(); // For complex data like arrays, objects

            // Additional metadata
            $table->boolean('is_visible')->default(true);
            $table->integer('display_order')->default(0);

            $table->timestamps();

            // Indexes
            $table->index(['service_id']);
            $table->index(['field_id']);
            $table->unique(['service_id', 'field_id']); // One field per service
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_fields');
    }
};
