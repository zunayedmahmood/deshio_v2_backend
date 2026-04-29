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
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade')->comment('Employee this note is about');
            $table->foreignId('created_by')->constrained('employees')->onDelete('cascade')->comment('Employee who created the note');
            $table->string('title');
            $table->text('content');
            $table->string('type')->default('general')->comment('general, hr, performance, disciplinary, medical');
            $table->boolean('is_private')->default(false)->comment('Only visible to HR/managers');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable()->comment('Additional data like attachments, tags');
            $table->timestamps();

            $table->index(['employee_id']);
            $table->index(['created_by']);
            $table->index(['type']);
            $table->index(['is_private']);
            $table->index(['is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
