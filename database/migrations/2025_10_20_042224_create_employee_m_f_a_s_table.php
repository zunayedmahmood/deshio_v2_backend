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
        Schema::create('employee_m_f_a_s', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->string('type')->comment('sms, email, totp, backup_codes');
            $table->text('secret')->nullable()->comment('TOTP secret key');
            $table->json('backup_codes')->nullable()->comment('Backup recovery codes');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->json('settings')->nullable()->comment('Additional MFA settings');
            $table->timestamps();

            $table->unique(['employee_id', 'type']);
            $table->index(['employee_id']);
            $table->index(['type']);
            $table->index(['is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_m_f_a_s');
    }
};
