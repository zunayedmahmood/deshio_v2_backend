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
        Schema::create('expense_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_extension', 10);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size'); // in bytes
            $table->string('original_name');
            $table->foreignId('uploaded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->json('metadata')->nullable(); // Additional file metadata
            $table->timestamps();
            $table->softDeletes();

            $table->index(['expense_id', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_receipts');
    }
};
