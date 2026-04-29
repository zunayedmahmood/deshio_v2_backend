<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Change status column to string with more length to accommodate new statuses
            // Database-agnostic way - Laravel will handle the syntax for each DB
            $table->string('status', 50)->change();
        });
        
        // Note: CHECK constraints are not portable across all databases
        // Laravel doesn't have native support for CHECK constraints
        // Consider implementing status validation at the application level (model/controller)
        // or use enum cast in the model for stricter type safety
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Revert to original length if needed
            $table->string('status', 50)->change();
        });
    }
};
