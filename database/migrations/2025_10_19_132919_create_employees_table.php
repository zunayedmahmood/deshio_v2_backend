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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->boolean('is_in_service')->default(true);
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('employee_code')->nullable()->unique();
            $table->date('hire_date')->nullable();
            $table->string('department')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->onDelete('set null');
            $table->boolean('is_active')->default(true);
            $table->string('avatar')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['email']);
            $table->index(['store_id']);
            $table->index(['role_id']);
            $table->index(['is_in_service']);
            $table->index(['is_active']);
            $table->index(['manager_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
