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
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->string('pathao_key', 100)->nullable();
            $table->boolean('is_warehouse')->default(false);
            $table->boolean('is_online')->default(false);
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('store_code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->integer('capacity')->nullable()->comment('Warehouse capacity in sq ft or units');
            $table->boolean('is_active')->default(true);
            $table->json('opening_hours')->nullable();
            $table->timestamps();

            $table->index(['is_warehouse']);
            $table->index(['is_online']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
