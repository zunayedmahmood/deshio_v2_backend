<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PaymentMethodsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Counter customer payment methods
        PaymentMethod::create([
            'code' => 'cash',
            'name' => 'Cash',
            'description' => 'Cash payment at counter',
            'type' => 'cash',
            'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
            'is_active' => true,
            'supports_partial' => true,
            'sort_order' => 1,
        ]);

        PaymentMethod::create([
            'code' => 'card',
            'name' => 'Card Payment',
            'description' => 'Credit/Debit card payment',
            'type' => 'card',
            'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
            'is_active' => true,
            'requires_reference' => true,
            'supports_partial' => true,
            'fixed_fee' => 0.00,
            'percentage_fee' => 1.50, // 1.5% fee
            'sort_order' => 2,
        ]);

        PaymentMethod::create([
            'code' => 'online_pay',
            'name' => 'Online Pay',
            'description' => 'Online payment gateway',
            'type' => 'online_banking',
            'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
            'is_active' => true,
            'requires_reference' => true,
            'supports_partial' => true,
            'fixed_fee' => 5.00,
            'percentage_fee' => 0.50,
            'sort_order' => 3,
        ]);

        // Ecommerce specific payment methods
        PaymentMethod::create([
            'code' => 'bank_transfer',
            'name' => 'Bank Transfer',
            'description' => 'Direct bank transfer',
            'type' => 'bank_transfer',
            'allowed_customer_types' => ['ecommerce'],
            'is_active' => true,
            'requires_reference' => true,
            'supports_partial' => true,
            'sort_order' => 4,
        ]);

        // Social commerce specific payment methods
        PaymentMethod::create([
            'code' => 'online_banking',
            'name' => 'Online Banking',
            'description' => 'Online banking payment',
            'type' => 'online_banking',
            'allowed_customer_types' => ['social_commerce'],
            'is_active' => true,
            'requires_reference' => true,
            'supports_partial' => true,
            'fixed_fee' => 5.00,
            'sort_order' => 5,
        ]);

        // Mobile banking (available for all)
        PaymentMethod::create([
            'code' => 'mobile_banking',
            'name' => 'Mobile Banking',
            'description' => 'Mobile banking payment (bKash, Nagad, etc.)',
            'type' => 'mobile_banking',
            'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
            'is_active' => true,
            'requires_reference' => true,
            'supports_partial' => true,
            'fixed_fee' => 2.00,
            'percentage_fee' => 1.00,
            'sort_order' => 6,
        ]);

        // Digital wallet
        PaymentMethod::create([
            'code' => 'digital_wallet',
            'name' => 'Digital Wallet',
            'description' => 'Digital wallet payment',
            'type' => 'digital_wallet',
            'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
            'is_active' => true,
            'requires_reference' => true,
            'supports_partial' => true,
            'fixed_fee' => 1.00,
            'sort_order' => 7,
        ]);
    }
}