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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->enum('sub_type', [
                'current_asset', 'fixed_asset', 'other_asset',
                'current_liability', 'long_term_liability',
                'owner_equity', 'retained_earnings',
                'sales_revenue', 'other_income',
                'cost_of_goods_sold', 'operating_expenses', 'other_expenses'
            ])->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('level')->default(1); // For hierarchical display
            $table->string('path')->nullable(); // For hierarchical queries
            $table->timestamps();

            // Indexes
            $table->index(['type']);
            $table->index(['sub_type']);
            $table->index(['parent_id']);
            $table->index(['is_active']);
            $table->index(['level']);

            // Foreign keys
            $table->foreign('parent_id')->references('id')->on('accounts')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
