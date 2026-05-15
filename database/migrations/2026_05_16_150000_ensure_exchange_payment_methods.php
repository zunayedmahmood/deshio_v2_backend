<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_methods')) {
            return;
        }

        $now = now();
        $defaults = [
            'allowed_customer_types' => json_encode(['counter', 'social_commerce', 'ecommerce']),
            'is_active' => true,
            'requires_reference' => false,
            'supports_partial' => true,
            'fixed_fee' => 0,
            'percentage_fee' => 0,
            'updated_at' => $now,
        ];

        DB::table('payment_methods')->updateOrInsert(
            ['code' => 'exchange_balance'],
            array_merge($defaults, [
                'name' => 'Exchange Balance',
                'description' => 'Returned item value used against an exchange replacement order',
                'type' => 'other',
                'sort_order' => 90,
                'created_at' => $now,
            ])
        );

        DB::table('payment_methods')->updateOrInsert(
            ['code' => 'store_credit'],
            array_merge($defaults, [
                'name' => 'Store Credit',
                'description' => 'Customer credit issued from returns or exchanges',
                'type' => 'other',
                'sort_order' => 91,
                'created_at' => $now,
            ])
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('payment_methods')) {
            return;
        }

        DB::table('payment_methods')
            ->whereIn('code', ['exchange_balance', 'store_credit'])
            ->delete();
    }
};
