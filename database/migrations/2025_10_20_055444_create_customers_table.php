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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->enum('customer_type', ['counter', 'social_commerce', 'ecommerce'])->default('counter');
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->json('preferences')->nullable(); // Shopping preferences, communication preferences
            $table->json('social_profiles')->nullable(); // WhatsApp, Facebook, etc.
            $table->string('customer_code')->unique(); // Auto-generated unique code
            $table->decimal('total_purchases', 10, 2)->default(0);
            $table->integer('total_orders')->default(0);
            $table->timestamp('last_purchase_at')->nullable();
            $table->timestamp('first_purchase_at')->nullable();
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('assigned_employee_id')->nullable()->constrained('employees')->onDelete('set null');

            $table->timestamps();

            $table->index(['customer_type', 'status']);
            $table->index(['phone']);
            $table->index(['email']);
            $table->index(['assigned_employee_id']);
            $table->index(['total_purchases']);
            $table->index(['last_purchase_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
