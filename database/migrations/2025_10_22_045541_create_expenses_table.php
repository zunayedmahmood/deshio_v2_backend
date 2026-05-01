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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number')->unique();

            // Relationships
            $table->foreignId('category_id')->constrained('expense_categories')->onDelete('restrict');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->onDelete('set null');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('set null'); // For salary expenses
            $table->foreignId('store_id')->constrained('stores')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('employees')->onDelete('cascade');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->foreignId('processed_by')->nullable()->constrained('employees')->onDelete('set null');

            // Expense details
            $table->decimal('amount', 10, 2);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2); // amount + tax - discount

            // Payment tracking
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->decimal('outstanding_amount', 10, 2); // total_amount - paid_amount

            // Status tracking
            $table->enum('status', [
                'draft',
                'pending_approval',
                'approved',
                'rejected',
                'cancelled',
                'processing',
                'completed'
            ])->default('draft');

            $table->enum('payment_status', [
                'unpaid',
                'partially_paid',
                'paid',
                'overpaid',
                'refunded'
            ])->default('unpaid');

            // Dates
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Reference information
            $table->string('reference_number')->nullable(); // Invoice/bill/receipt number
            $table->string('vendor_invoice_number')->nullable();
            $table->text('description');
            $table->text('notes')->nullable();

            // Expense type specific fields
            $table->enum('expense_type', [
                'vendor_payment',     // Payment to vendor/supplier
                'salary_payment',     // Employee salary payment
                'utility_bill',       // Electricity, water, internet, etc.
                'rent_lease',         // Office/shop rent or lease
                'logistics',          // Shipping, transportation costs
                'maintenance',        // Equipment/facility maintenance
                'marketing',          // Advertising, promotion costs
                'insurance',          // Insurance premiums
                'taxes',             // Tax payments
                'supplies',          // Office supplies, materials
                'travel',            // Travel and accommodation
                'training',          // Employee training costs
                'software',          // Software licenses, subscriptions
                'bank_charges',      // Bank fees, charges
                'depreciation',      // Asset depreciation
                'miscellaneous'      // Other expenses
            ])->default('miscellaneous');

            // Recurring expense support
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'])->nullable();
            $table->integer('recurrence_interval')->default(1); // Every X days/weeks/months/years
            $table->date('recurrence_end_date')->nullable();
            $table->foreignId('parent_expense_id')->nullable()->constrained('expenses')->onDelete('set null');

            // Attachments and documents
            $table->json('attachments')->nullable(); // File paths/URLs for receipts, invoices
            $table->json('metadata')->nullable(); // Additional custom data

            // Approval workflow
            $table->text('approval_notes')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['expense_number']);
            $table->index(['category_id']);
            $table->index(['vendor_id']);
            $table->index(['employee_id']);
            $table->index(['store_id']);
            $table->index(['status']);
            $table->index(['payment_status']);
            $table->index(['expense_date']);
            $table->index(['due_date']);
            $table->index(['expense_type']);
            $table->index(['is_recurring']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
