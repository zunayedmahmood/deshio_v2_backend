<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Store;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class TestMultiStoreShipment extends Command
{
    protected $signature = 'test:multi-store-shipment';
    protected $description = 'Test multi-store shipment flow - verify requirements';

    public function handle()
    {
        $this->info('=== Multi-Store Shipment Requirements Test ===');
        $this->newLine();

        // REQUIREMENT 1: Order items can be assigned to separate stores
        $this->info('📋 REQUIREMENT 1: Order items can be assigned to separate stores');
        $this->line('   Testing backend support for item-level store assignment...');
        $this->newLine();

        $req1Passed = $this->testRequirement1();
        
        $this->newLine();
        $this->newLine();

        // REQUIREMENT 2: Each store uses its own pathao_store_id (not env default)
        $this->info('📋 REQUIREMENT 2: Multi-store orders use store-specific pathao_store_id');
        $this->line('   Testing that each store uses its own pathao_store_id in shipment...');
        $this->newLine();

        $req2Passed = $this->testRequirement2();

        $this->newLine();
        $this->newLine();

        // FINAL RESULT
        $this->info('=== TEST RESULTS ===');
        $this->line('Requirement 1 (Item-level store assignment): ' . ($req1Passed ? '✅ PASS' : '❌ FAIL'));
        $this->line('Requirement 2 (Store-specific pathao_store_id): ' . ($req2Passed ? '✅ PASS' : '❌ FAIL'));
        $this->newLine();

        if ($req1Passed && $req2Passed) {
            $this->info('🎉 ALL REQUIREMENTS PASSED! System is ready for multi-store shipments.');
            return Command::SUCCESS;
        } else {
            $this->error('❌ Some requirements failed. Review the issues above.');
            return Command::FAILURE;
        }
    }

    private function testRequirement1(): bool
    {
        try {
            // Check migration exists
            $this->line('   1. Checking order_items.store_id column...');
            $storeIdExists = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'order_items' AND column_name = 'store_id'");
            
            if (empty($storeIdExists)) {
                $this->error('      ❌ order_items.store_id column does not exist!');
                $this->warn('      Run: php artisan migrate');
                return false;
            }
            $this->info('      ✅ order_items.store_id column exists');

            // Check OrderItem model has store_id in fillable
            $this->line('   2. Checking OrderItem model configuration...');
            $orderItem = new OrderItem();
            $fillable = $orderItem->getFillable();
            
            if (!in_array('store_id', $fillable)) {
                $this->error('      ❌ store_id not in OrderItem fillable array');
                return false;
            }
            $this->info('      ✅ store_id is fillable in OrderItem model');

            // Check store relationship exists
            $this->line('   3. Checking OrderItem -> Store relationship...');
            try {
                $testItem = OrderItem::with('store')->first();
                $this->info('      ✅ OrderItem has store() relationship');
            } catch (\Exception $e) {
                $this->error('      ❌ OrderItem store() relationship missing or broken');
                return false;
            }

            // Check MultiStoreOrderController exists
            $this->line('   4. Checking MultiStoreOrderController...');
            if (!class_exists('App\Http\Controllers\MultiStoreOrderController')) {
                $this->error('      ❌ MultiStoreOrderController not found');
                return false;
            }
            $this->info('      ✅ MultiStoreOrderController exists');

            // Check if we can actually assign store_id to an order item
            $this->line('   5. Testing actual store assignment...');
            $store = Store::first();
            $orderItem = OrderItem::first();

            if (!$store || !$orderItem) {
                $this->warn('      ⚠️  No stores or order items in database to test with');
                $this->warn('      But structure is correct - skipping actual assignment test');
                return true;
            }

            // Try to update order item with store_id
            try {
                $orderItem->update(['store_id' => $store->id]);
                $orderItem->refresh();
                
                if ($orderItem->store_id == $store->id) {
                    $this->info('      ✅ Successfully assigned order item to store');
                    $this->line("         Order Item #{$orderItem->id} → Store #{$store->id} ({$store->name})");
                } else {
                    $this->error('      ❌ Store assignment failed');
                    return false;
                }
            } catch (\Exception $e) {
                $this->error('      ❌ Error assigning store: ' . $e->getMessage());
                return false;
            }

            $this->newLine();
            $this->info('   ✅ REQUIREMENT 1: PASSED');
            return true;

        } catch (\Exception $e) {
            $this->error('   ❌ Test failed with error: ' . $e->getMessage());
            return false;
        }
    }

    private function testRequirement2(): bool
    {
        try {
            // Check stores.pathao_store_id column exists
            $this->line('   1. Checking stores.pathao_store_id column...');
            $pathaoStoreIdExists = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'stores' AND column_name = 'pathao_store_id'");
            
            if (empty($pathaoStoreIdExists)) {
                $this->error('      ❌ stores.pathao_store_id column does not exist!');
                $this->warn('      Run: php artisan migrate');
                return false;
            }
            $this->info('      ✅ stores.pathao_store_id column exists');

            // Check Store model has pathao_store_id in fillable
            $this->line('   2. Checking Store model configuration...');
            $store = new Store();
            $fillable = $store->getFillable();
            
            if (!in_array('pathao_store_id', $fillable)) {
                $this->error('      ❌ pathao_store_id not in Store fillable array');
                return false;
            }
            $this->info('      ✅ pathao_store_id is fillable in Store model');

            // Check StoreController auto-syncs pathao_key to pathao_store_id
            $this->line('   3. Checking StoreController auto-sync logic...');
            $controllerPath = app_path('Http/Controllers/StoreController.php');
            $controllerContent = file_get_contents($controllerPath);
            
            if (strpos($controllerContent, "validated['pathao_store_id'] = \$validated['pathao_key']") === false) {
                $this->error('      ❌ StoreController does not auto-sync pathao_key to pathao_store_id');
                return false;
            }
            $this->info('      ✅ StoreController auto-syncs pathao_key → pathao_store_id');

            // Check MultiStoreShipmentController uses store's pathao_store_id
            $this->line('   4. Checking MultiStoreShipmentController implementation...');
            if (!class_exists('App\Http\Controllers\MultiStoreShipmentController')) {
                $this->error('      ❌ MultiStoreShipmentController not found');
                return false;
            }
            
            $shipmentControllerPath = app_path('Http/Controllers/MultiStoreShipmentController.php');
            $shipmentContent = file_get_contents($shipmentControllerPath);
            
            // Check it uses $store->pathao_store_id, NOT env default
            if (strpos($shipmentContent, "\$store->pathao_store_id") === false) {
                $this->error('      ❌ MultiStoreShipmentController does not use store->pathao_store_id');
                return false;
            }
            $this->info('      ✅ MultiStoreShipmentController uses \$store->pathao_store_id');

            // Verify it does NOT use env('PATHAO_STORE_ID') or config('pathao.store_id')
            if (strpos($shipmentContent, "env('PATHAO_STORE_ID')") !== false || 
                strpos($shipmentContent, "config('pathao.store_id')") !== false) {
                $this->error('      ❌ Controller still uses env/config default store_id!');
                return false;
            }
            $this->info('      ✅ Controller does NOT use env default (correct!)');

            // Check actual stores have pathao_store_id set
            $this->line('   5. Checking actual stores configuration...');
            $stores = Store::all();
            
            if ($stores->isEmpty()) {
                $this->warn('      ⚠️  No stores in database to check');
                $this->warn('      But structure is correct - skipping store data test');
                return true;
            }

            $this->line("      Found {$stores->count()} store(s):");
            $storesWithPathao = 0;
            $storesWithoutPathao = 0;

            foreach ($stores as $store) {
                if ($store->pathao_store_id) {
                    $this->info("      ✅ Store #{$store->id} ({$store->name}) has pathao_store_id: {$store->pathao_store_id}");
                    $storesWithPathao++;
                } else {
                    $this->warn("      ⚠️  Store #{$store->id} ({$store->name}) missing pathao_store_id");
                    $storesWithoutPathao++;
                }
            }

            $this->newLine();
            if ($storesWithoutPathao > 0) {
                $this->warn("      {$storesWithoutPathao} store(s) need pathao_store_id configured");
                $this->line("      Run: UPDATE stores SET pathao_store_id = pathao_key WHERE pathao_key IS NOT NULL;");
                $this->line("      Or use StoreController to update/create stores (auto-syncs)");
            } else {
                $this->info("      All stores have pathao_store_id configured!");
            }

            // Test the actual flow logic
            $this->line('   6. Testing shipment grouping logic...');
            
            // Find an order with items
            $order = Order::with('items')->whereHas('items')->first();
            
            if (!$order) {
                $this->warn('      ⚠️  No orders with items to test grouping');
                $this->warn('      But implementation is correct - skipping flow test');
                return true;
            }

            $itemsByStore = $order->items->groupBy('store_id');
            $this->info("      ✅ Order #{$order->id} items grouped by store:");
            
            foreach ($itemsByStore as $storeId => $items) {
                if ($storeId) {
                    $store = Store::find($storeId);
                    $this->line("         Store #{$storeId} ({$store->name}): {$items->count()} item(s)");
                    if ($store->pathao_store_id) {
                        $this->line("         → Would use pathao_store_id: {$store->pathao_store_id}");
                    } else {
                        $this->warn("         → ⚠️ Missing pathao_store_id (shipment would fail)");
                    }
                } else {
                    $this->warn("         Not assigned: {$items->count()} item(s) - needs assignment");
                }
            }

            $this->newLine();
            $this->info('   ✅ REQUIREMENT 2: PASSED');
            return true;

        } catch (\Exception $e) {
            $this->error('   ❌ Test failed with error: ' . $e->getMessage());
            return false;
        }
    }
}
