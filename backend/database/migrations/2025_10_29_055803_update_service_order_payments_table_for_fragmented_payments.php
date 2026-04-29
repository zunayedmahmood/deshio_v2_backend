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
        Schema::table('service_order_payments', function (Blueprint $table) {
            // Fragmented payment tracking
            if (!Schema::hasColumn('service_order_payments', 'is_partial_payment')) {
                $table->boolean('is_partial_payment')->default(false)->after('net_amount');
            }
            if (!Schema::hasColumn('service_order_payments', 'installment_number')) {
                $table->integer('installment_number')->nullable()->after('is_partial_payment'); // 1, 2, 3... for installment tracking
            }
            if (!Schema::hasColumn('service_order_payments', 'payment_type')) {
                $table->enum('payment_type', ['full', 'installment', 'partial', 'final', 'advance'])->default('full')->after('installment_number');
            }

            // Payment scheduling
            if (!Schema::hasColumn('service_order_payments', 'payment_due_date')) {
                $table->date('payment_due_date')->nullable()->after('payment_type'); // When this payment was expected
            }
            if (!Schema::hasColumn('service_order_payments', 'payment_received_date')) {
                $table->date('payment_received_date')->nullable()->after('payment_due_date'); // When payment was actually received
            }

            // Balance tracking
            if (!Schema::hasColumn('service_order_payments', 'order_balance_before')) {
                $table->decimal('order_balance_before', 10, 2)->nullable()->after('payment_received_date'); // Order balance before this payment
            }
            if (!Schema::hasColumn('service_order_payments', 'order_balance_after')) {
                $table->decimal('order_balance_after', 10, 2)->nullable()->after('order_balance_before'); // Order balance after this payment
            }

            // Installment metadata
            if (!Schema::hasColumn('service_order_payments', 'expected_installment_amount')) {
                $table->decimal('expected_installment_amount', 10, 2)->nullable()->after('order_balance_after'); // How much was expected for this installment
            }
            if (!Schema::hasColumn('service_order_payments', 'installment_notes')) {
                $table->text('installment_notes')->nullable()->after('expected_installment_amount'); // Notes about this specific installment
            }

            // Late payment tracking
            if (!Schema::hasColumn('service_order_payments', 'is_late_payment')) {
                $table->boolean('is_late_payment')->default(false)->after('installment_notes');
            }
            if (!Schema::hasColumn('service_order_payments', 'days_late')) {
                $table->integer('days_late')->nullable()->after('is_late_payment');
            }

            // Indexes for performance
            $table->index(['is_partial_payment', 'installment_number'], 'sop_partial_installment_idx');
            $table->index(['payment_type', 'payment_due_date'], 'sop_type_due_date_idx');
            $table->index(['is_late_payment', 'days_late'], 'sop_late_payment_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_order_payments', function (Blueprint $table) {
            // Remove new columns if they exist
            $columnsToDrop = [];
            if (Schema::hasColumn('service_order_payments', 'is_partial_payment')) {
                $columnsToDrop[] = 'is_partial_payment';
            }
            if (Schema::hasColumn('service_order_payments', 'installment_number')) {
                $columnsToDrop[] = 'installment_number';
            }
            if (Schema::hasColumn('service_order_payments', 'payment_type')) {
                $columnsToDrop[] = 'payment_type';
            }
            if (Schema::hasColumn('service_order_payments', 'payment_due_date')) {
                $columnsToDrop[] = 'payment_due_date';
            }
            if (Schema::hasColumn('service_order_payments', 'payment_received_date')) {
                $columnsToDrop[] = 'payment_received_date';
            }
            if (Schema::hasColumn('service_order_payments', 'order_balance_before')) {
                $columnsToDrop[] = 'order_balance_before';
            }
            if (Schema::hasColumn('service_order_payments', 'order_balance_after')) {
                $columnsToDrop[] = 'order_balance_after';
            }
            if (Schema::hasColumn('service_order_payments', 'expected_installment_amount')) {
                $columnsToDrop[] = 'expected_installment_amount';
            }
            if (Schema::hasColumn('service_order_payments', 'installment_notes')) {
                $columnsToDrop[] = 'installment_notes';
            }
            if (Schema::hasColumn('service_order_payments', 'is_late_payment')) {
                $columnsToDrop[] = 'is_late_payment';
            }
            if (Schema::hasColumn('service_order_payments', 'days_late')) {
                $columnsToDrop[] = 'days_late';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
