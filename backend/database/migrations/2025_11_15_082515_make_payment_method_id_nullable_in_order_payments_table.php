<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Make payment_method_id nullable to support split payments.
     * When a payment is split across multiple methods, the parent
     * OrderPayment record has payment_method_id = null, and each
     * split has its own payment_method_id in the payment_splits table.
     */
    public function up(): void
    {
        // SQLite doesn't support dropping foreign keys, so we need conditional logic
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'sqlite') {
            // For SQLite, just make the column nullable (foreign key is already defined in initial migration)
            Schema::table('order_payments', function (Blueprint $table) {
                $table->foreignId('payment_method_id')->nullable()->change();
            });
        } else {
            // For MySQL/PostgreSQL, drop and recreate the foreign key
            Schema::table('order_payments', function (Blueprint $table) {
                // Drop the foreign key constraint first
                $table->dropForeign(['payment_method_id']);
                
                // Make the column nullable
                $table->foreignId('payment_method_id')->nullable()->change();
                
                // Re-add the foreign key constraint
                $table->foreign('payment_method_id')
                    ->references('id')
                    ->on('payment_methods')
                    ->onDelete('restrict');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // SQLite doesn't support dropping foreign keys, so we need conditional logic
        $driver = Schema::getConnection()->getDriverName();
        
        if ($driver === 'sqlite') {
            // For SQLite, just make the column not nullable
            Schema::table('order_payments', function (Blueprint $table) {
                // Note: This will fail if there are records with null payment_method_id
                $table->foreignId('payment_method_id')->nullable(false)->change();
            });
        } else {
            // For MySQL/PostgreSQL, drop and recreate the foreign key
            Schema::table('order_payments', function (Blueprint $table) {
                // Drop the foreign key constraint
                $table->dropForeign(['payment_method_id']);
                
                // Make the column not nullable
                // Note: This will fail if there are records with null payment_method_id
                $table->foreignId('payment_method_id')->nullable(false)->change();
                
                // Re-add the foreign key constraint
                $table->foreign('payment_method_id')
                    ->references('id')
                    ->on('payment_methods')
                    ->onDelete('restrict');
            });
        }
    }
};
