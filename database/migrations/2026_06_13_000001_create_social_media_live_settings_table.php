<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_live_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_live')->default(false);
            $table->boolean('displaying_now_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_live_settings');
    }
};
