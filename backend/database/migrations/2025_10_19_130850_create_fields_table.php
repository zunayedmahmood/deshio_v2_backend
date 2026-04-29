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
        Schema::create('fields', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type')->comment('text|number|date|file|select|textarea|boolean|email|url');
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->text('default_value')->nullable();
            $table->json('options')->nullable()->comment('For select/radio fields: {"option1": "value1", "option2": "value2"}');
            $table->string('validation_rules')->nullable()->comment('Laravel validation rules like "required|email|max:255"');
            $table->string('placeholder')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type']);
            $table->index(['is_required']);
            $table->index(['is_active']);
            $table->index(['order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fields');
    }
};
