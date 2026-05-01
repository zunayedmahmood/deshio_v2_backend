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
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('type', ['season', 'occasion', 'category', 'campaign'])->default('season');
            $table->string('season')->nullable(); // Spring, Summer, Fall, Winter
            $table->integer('year')->nullable();
            $table->date('launch_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('banner_image')->nullable();
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            
            $table->index('slug');
            $table->index('status');
            $table->index('type');
        });
        
        // Pivot table for collection products
        Schema::create('collection_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            
            $table->unique(['collection_id', 'product_id']);
            $table->index('collection_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_product');
        Schema::dropIfExists('collections');
    }
};
