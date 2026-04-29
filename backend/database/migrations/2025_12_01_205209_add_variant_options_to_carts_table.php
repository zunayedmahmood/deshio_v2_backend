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
        Schema::table('carts', function (Blueprint $table) {
            // Drop the old unique constraint that doesn't account for variants
            $table->dropUnique('unique_customer_product_status');
            
            // Add variant_options JSON column
            $table->json('variant_options')->nullable()->after('product_id');
            
            // Add a computed hash column for variant_options to enable unique indexing
            // This is database-agnostic and works with MySQL, PostgreSQL, SQLite
            $table->string('variant_hash', 32)->nullable()->after('variant_options');
            
            // Add regular index on the hash column
            // Note: Uniqueness will be enforced at application level in CartController
            // since JSON comparison varies across databases
            $table->index(['customer_id', 'product_id', 'variant_hash', 'status'], 'idx_cart_variant_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // Drop the index
            $table->dropIndex('idx_cart_variant_lookup');
            
            // Remove variant_hash and variant_options columns
            $table->dropColumn(['variant_hash', 'variant_options']);
            
            // Restore old constraint
            $table->unique(['customer_id', 'product_id', 'status'], 'unique_customer_product_status');
        });
    }
};
