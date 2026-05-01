<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\Store;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServiceOrdersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get existing data
        $customer = Customer::first();
        $store = Store::first();
        $employee = Employee::first();
        $dryWashService = Service::where('service_code', 'DRY-WASH-001')->first();
        $ironingService = Service::where('service_code', 'IRON-001')->first();

        if (!$customer || !$store || !$employee || !$dryWashService || !$ironingService) {
            $this->command->warn('Required data not found. Please run other seeders first.');
            return;
        }

        // Create a service order for dry washing
        $serviceOrder1 = ServiceOrder::create([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'created_by' => $employee->id,
            'assigned_to' => $employee->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'subtotal' => 300.00,
            'tax_amount' => 0.00,
            'discount_amount' => 0.00,
            'total_amount' => 300.00,
            'paid_amount' => 300.00,
            'scheduled_date' => now()->addDays(1),
            'scheduled_time' => now()->setTime(10, 0),
            'estimated_completion' => now()->addDays(2),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
            'special_instructions' => 'Please handle delicate fabrics carefully',
            'notes' => 'Customer prefers eco-friendly detergents',
            'confirmed_at' => now(),
        ]);

        // Add service items to the order
        ServiceOrderItem::create([
            'service_order_id' => $serviceOrder1->id,
            'service_id' => $dryWashService->id,
            'service_name' => $dryWashService->name,
            'service_code' => $dryWashService->service_code,
            'service_description' => $dryWashService->description,
            'quantity' => 3, // 3 kg
            'unit_price' => 100.00, // per kg
            'base_price' => $dryWashService->base_price,
            'total_price' => 300.00,
            'selected_options' => [
                [
                    'label' => 'Express Service (12 hours)',
                    'price_modifier' => 50,
                ]
            ],
            'status' => 'confirmed',
            'scheduled_date' => $serviceOrder1->scheduled_date,
            'scheduled_time' => $serviceOrder1->scheduled_time,
            'estimated_duration' => $dryWashService->estimated_duration,
            'special_instructions' => 'Separate light and dark colors',
        ]);

        // Create another service order for ironing
        $serviceOrder2 = ServiceOrder::create([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'created_by' => $employee->id,
            'assigned_to' => $employee->id,
            'status' => 'in_progress',
            'payment_status' => 'partially_paid',
            'subtotal' => 100.00,
            'tax_amount' => 0.00,
            'discount_amount' => 0.00,
            'total_amount' => 100.00,
            'paid_amount' => 50.00,
            'scheduled_date' => now()->addDays(2),
            'scheduled_time' => now()->setTime(14, 0),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
            'special_instructions' => 'Professional folding required',
            'started_at' => now(),
        ]);

        // Add ironing service item
        ServiceOrderItem::create([
            'service_order_id' => $serviceOrder2->id,
            'service_id' => $ironingService->id,
            'service_name' => $ironingService->name,
            'service_code' => $ironingService->service_code,
            'service_description' => $ironingService->description,
            'quantity' => 5, // 5 pieces
            'unit_price' => 20.00, // per piece
            'base_price' => $ironingService->base_price,
            'total_price' => 100.00,
            'selected_options' => [
                [
                    'label' => 'Steam Ironing',
                    'price_modifier' => 20,
                ]
            ],
            'status' => 'in_progress',
            'scheduled_date' => $serviceOrder2->scheduled_date,
            'scheduled_time' => $serviceOrder2->scheduled_time,
            'estimated_duration' => $ironingService->estimated_duration,
            'started_at' => now(),
        ]);

        // Create a completed service order
        $serviceOrder3 = ServiceOrder::create([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'created_by' => $employee->id,
            'assigned_to' => $employee->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 200.00,
            'tax_amount' => 0.00,
            'discount_amount' => 20.00, // 10% discount
            'total_amount' => 180.00,
            'paid_amount' => 180.00,
            'scheduled_date' => now()->subDays(3),
            'scheduled_time' => now()->setTime(11, 0),
            'actual_completion' => now()->subDays(1),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
            'special_instructions' => 'Handle with care - expensive garments',
            'confirmed_at' => now()->subDays(3),
            'started_at' => now()->subDays(3),
            'completed_at' => now()->subDays(1),
        ]);

        // Add completed service item
        ServiceOrderItem::create([
            'service_order_id' => $serviceOrder3->id,
            'service_id' => $dryWashService->id,
            'service_name' => $dryWashService->name,
            'service_code' => $dryWashService->service_code,
            'service_description' => $dryWashService->description,
            'quantity' => 2, // 2 kg
            'unit_price' => 100.00, // per kg
            'base_price' => $dryWashService->base_price,
            'total_price' => 200.00,
            'selected_options' => [],
            'status' => 'completed',
            'scheduled_date' => $serviceOrder3->scheduled_date,
            'scheduled_time' => $serviceOrder3->scheduled_time,
            'estimated_duration' => $dryWashService->estimated_duration,
            'started_at' => $serviceOrder3->started_at,
            'completed_at' => $serviceOrder3->completed_at,
            'special_instructions' => 'Customer satisfied with service quality',
        ]);

        $this->command->info('Service orders seeded successfully!');
    }
}
