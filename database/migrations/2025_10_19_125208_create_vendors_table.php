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
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('type', 50)->comment('manufacturer/distributor');
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('website')->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->string('payment_terms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['name']);
            $table->index(['email']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
