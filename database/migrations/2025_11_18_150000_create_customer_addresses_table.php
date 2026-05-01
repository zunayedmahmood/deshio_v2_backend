<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            
            // Address type: shipping, billing, both
            $table->enum('type', ['shipping', 'billing', 'both'])->default('both');
            
            // Contact details
            $table->string('name');
            $table->string('phone')->nullable();
            
            // Address components
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city');
            $table->string('state');
            $table->string('postal_code');
            $table->string('country')->default('Bangladesh');
            $table->string('landmark')->nullable();
            
            // Default address flags
            $table->boolean('is_default_shipping')->default(false);
            $table->boolean('is_default_billing')->default(false);
            
            // Additional info
            $table->text('delivery_instructions')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['customer_id', 'type']);
            $table->index(['customer_id', 'is_default_shipping']);
            $table->index(['customer_id', 'is_default_billing']);
            $table->index(['city', 'state']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_addresses');
    }
};