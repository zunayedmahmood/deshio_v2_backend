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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('guard_name')->nullable();
            $table->integer('level')->default(0)->comment('Role hierarchy level');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false)->comment('Default role for new users');
            $table->timestamps();

            $table->index(['slug']);
            $table->index(['guard_name']);
            $table->index(['level']);
            $table->index(['is_active']);
            $table->index(['is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
