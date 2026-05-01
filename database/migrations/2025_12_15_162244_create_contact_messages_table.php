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
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index(); // Indexed for quick lookup
            $table->string('name');
            $table->text('message');
            $table->enum('status', ['new', 'read', 'replied', 'archived'])->default('new');
            $table->text('admin_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->unsignedBigInteger('replied_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign key
            $table->foreign('replied_by')->references('id')->on('employees')->onDelete('set null');

            // Additional indexes
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
