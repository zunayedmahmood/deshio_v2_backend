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
        Schema::create('employee_m_f_a_backup_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_mfa_id')->constrained('employee_m_f_a_s')->onDelete('cascade');
            $table->string('code');
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['employee_mfa_id']);
            $table->index(['code']);
            $table->index(['is_used']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_m_f_a_backup_codes');
    }
};
