<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BulkStoreAssignmentTestController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function run(Request $request): JsonResponse
    {
        $cleanup = $request->boolean('cleanup', true);
        $runKey = 'BSA-' . now()->format('YmdHis') . '-' . substr((string) uniqid(), -5);

        $created = [
            'store_ids' => [],
            'product_ids' => [],
            'batch_ids' => [],
            'order_ids' => [],
            'customer_ids' => [],
        ];

        $tests = [];
        $context = [];

        $addTest = function (string $name, bool $passed, string $expected, $actual, array $details = []) use (&$tests) {
            $tests[] = [
                'name' => $name,
                'passed' => $passed,
                'expected' => $expected,
                'actual' => $actual,
                'details' => $details,
            ];
        };

        try {
            DB::beginTransaction();

            $category = Category::firstOrCreate(
                ['slug' => 'bulk-store-assignment-test'],
                [
                    'title' => 'Bulk Store Assignment Test',
                    'description' => 'Temporary category used by public/full-testing.html diagnostics.',
                    'is_active' => true,
                ]
            );

            $vendor = Vendor::firstOrCreate(
                ['email' => 'bulk-store-assignment-test@deshio.local'],
                [
                    'name' => 'Bulk Store Assignment Test Vendor',
                    'phone' => '01000000000',
                    'address' => 'Test Address',
                    'type' => 'supplier',
                    'is_active' => true,
                ]
            );

            $storeA = Store::create([
                'name' => "{$runKey} Fulfillment Store",
                'address' => 'Diagnostic Store A',
                'store_code' => "{$runKey}-A",
                'is_active' => true,
                'is_online' => true,
                'is_warehouse' => false,
            ]);
            $storeB = Store::create([
                'name' => "{$runKey} Partial Store",
                'address' => 'Diagnostic Store B',
                'store_code' => "{$runKey}-B",
                'is_active' => true,
                'is_online' => true,
                'is_warehouse' => false,
            ]);
            $inactiveStore = Store::create([
                'name' => "{$runKey} Inactive Store",
                'address' => 'Diagnostic Inactive Store',
                'store_code' => "{$runKey}-I",
                'is_active' => false,
                'is_online' => true,
                'is_warehouse' => false,
            ]);
            $created['store_ids'] = [$storeA->id, $storeB->id, $inactiveStore->id];

            $productA = Product::create([
                'category_id' => $category->id,
                'vendor_id' => $vendor->id,
                'sku' => "{$runKey}-SKU-A",
                'name' => "{$runKey} Test Product A",
                'description' => 'Bulk assignment diagnostic product A',
                'is_archived' => false,
            ]);
            $productB = Product::create([
                'category_id' => $category->id,
                'vendor_id' => $vendor->id,
                'sku' => "{$runKey}-SKU-B",
                'name' => "{$runKey} Test Product B",
                'description' => 'Bulk assignment diagnostic product B',
                'is_archived' => false,
            ]);
            $created['product_ids'] = [$productA->id, $productB->id];

            $batchA1 = ProductBatch::create([
                'product_id' => $productA->id,
                'batch_number' => "{$runKey}-A1",
                'quantity' => 5,
                'cost_price' => 10,
                'sell_price' => 20,
                'availability' => true,
                'expiry_date' => now()->addYear()->toDateString(),
                'store_id' => $storeA->id,
                'notes' => 'Bulk assignment test stock',
                'is_active' => true,
            ]);
            $batchB1 = ProductBatch::create([
                'product_id' => $productB->id,
                'batch_number' => "{$runKey}-B1",
                'quantity' => 3,
                'cost_price' => 10,
                'sell_price' => 20,
                'availability' => true,
                'expiry_date' => now()->addYear()->toDateString(),
                'store_id' => $storeA->id,
                'notes' => 'Bulk assignment test stock',
                'is_active' => true,
            ]);
            $batchPartial = ProductBatch::create([
                'product_id' => $productA->id,
                'batch_number' => "{$runKey}-P1",
                'quantity' => 1,
                'cost_price' => 10,
                'sell_price' => 20,
                'availability' => true,
                'expiry_date' => now()->addYear()->toDateString(),
                'store_id' => $storeB->id,
                'notes' => 'Bulk assignment partial stock',
                'is_active' => true,
            ]);
            $batchInactive = ProductBatch::create([
                'product_id' => $productA->id,
                'batch_number' => "{$runKey}-I1",
                'quantity' => 10,
                'cost_price' => 10,
                'sell_price' => 20,
                'availability' => true,
                'expiry_date' => now()->addYear()->toDateString(),
                'store_id' => $inactiveStore->id,
                'notes' => 'Bulk assignment inactive store stock',
                'is_active' => true,
            ]);
            $created['batch_ids'] = [$batchA1->id, $batchB1->id, $batchPartial->id, $batchInactive->id];

            $customer = Customer::create([
                'customer_type' => 'ecommerce',
                'name' => "{$runKey} Test Customer",
                'phone' => '01' . random_int(100000000, 999999999),
                'email' => strtolower($runKey) . '@deshio-test.local',
                'address' => 'Bulk assignment diagnostic address',
                'status' => 'active',
            ]);
            $created['customer_ids'][] = $customer->id;

            $makeOrder = function (
                string $suffix,
                string $status,
                ?int $storeId,
                array $items,
                string $orderType = 'ecommerce',
                int $createdOffsetSeconds = 0
            ) use ($runKey, $customer, &$created) {
                $subtotal = collect($items)->sum(fn ($row) => $row['quantity'] * $row['unit_price']);
                $createdAt = now()->addSeconds($createdOffsetSeconds);

                $order = Order::create([
                    'order_number' => "{$runKey}-{$suffix}",
                    'customer_id' => $customer->id,
                    'store_id' => $storeId,
                    'order_type' => $orderType,
                    'status' => $status,
                    'payment_status' => 'pending',
                    'payment_method' => 'cod',
                    'subtotal' => $subtotal,
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                    'shipping_amount' => 0,
                    'total_amount' => $subtotal,
                    'order_date' => $createdAt,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'metadata' => ['bulk_store_assignment_test' => true, 'run_key' => $runKey],
                ]);

                foreach ($items as $row) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $row['product']->id,
                        'product_name' => $row['product']->name,
                        'product_sku' => $row['product']->sku,
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'discount_amount' => 0,
                        'tax_amount' => 0,
                        'total_amount' => $row['quantity'] * $row['unit_price'],
                        'store_id' => $storeId,
                    ]);
                }

                $created['order_ids'][] = $order->id;
                return $order->fresh(['items']);
            };

            $orderOne = $makeOrder('ORDER-1-SINGLE', 'pending_assignment', null, [
                ['product' => $productA, 'quantity' => 1, 'unit_price' => 20],
                ['product' => $productB, 'quantity' => 1, 'unit_price' => 20],
            ], 'ecommerce', 10);
            $orderTwo = $makeOrder('ORDER-2-PARTIAL-SUCCESS', 'pending_assignment', null, [
                ['product' => $productA, 'quantity' => 2, 'unit_price' => 20],
            ], 'social_commerce', 20);
            $orderThree = $makeOrder('ORDER-3-INSUFFICIENT', 'pending_assignment', null, [
                ['product' => $productA, 'quantity' => 99, 'unit_price' => 20],
            ], 'ecommerce', 30);
            $orderFour = $makeOrder('ORDER-4-NONPENDING', 'confirmed', $storeA->id, [
                ['product' => $productA, 'quantity' => 1, 'unit_price' => 20],
            ], 'ecommerce', 40);
            $orderFive = $makeOrder('ORDER-5-OVER-CAPACITY-SUCCESS', 'pending_assignment', null, [
                ['product' => $productA, 'quantity' => 2, 'unit_price' => 20],
            ], 'ecommerce', 50);
            $orderSix = $makeOrder('ORDER-6-OVER-CAPACITY-FAIL', 'pending_assignment', null, [
                ['product' => $productA, 'quantity' => 1, 'unit_price' => 20],
            ], 'ecommerce', 60);
            $orderSeven = $makeOrder('ORDER-7-ALREADY-ASSIGNED', 'pending_assignment', $storeA->id, [
                ['product' => $productA, 'quantity' => 1, 'unit_price' => 20],
            ], 'ecommerce', 70);
            $orderEight = $makeOrder('ORDER-8-WRONG-TYPE', 'pending_assignment', null, [
                ['product' => $productA, 'quantity' => 1, 'unit_price' => 20],
            ], 'pos', 80);
            $orderNine = $makeOrder('ORDER-9-NO-ITEMS', 'pending_assignment', null, [], 'ecommerce', 90);
            $orderTen = $makeOrder('ORDER-10-MULTI-B1', 'pending_assignment', null, [
                ['product' => $productB, 'quantity' => 1, 'unit_price' => 20],
            ], 'ecommerce', 100);
            $orderEleven = $makeOrder('ORDER-11-MULTI-B2', 'pending_assignment', null, [
                ['product' => $productB, 'quantity' => 1, 'unit_price' => 20],
            ], 'social_commerce', 110);

            DB::commit();

            $context = [
                'run_key' => $runKey,
                'stores' => [
                    'full_store' => ['id' => $storeA->id, 'name' => $storeA->name, 'product_a_qty' => 5, 'product_b_qty' => 3],
                    'partial_store' => ['id' => $storeB->id, 'name' => $storeB->name, 'product_a_qty' => 1],
                    'inactive_store' => ['id' => $inactiveStore->id, 'name' => $inactiveStore->name],
                ],
                'orders' => [
                    'single_success' => $orderOne->order_number,
                    'partial_success_order' => $orderTwo->order_number,
                    'insufficient' => $orderThree->order_number,
                    'non_pending' => $orderFour->order_number,
                    'over_capacity_success' => $orderFive->order_number,
                    'over_capacity_fail' => $orderSix->order_number,
                    'already_assigned' => $orderSeven->order_number,
                    'wrong_type' => $orderEight->order_number,
                    'no_items' => $orderNine->order_number,
                    'multi_success_one' => $orderTen->order_number,
                    'multi_success_two' => $orderEleven->order_number,
                ],
            ];

            $manager = app(OrderManagementController::class);

            $callBulkAssign = function (array $payload) use ($manager) {
                $response = $manager->bulkAssignOrdersToStorePending(new Request($payload));
                return [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getData(true),
                ];
            };

            $callPageData = function (array $query = []) use ($manager) {
                $response = $manager->getBulkPendingAssignmentOrders(new Request($query));
                return [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getData(true),
                ];
            };

            $initialPageData = $callPageData(['per_page' => 999, 'sort_order' => 'asc']);
            $initialOrders = collect($initialPageData['body']['data']['orders'] ?? []);
            $initialTestOrders = $initialOrders->filter(fn ($order) => str_starts_with((string) ($order['order_number'] ?? ''), $runKey))->values();
            $initialOrderNumbers = $initialTestOrders->pluck('order_number')->values();
            $initialStores = collect($initialPageData['body']['data']['stores'] ?? []);
            $orderOnePage = $initialTestOrders->firstWhere('order_number', $orderOne->order_number);
            $orderOneStoreRows = collect($orderOnePage['available_stores_summary'] ?? []);
            $orderOneFullStoreRow = $orderOneStoreRows->firstWhere('store_id', $storeA->id);
            $orderOnePartialStoreRow = $orderOneStoreRows->firstWhere('store_id', $storeB->id);

            $addTest(
                'Page API contract: pending list, stores, pagination, and item/store summaries load',
                $initialPageData['status'] === 200
                    && ($initialPageData['body']['success'] ?? false) === true
                    && $initialOrders->isNotEmpty()
                    && $initialStores->contains('id', $storeA->id)
                    && $initialStores->contains('id', $storeB->id)
                    && !$initialStores->contains('id', $inactiveStore->id)
                    && isset($initialPageData['body']['data']['pagination'])
                    && ((int) ($initialPageData['body']['data']['pagination']['per_page'] ?? 0) <= 200)
                    && !empty($orderOnePage['items_summary'])
                    && !empty($orderOnePage['available_stores_summary']),
                'The /bulk-pending-assignment endpoint should return only active stores, capped pagination, ordered items, and store fulfillment rows for the page',
                [
                    'status' => $initialPageData['status'],
                    'pagination' => $initialPageData['body']['data']['pagination'] ?? null,
                    'visible_test_orders' => $initialOrderNumbers,
                    'visible_test_store_ids' => $initialStores->pluck('id')->filter(fn ($id) => in_array((int) $id, $created['store_ids'], true))->values(),
                ]
            );

            $addTest(
                'Page filtering: only unassigned ecommerce/social_commerce pending_assignment orders are visible',
                $initialOrderNumbers->contains($orderOne->order_number)
                    && $initialOrderNumbers->contains($orderTwo->order_number)
                    && $initialOrderNumbers->contains($orderThree->order_number)
                    && $initialOrderNumbers->contains($orderFive->order_number)
                    && $initialOrderNumbers->contains($orderSix->order_number)
                    && $initialOrderNumbers->contains($orderNine->order_number)
                    && $initialOrderNumbers->contains($orderTen->order_number)
                    && $initialOrderNumbers->contains($orderEleven->order_number)
                    && !$initialOrderNumbers->contains($orderFour->order_number)
                    && !$initialOrderNumbers->contains($orderSeven->order_number)
                    && !$initialOrderNumbers->contains($orderEight->order_number),
                'The new page should not show already assigned orders, non-pending orders, or unsupported order types',
                [
                    'visible_test_orders' => $initialOrderNumbers,
                    'hidden_expected' => [$orderFour->order_number, $orderSeven->order_number, $orderEight->order_number],
                ]
            );

            $addTest(
                'Page fulfillment matrix: shows which store can fulfill the order',
                !empty($orderOneFullStoreRow)
                    && !empty($orderOnePartialStoreRow)
                    && ($orderOneFullStoreRow['can_fulfill_entire_order'] ?? false) === true
                    && ($orderOnePartialStoreRow['can_fulfill_entire_order'] ?? true) === false
                    && (int) ($orderOnePage['best_fulfillment_store']['store_id'] ?? 0) === (int) $storeA->id,
                'A fully stocked store should be marked fulfillable; a partial store should show insufficient; best store should recommend the fulfillable store',
                [
                    'order_number' => $orderOne->order_number,
                    'full_store_row' => $orderOneFullStoreRow,
                    'partial_store_row' => $orderOnePartialStoreRow,
                    'recommendation' => $orderOnePage['best_fulfillment_store'] ?? null,
                ]
            );

            $missingStore = $callBulkAssign(['order_ids' => [$orderOne->id]]);
            $addTest(
                'Validation: store is required',
                $missingStore['status'] === 422,
                'API rejects request without store_id before changing any order',
                $missingStore
            );

            $emptyOrders = $callBulkAssign(['store_id' => $storeA->id, 'order_ids' => []]);
            $addTest(
                'Validation: at least one order is required',
                $emptyOrders['status'] === 422,
                'API rejects empty order selection before changing any order',
                $emptyOrders
            );

            $duplicateOrders = $callBulkAssign(['store_id' => $storeA->id, 'order_ids' => [$orderOne->id, $orderOne->id]]);
            $addTest(
                'Validation: duplicate selected order IDs are rejected',
                $duplicateOrders['status'] === 422,
                'API rejects duplicate IDs so select-all/manual selection cannot accidentally double-count the same order',
                $duplicateOrders
            );

            $inactiveStoreAttempt = $callBulkAssign(['store_id' => $inactiveStore->id, 'order_ids' => [$orderOne->id]]);
            $addTest(
                'Validation: inactive store cannot be assigned',
                $inactiveStoreAttempt['status'] === 422,
                'API rejects inactive stores even if they have physical batches',
                $inactiveStoreAttempt
            );

            $nonPending = $callBulkAssign(['store_id' => $storeA->id, 'order_ids' => [$orderFour->id]]);
            $addTest(
                'Negative case: non-pending_assignment order is rejected',
                ($nonPending['body']['data']['failed_count'] ?? 0) === 1
                    && $orderFour->fresh()->status === 'confirmed'
                    && (int) $orderFour->fresh()->store_id === (int) $storeA->id,
                'Order already outside pending_assignment should not be reassigned by this page endpoint',
                [
                    'response' => $nonPending,
                    'status_after' => $orderFour->fresh()->status,
                    'store_after' => $orderFour->fresh()->store_id,
                ]
            );

            $alreadyAssigned = $callBulkAssign(['store_id' => $storeB->id, 'order_ids' => [$orderSeven->id]]);
            $freshOrderSeven = $orderSeven->fresh();
            $addTest(
                'Negative case: pending_assignment order with existing store_id is rejected',
                ($alreadyAssigned['body']['data']['failed_count'] ?? 0) === 1
                    && $freshOrderSeven->status === 'pending_assignment'
                    && (int) $freshOrderSeven->store_id === (int) $storeA->id,
                'A half-assigned/stale order should not be silently moved to another store by bulk assignment',
                [
                    'response' => $alreadyAssigned,
                    'status_after' => $freshOrderSeven->status,
                    'store_after' => $freshOrderSeven->store_id,
                ]
            );

            $noItems = $callBulkAssign(['store_id' => $storeA->id, 'order_ids' => [$orderNine->id]]);
            $freshOrderNine = $orderNine->fresh();
            $addTest(
                'Negative case: order with no product items is rejected',
                ($noItems['body']['data']['failed_count'] ?? 0) === 1
                    && $freshOrderNine->status === 'pending_assignment'
                    && $freshOrderNine->store_id === null,
                'Bulk assignment should not assign an empty order because no store can actually fulfill it',
                [
                    'response' => $noItems,
                    'status_after' => $freshOrderNine->status,
                    'store_after' => $freshOrderNine->store_id,
                ]
            );

            $partialStoreCannotFulfill = $callBulkAssign(['store_id' => $storeB->id, 'order_ids' => [$orderOne->id]]);
            $freshOrderOneAfterPartialStore = $orderOne->fresh();
            $addTest(
                'Negative case: selected store cannot fulfill whole order',
                ($partialStoreCannotFulfill['body']['data']['failed_count'] ?? 0) === 1
                    && $freshOrderOneAfterPartialStore->status === 'pending_assignment'
                    && $freshOrderOneAfterPartialStore->store_id === null,
                'If the selected store is missing any ordered item, the order should stay pending_assignment',
                [
                    'response' => $partialStoreCannotFulfill,
                    'order_status' => $freshOrderOneAfterPartialStore->status,
                    'order_store_id' => $freshOrderOneAfterPartialStore->store_id,
                ]
            );

            $partialSuccess = $callBulkAssign([
                'store_id' => $storeA->id,
                'order_ids' => [$orderTwo->id, $orderThree->id],
                'notes' => 'partial success diagnostic',
            ]);
            $freshOrderTwo = $orderTwo->fresh(['items']);
            $freshOrderThree = $orderThree->fresh();
            $addTest(
                'Mixed selection: one fulfillable order succeeds and one insufficient order fails',
                $partialSuccess['status'] === 200
                    && ($partialSuccess['body']['partial_success'] ?? false) === true
                    && ($partialSuccess['body']['data']['assigned_count'] ?? 0) === 1
                    && ($partialSuccess['body']['data']['failed_count'] ?? 0) === 1
                    && $freshOrderTwo->status === 'assigned_to_store'
                    && (int) $freshOrderTwo->store_id === (int) $storeA->id
                    && $freshOrderThree->status === 'pending_assignment'
                    && $freshOrderThree->store_id === null,
                'A mixed payload should return partial_success, assign the fulfillable order, and leave the failed one unassigned',
                [
                    'response' => $partialSuccess,
                    'success_order_status' => $freshOrderTwo->status,
                    'failed_order_status' => $freshOrderThree->status,
                ]
            );

            $singleSuccess = $callBulkAssign([
                'store_id' => $storeA->id,
                'order_ids' => [$orderOne->id],
                'notes' => 'single success diagnostic',
            ]);
            $freshOrderOne = $orderOne->fresh(['items']);
            $metadata = $freshOrderOne->metadata ?? [];
            $addTest(
                'Positive case: single selected order assignment',
                ($singleSuccess['body']['data']['assigned_count'] ?? 0) === 1
                    && $freshOrderOne->status === 'assigned_to_store'
                    && (int) $freshOrderOne->store_id === (int) $storeA->id
                    && $freshOrderOne->items->every(fn ($item) => (int) $item->store_id === (int) $storeA->id)
                    && ($metadata['bulk_assignment_target_status'] ?? null) === 'assigned_to_store',
                'One selected pending_assignment order becomes assigned_to_store, gets store_id, item store_id is synced, and metadata records the target status',
                [
                    'response' => $singleSuccess,
                    'new_status' => $freshOrderOne->status,
                    'store_id' => $freshOrderOne->store_id,
                    'item_store_ids' => $freshOrderOne->items->pluck('store_id')->values(),
                    'metadata' => $metadata,
                ]
            );

            $reassignSameOrder = $callBulkAssign(['store_id' => $storeA->id, 'order_ids' => [$orderOne->id]]);
            $addTest(
                'Idempotency/safety: already assigned_to_store order cannot be bulk-assigned again',
                ($reassignSameOrder['body']['data']['failed_count'] ?? 0) === 1
                    && $orderOne->fresh()->status === 'assigned_to_store'
                    && (int) $orderOne->fresh()->store_id === (int) $storeA->id,
                'After a successful assignment, the same order should not be reprocessed as if it were still pending_assignment',
                $reassignSameOrder
            );

            $batchQuantitiesAfterAssignments = ProductBatch::whereIn('id', [$batchA1->id, $batchB1->id, $batchPartial->id])->pluck('quantity', 'id');
            $addTest(
                'Stock safety: assignment does not deduct physical batches',
                (int) $batchQuantitiesAfterAssignments[$batchA1->id] === 5
                    && (int) $batchQuantitiesAfterAssignments[$batchB1->id] === 3
                    && (int) $batchQuantitiesAfterAssignments[$batchPartial->id] === 1,
                'Assigning to store should promise inventory only; physical stock is deducted later during fulfillment/scanning',
                $batchQuantitiesAfterAssignments
            );

            $multiSuccess = $callBulkAssign([
                'store_id' => $storeA->id,
                'order_ids' => [$orderTen->id, $orderEleven->id],
                'notes' => 'multi success diagnostic',
            ]);
            $freshOrderTen = $orderTen->fresh(['items']);
            $freshOrderEleven = $orderEleven->fresh(['items']);
            $addTest(
                'Positive case: many selected orders assignment',
                ($multiSuccess['body']['data']['assigned_count'] ?? 0) === 2
                    && ($multiSuccess['body']['data']['failed_count'] ?? 0) === 0
                    && $freshOrderTen->status === 'assigned_to_store'
                    && $freshOrderEleven->status === 'assigned_to_store'
                    && (int) $freshOrderTen->store_id === (int) $storeA->id
                    && (int) $freshOrderEleven->store_id === (int) $storeA->id
                    && $freshOrderTen->items->every(fn ($item) => (int) $item->store_id === (int) $storeA->id)
                    && $freshOrderEleven->items->every(fn ($item) => (int) $item->store_id === (int) $storeA->id),
                'Multiple selected orders should all become assigned_to_store and share the selected store',
                [
                    'response' => $multiSuccess,
                    'order_ten_status' => $freshOrderTen->status,
                    'order_eleven_status' => $freshOrderEleven->status,
                    'order_ten_item_store_ids' => $freshOrderTen->items->pluck('store_id')->values(),
                    'order_eleven_item_store_ids' => $freshOrderEleven->items->pluck('store_id')->values(),
                ]
            );

            $overCapacity = $callBulkAssign([
                'store_id' => $storeA->id,
                'order_ids' => [$orderFive->id, $orderSix->id],
                'notes' => 'select all over capacity diagnostic',
            ]);
            $freshOrderFive = $orderFive->fresh(['items']);
            $freshOrderSix = $orderSix->fresh();
            $addTest(
                'Select-all capacity protection: combined selected orders cannot exceed free stock',
                $overCapacity['status'] === 200
                    && ($overCapacity['body']['partial_success'] ?? false) === true
                    && ($overCapacity['body']['data']['assigned_count'] ?? 0) === 1
                    && ($overCapacity['body']['data']['failed_count'] ?? 0) === 1
                    && $freshOrderFive->status === 'assigned_to_store'
                    && (int) $freshOrderFive->store_id === (int) $storeA->id
                    && $freshOrderSix->status === 'pending_assignment'
                    && $freshOrderSix->store_id === null,
                'When select-all includes more orders than the selected store can fulfill together, the extra order should fail and remain unassigned',
                [
                    'response' => $overCapacity,
                    'order_five_status' => $freshOrderFive->status,
                    'order_six_status' => $freshOrderSix->status,
                ]
            );

            $finalPageData = $callPageData(['per_page' => 100, 'sort_order' => 'asc']);
            $finalTestOrders = collect($finalPageData['body']['data']['orders'] ?? [])
                ->filter(fn ($order) => str_starts_with((string) ($order['order_number'] ?? ''), $runKey))
                ->values();
            $remainingOrderNumbers = $finalTestOrders->pluck('order_number')->values();
            $addTest(
                'Page data after assignment: successful orders disappear and failed pending orders remain',
                !$remainingOrderNumbers->contains($orderOne->order_number)
                    && !$remainingOrderNumbers->contains($orderTwo->order_number)
                    && !$remainingOrderNumbers->contains($orderFive->order_number)
                    && !$remainingOrderNumbers->contains($orderTen->order_number)
                    && !$remainingOrderNumbers->contains($orderEleven->order_number)
                    && $remainingOrderNumbers->contains($orderThree->order_number)
                    && $remainingOrderNumbers->contains($orderSix->order_number)
                    && $remainingOrderNumbers->contains($orderNine->order_number),
                'Successful assignments should leave the page; failed pending_assignment orders should remain visible for correction',
                [
                    'remaining_test_orders' => $remainingOrderNumbers,
                    'assigned_should_be_hidden' => [
                        $orderOne->order_number,
                        $orderTwo->order_number,
                        $orderFive->order_number,
                        $orderTen->order_number,
                        $orderEleven->order_number,
                    ],
                    'failed_should_remain' => [$orderThree->order_number, $orderSix->order_number, $orderNine->order_number],
                ]
            );

            $passed = collect($tests)->where('passed', true)->count();
            $failed = count($tests) - $passed;

            return response()->json([
                'success' => $failed === 0,
                'message' => $failed === 0
                    ? 'Bulk store assignment diagnostic passed.'
                    : 'Bulk store assignment diagnostic found failures.',
                'data' => [
                    'run_key' => $runKey,
                    'summary' => [
                        'total' => count($tests),
                        'passed' => $passed,
                        'failed' => $failed,
                        'cleanup_requested' => $cleanup,
                    ],
                    'context' => $context,
                    'tests' => $tests,
                ],
            ], $failed === 0 ? 200 : 500);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            Log::error('Bulk store assignment test failed', [
                'run_key' => $runKey,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Bulk store assignment diagnostic crashed.',
                'error' => $e->getMessage(),
                'data' => [
                    'run_key' => $runKey,
                    'tests' => $tests,
                    'context' => $context,
                ],
            ], 500);
        } finally {
            if ($cleanup) {
                $this->cleanup($created);
            }
        }
    }

    private function cleanup(array $created): void
    {
        try {
            OrderItem::whereIn('order_id', $created['order_ids'] ?? [])->delete();

            if (!empty($created['order_ids'])) {
                Order::withTrashed()->whereIn('id', $created['order_ids'])->forceDelete();
            }

            if (!empty($created['batch_ids'])) {
                ProductBatch::whereIn('id', $created['batch_ids'])->delete();
            }

            if (!empty($created['product_ids'])) {
                Product::withTrashed()->whereIn('id', $created['product_ids'])->forceDelete();
            }

            if (!empty($created['customer_ids'])) {
                Customer::whereIn('id', $created['customer_ids'])->delete();
            }

            if (!empty($created['store_ids'])) {
                Store::withTrashed()->whereIn('id', $created['store_ids'])->forceDelete();
            }
        } catch (\Throwable $e) {
            Log::warning('Bulk store assignment test cleanup failed', [
                'message' => $e->getMessage(),
                'created' => $created,
            ]);
        }
    }
}
