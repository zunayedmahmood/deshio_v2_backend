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
        Schema::table('inventory_rebalancings', function (Blueprint $table) {
            $table->foreignId('dispatch_id')->nullable()->after('approved_at')->constrained('product_dispatches')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        
        Schema::table('inventory_rebalancings', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropForeign(['dispatch_id']);
            }
            $table->dropColumn('dispatch_id');
        });
    }
};
