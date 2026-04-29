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
        Schema::table('orders', function (Blueprint $table) {
            // Add fields for fragmented payment tracking
            $table->decimal('paid_amount', 10, 2)->default(0)->after('total_amount');
            $table->decimal('outstanding_amount', 10, 2)->default(0)->after('paid_amount');

            // Payment plan/schedule tracking
            $table->boolean('is_installment_payment')->default(false)->after('outstanding_amount');
            $table->integer('total_installments')->nullable()->after('is_installment_payment');
            $table->integer('paid_installments')->default(0)->after('total_installments');
            $table->decimal('installment_amount', 10, 2)->nullable()->after('paid_installments');
            $table->date('next_payment_due')->nullable()->after('installment_amount');

            // Payment flexibility settings
            $table->boolean('allow_partial_payments')->default(true)->after('next_payment_due');
            $table->decimal('minimum_payment_amount', 10, 2)->nullable()->after('allow_partial_payments');

            // Enhanced metadata for payment tracking
            $table->json('payment_schedule')->nullable()->after('metadata'); // Expected payment dates and amounts
            $table->json('payment_history')->nullable()->after('payment_schedule'); // Actual payment records summary

            // Indexes for performance
            $table->index(['payment_status', 'next_payment_due']);
            $table->index(['is_installment_payment', 'paid_installments']);
        });

        // Update payment_status enum using drop/recreate for SQLite compatibility
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_status');
        });
        
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_status', ['pending', 'partially_paid', 'paid', 'failed', 'refunded', 'overdue'])->default('pending')->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert payment_status enum using drop/recreate for SQLite compatibility
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_status');
        });
        
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending')->after('id');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Remove new columns
            $table->dropColumn([
                'paid_amount',
                'outstanding_amount',
                'is_installment_payment',
                'total_installments',
                'paid_installments',
                'installment_amount',
                'next_payment_due',
                'allow_partial_payments',
                'minimum_payment_amount',
                'payment_schedule',
                'payment_history',
            ]);
        });
    }
};
