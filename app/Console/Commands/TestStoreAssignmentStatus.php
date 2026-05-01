<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\ProductBatch;
use App\Models\MasterInventory;
use Illuminate\Support\Facades\DB;

class TestStoreAssignmentStatus extends Command
{
    protected $signature = 'test:store-assignment-status';
    protected $description = 'Test store assignment status for ecommerce and social commerce orders';

    public function handle()
    {
        $this->info('🧪 Testing Store Assignment Status');
        $this->newLine();

        DB::beginTransaction();
        
        try {
            // 1. Create test customer
            $customer = Customer::create([
                'name' => 'Test Customer ' . time(),
                'email' => 'test' . time() . '@example.com',
                'phone' => '01712345678',
                'customer_type' => 'ecommerce',
                'status' => 'active'
            ]);
            $this->info("✅ Created customer: {$customer->name}");

            // 2. Create ecommerce order directly using Order::create()
            $this->newLine();
            $this->info('📦 Creating ECOMMERCE order using Order::create()...');
            
            $ecommerceOrder = Order::create([
                'customer_id' => $customer->id,
                'store_id' => null,
                'order_type' => 'ecommerce',
                'is_preorder' => false,
                'status' => 'pending_assignment', // Explicitly set
                'payment_status' => 'pending',
                'payment_method' => 'cod',
                'subtotal' => 100,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 50,
                'total_amount' => 150,
                'shipping_address' => [
                    'street' => '123 Test St',
                    'city' => 'Dhaka'
                ],
                'billing_address' => [
                    'street' => '123 Test St',
                    'city' => 'Dhaka'
                ],
            ]);

            $this->info("   Order Number: {$ecommerceOrder->order_number}");
            $this->info("   Order Type: {$ecommerceOrder->order_type}");
            $this->info("   Status: {$ecommerceOrder->status}");
            $this->info("   Store ID: " . ($ecommerceOrder->store_id ?? 'null'));

            // 4. Create social commerce order
            $this->newLine();
            $this->info('📦 Creating SOCIAL COMMERCE order using Order::create()...');
            
            $socialOrder = Order::create([
                'customer_id' => $customer->id,
                'store_id' => null,
                'order_type' => 'social_commerce',
                'is_preorder' => false,
                'status' => 'pending_assignment', // Explicitly set
                'payment_status' => 'pending',
                'payment_method' => 'cod',
                'subtotal' => 100,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 50,
                'total_amount' => 150,
                'shipping_address' => [
                    'street' => '123 Test St',
                    'city' => 'Dhaka'
                ],
                'billing_address' => [
                    'street' => '123 Test St',
                    'city' => 'Dhaka'
                ],
            ]);

            $this->info("   Order Number: {$socialOrder->order_number}");
            $this->info("   Order Type: {$socialOrder->order_type}");
            $this->info("   Status: {$socialOrder->status}");
            $this->info("   Store ID: " . ($socialOrder->store_id ?? 'null'));

            // 5. Query pending assignment orders
            $this->newLine();
            $this->info('🔍 Querying pending assignment orders...');
            
            $pendingOrders = Order::where('status', 'pending_assignment')
                ->whereIn('order_type', ['ecommerce', 'social_commerce'])
                ->get(['id', 'order_number', 'order_type', 'status', 'store_id']);

            $this->info("   Found {$pendingOrders->count()} orders with status 'pending_assignment'");
            
            foreach ($pendingOrders as $order) {
                $this->info("   - {$order->order_number} ({$order->order_type}): {$order->status}");
            }

            // 6. Verify what the API endpoint would return
            $this->newLine();
            $this->info('🌐 Simulating OrderManagementController::getPendingAssignmentOrders()...');
            
            $apiQuery = Order::where('status', 'pending_assignment')
                ->whereIn('order_type', ['ecommerce', 'social_commerce'])
                ->orderBy('created_at', 'asc')
                ->get();

            $this->info("   API would return: {$apiQuery->count()} orders");

            // 7. Check if there are any orders with status='pending'
            $this->newLine();
            $this->info('⚠️  Checking for orders with status="pending" (should be none)...');
            
            $pendingStatusOrders = Order::where('status', 'pending')
                ->whereIn('order_type', ['ecommerce', 'social_commerce'])
                ->get();

            if ($pendingStatusOrders->count() > 0) {
                $this->error("   ❌ Found {$pendingStatusOrders->count()} orders with status='pending' (incorrect!)");
                foreach ($pendingStatusOrders as $order) {
                    $this->error("      - {$order->order_number} ({$order->order_type})");
                }
            } else {
                $this->info("   ✅ No orders with status='pending' found (correct)");
            }

            // 8. Summary
            $this->newLine();
            $this->info('📊 SUMMARY:');
            $this->info("   ✅ Ecommerce order status: {$ecommerceOrder->status}");
            $this->info("   ✅ Social commerce order status: {$socialOrder->status}");
            $this->info("   ✅ Pending assignment query returns: {$pendingOrders->count()} orders");
            
            if ($ecommerceOrder->status === 'pending_assignment' && 
                $socialOrder->status === 'pending_assignment' &&
                $pendingOrders->count() === 2) {
                $this->newLine();
                $this->info('✅ ✅ ✅ ALL TESTS PASSED! Store assignment logic is working correctly.');
            } else {
                $this->newLine();
                $this->error('❌ TESTS FAILED! There is an issue with store assignment status.');
            }

            DB::rollBack();
            $this->newLine();
            $this->info('🔄 Test data rolled back (not committed to database)');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ Test failed: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        return 0;
    }
}
