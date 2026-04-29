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
        Schema::create('reserved_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->integer('total_inventory')->default(0);
            $table->integer('reserved_inventory')->default(0);
            $table->integer('available_inventory')->default(0);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        // Seed with current data
        $batches = DB::table('product_batches')
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id')
            ->get();

        foreach ($batches as $batch) {
            DB::table('reserved_products')->insert([
                'product_id' => $batch->product_id,
                'total_inventory' => $batch->total,
                'reserved_inventory' => 0,
                'available_inventory' => $batch->total,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reserved_products');
    }
};
