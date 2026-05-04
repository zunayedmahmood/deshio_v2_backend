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
        Schema::table('promotions', function (Blueprint $table) {
            $table->boolean('is_automatic')->default(false)->after('is_public')
                ->comment('If true, discount applies automatically without code. If false, acts as coupon.');
            $table->index('is_automatic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropIndex(['is_automatic']);
            $table->dropColumn('is_automatic');
        });
    }
};
