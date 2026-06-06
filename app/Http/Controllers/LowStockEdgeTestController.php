<?php

namespace App\Http\Controllers;

use App\Models\BatchDeletedBarcode;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductBatch;
use App\Models\ReservedProduct;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\InventoryReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LowStockEdgeTestController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function run(Request $request): JsonResponse
    {
        $runKey = 'LSE-' . now()->format('YmdHis') . '-' . substr((string) uniqid(), -5);
        $tests = [];
        $context = [
            'run_key' => $runKey,
            'store_id' => 1,
            'one_stock' => [],
            'multi_stock' => [],
        ];

        $store = null;
        $category = null;
        $vendor = null;
        $customer = null;

        $oneProduct = null;
        $oneBatch = null;
        $oneBarcodes = collect();
        $oneOriginalOrder = null;
        $oneOriginalItem = null;
        $oneResaleOrder = null;

        $multiProduct = null;
        $multiBatch = null;
        $multiBarcodes = collect();
        $multiOriginalOrder = null;
        $multiOriginalItem = null;
        $multiResaleOrder = null;

        $addStep = function (
            string $name,
            string $whatDoing,
            string $whatTested,
            string $shouldHappen,
            callable $callback
        ) use (&$tests) {
            try {
                $result = $callback();
                $passed = (bool) ($result['passed'] ?? false);
                $tests[] = [
                    'name' => $name,
                    'passed' => $passed,
                    'expected' => $shouldHappen,
                    'what_doing' => $whatDoing,
                    'what_tested' => $whatTested,
                    'should_happen' => $shouldHappen,
                    'actual' => $result['actual'] ?? null,
                    'route_like' => $result['route_like'] ?? null,
                    'dumb_explanation' => $result['dumb_explanation'] ?? ($passed ? 'This step behaved correctly.' : 'This step did not behave the way we expected.'),
                ];
            } catch (\Throwable $e) {
                $tests[] = [
                    'name' => $name,
                    'passed' => false,
                    'expected' => $shouldHappen,
                    'what_doing' => $whatDoing,
                    'what_tested' => $whatTested,
                    'should_happen' => $shouldHappen,
                    'actual' => [
                        'error' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine(),
                    ],
                    'route_like' => null,
                    'dumb_explanation' => 'This step crashed, but the tester kept going so you can see the remaining workflow results.',
                ];

                Log::warning('Low-stock edge diagnostic step failed', [
                    'step' => $name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        };

        $addStep(
            'Setup: confirm Store ID 1 exists',
            'Checking the exact store used by the social-commerce manual assignment flow.',
            'The test needs store_id=1 because the reported bug happens when the social-commerce order is assigned to a store.',
            'Store #1 should exist and be active.',
            function () use (&$store) {
                $store = Store::find(1);

                return [
                    'passed' => (bool) ($store && $store->is_active),
                    'route_like' => 'DB check for store_id=1 before calling real order/cart routes',
                    'actual' => $store ? [
                        'id' => $store->id,
                        'name' => $store->name,
                        'is_active' => (bool) $store->is_active,
                    ] : ['message' => 'Store ID 1 was not found.'],
                    'dumb_explanation' => $store
                        ? 'Store #1 exists, so the test can use the same selected-store behavior as social-commerce.'
                        : 'Store #1 is missing, so store-specific checks cannot be trusted.',
                ];
            }
        );

        $addStep(
            'Setup: create test category, vendor, and customer',
            'Creating reusable support records for the temporary products and orders.',
            'The workflow should use real product/order/customer records, not fake in-memory objects.',
            'Support records should be ready for the real route-like calls.',
            function () use ($runKey, &$category, &$vendor, &$customer) {
                $category = Category::firstOrCreate(
                    ['slug' => 'low-stock-edge-test'],
                    [
                        'title' => 'Low Stock Edge Test',
                        'description' => 'Temporary category used by public/full-testing.html low-stock diagnostics.',
                        'is_active' => true,
                    ]
                );

                $vendor = Vendor::firstOrCreate(
                    ['email' => 'low-stock-edge-test@deshio.local'],
                    [
                        'name' => 'Low Stock Edge Test Vendor',
                        'phone' => '01000000002',
                        'address' => 'Diagnostic Address',
                        'type' => 'supplier',
                        'is_active' => true,
                    ]
                );

                $customer = Customer::firstOrCreate(
                    ['phone' => '016' . random_int(10000000, 99999999)],
                    [
                        'name' => $runKey . ' Low Stock Customer',
                        'email' => strtolower($runKey) . '@deshio-test.local',
                        'address' => 'Low-stock diagnostic customer address',
                        'customer_type' => 'social_commerce',
                        'status' => 'active',
                        'created_by' => auth()->id(),
                    ]
                );

                return [
                    'passed' => $category && $vendor && $customer,
                    'route_like' => 'Real models used by POST /api/products and POST /api/orders',
                    'actual' => [
                        'category_id' => $category?->id,
                        'vendor_id' => $vendor?->id,
                        'customer_id' => $customer?->id,
                    ],
                    'dumb_explanation' => 'The test now has real support data for products and social-commerce orders.',
                ];
            }
        );

        $addStep(
            'One-stock case: create product with exactly 1 barcode',
            'Creating a temporary product and one physical unit in store_id=1.',
            'This reproduces the sharp edge where stock goes from 1 to 0, then should come back after return.',
            'Product List, reserved_products, and cart availability should initially agree that 1 unit is available.',
            function () use ($runKey, &$category, &$vendor, &$oneProduct, &$oneBatch, &$oneBarcodes, &$context) {
                if (!$category || !$vendor) {
                    return $this->skippedActual('Missing category or vendor.');
                }

                [$oneProduct, $oneBatch, $oneBarcodes, $raw] = $this->createProductWithBatch(
                    $runKey,
                    $category,
                    $vendor,
                    'ONE-STOCK',
                    1
                );

                $context['one_stock']['product_id'] = $oneProduct?->id;
                $context['one_stock']['batch_id'] = $oneBatch?->id;
                $context['one_stock']['barcodes'] = $oneBarcodes->pluck('barcode')->values()->all();

                $availability = $oneProduct ? $this->cartAvailabilitySnapshot($oneProduct->id, 1) : null;
                $productList = $oneProduct ? $this->productListSnapshot($oneProduct) : null;

                return [
                    'passed' => $oneProduct && $oneBatch && $oneBarcodes->count() === 1 && ($availability['can_fulfill'] ?? false),
                    'route_like' => 'POST /api/products, POST /api/batches, GET /api/products, POST /api/order-management/cart-store-availability',
                    'actual' => [
                        'create_raw' => $raw,
                        'product_list_snapshot' => $productList,
                        'cart_availability' => $availability,
                    ],
                    'dumb_explanation' => ($availability['can_fulfill'] ?? false)
                        ? 'The product starts with exactly one sellable unit, and social-commerce can add it.'
                        : 'The product was created, but social-commerce does not see the initial one unit as addable.',
                ];
            }
        );

        $addStep(
            'One-stock case: sell the only unit through social-commerce',
            'Creating a manual store-assigned social-commerce order, packing it with the only barcode, and completing it.',
            'This follows the real order create, package barcode scan, and order complete path.',
            'The only barcode should become with_customer, and the product should no longer be addable before return.',
            function () use ($runKey, &$oneProduct, &$customer, &$oneBarcodes, &$oneOriginalOrder, &$oneOriginalItem) {
                if (!$oneProduct || !$customer || $oneBarcodes->isEmpty()) {
                    return $this->skippedActual('Missing one-stock product/customer/barcode.');
                }

                $oneOriginalOrder = $this->createAssignedSocialOrder($runKey, $oneProduct, $customer, 'ONE-STOCK-ORIGINAL', 1);
                $pack = $oneOriginalOrder
                    ? $this->packAndComplete($oneOriginalOrder, [$oneBarcodes[0]->barcode])
                    : ['fulfill' => ['status' => 0, 'body' => ['message' => 'Order was not created.']], 'complete' => ['status' => 0, 'body' => []]];

                $oneOriginalItem = $oneOriginalOrder
                    ? OrderItem::where('order_id', $oneOriginalOrder->id)->where('product_barcode_id', $oneBarcodes[0]->id)->first()
                    : null;
                $oneBarcodes[0]->refresh();
                $oneOriginalOrder?->refresh();

                $afterSaleAvailability = $this->cartAvailabilitySnapshot($oneProduct->id, 1);

                return [
                    'passed' => $oneOriginalOrder
                        && $this->responseSucceeded($pack['fulfill'])
                        && $this->responseSucceeded($pack['complete'])
                        && $oneBarcodes[0]->current_status === 'with_customer'
                        && !($afterSaleAvailability['can_fulfill'] ?? true),
                    'route_like' => 'POST /api/orders then PATCH /api/orders/{id}/fulfill then PATCH /api/orders/{id}/complete then POST /api/order-management/cart-store-availability',
                    'actual' => [
                        'order' => $this->orderSnapshot($oneOriginalOrder?->fresh(['items'])),
                        'pack_and_complete' => $pack,
                        'sold_barcode' => $this->barcodeSnapshot($oneBarcodes[0]->fresh()),
                        'availability_after_sale' => $afterSaleAvailability,
                        'reserved_product' => $this->reservationSnapshot($oneProduct->id),
                    ],
                    'dumb_explanation' => !($afterSaleAvailability['can_fulfill'] ?? true)
                        ? 'Good. While the only unit is with the customer, social-commerce correctly blocks adding it again.'
                        : 'Bad. The only sold unit still looked addable before return.',
                ];
            }
        );

        $addStep(
            'One-stock negative test: sold barcode cannot be packed again',
            'Trying to pack the already-sold barcode into another social-commerce order before it is returned.',
            'A barcode already with a customer must never be reusable until return/exchange restores it.',
            'The package scan should fail.',
            function () use ($runKey, &$oneProduct, &$customer, &$oneBarcodes) {
                if (!$oneProduct || !$customer || $oneBarcodes->isEmpty()) {
                    return $this->skippedActual('Missing one-stock product/customer/barcode.');
                }

                $negativeOrder = $this->createAssignedSocialOrder($runKey, $oneProduct, $customer, 'NEGATIVE-SOLD-BARCODE-REUSE', 1, false);
                $item = $negativeOrder?->items()->first();
                $response = $item
                    ? app(OrderController::class)->fulfill(new Request([
                        'fulfillments' => [[
                            'order_item_id' => $item->id,
                            'barcodes' => [$oneBarcodes[0]->barcode],
                        ]],
                    ]), $negativeOrder->id)
                    : null;
                $info = $response ? $this->responseInfo($response) : ['status' => 0, 'body' => ['message' => 'Negative order was not created, likely because stock is correctly unavailable.']];

                if ($negativeOrder) {
                    app(OrderController::class)->cancel(new Request(['reason' => 'Low-stock negative test cleanup']), $negativeOrder->id);
                    app(InventoryReservationService::class)->syncProduct((int) $oneProduct->id);
                    $negativeOrder->refresh();
                }

                return [
                    'passed' => !$this->responseSucceeded($info),
                    'route_like' => 'POST /api/orders then PATCH /api/orders/{id}/fulfill',
                    'actual' => [
                        'negative_order' => $this->orderSnapshot($negativeOrder?->fresh(['items'])),
                        'attempted_barcode' => $oneBarcodes[0]->barcode,
                        'fulfill_response' => $info,
                        'reserved_product' => $this->reservationSnapshot($oneProduct->id),
                    ],
                    'dumb_explanation' => !$this->responseSucceeded($info)
                        ? 'Good. The sold barcode could not be used again before return.'
                        : 'Bad. A sold barcode was packed again before return.',
                ];
            }
        );

        $addStep(
            'One-stock case: return the unit from Lookup return modal path',
            'Returning the sold barcode using the quick-complete return route used by the Lookup return modal.',
            'The barcode and batch should become sellable again.',
            'Product List and social-commerce cart availability should both show the unit as available after return.',
            function () use (&$oneProduct, &$oneOriginalOrder, &$oneOriginalItem, &$oneBarcodes) {
                if (!$oneProduct || !$oneOriginalOrder || !$oneOriginalItem || $oneBarcodes->isEmpty()) {
                    return $this->skippedActual('Missing one-stock sold order/item/barcode.');
                }

                $barcode = $oneBarcodes[0]->fresh();
                $payload = [
                    'order_id' => $oneOriginalOrder->id,
                    'received_at_store_id' => 1,
                    'return_reason' => 'changed_mind',
                    'return_type' => 'customer_return',
                    'customer_notes' => 'Low-stock edge diagnostic return.',
                    'items' => [[
                        'order_item_id' => $oneOriginalItem->id,
                        'quantity' => 1,
                        'product_barcode_id' => $barcode->id,
                        'unit_price' => 150,
                        'manual_sold_at_price' => 150,
                        'total_price' => 150,
                        'reason' => 'Low-stock edge diagnostic return.',
                    ]],
                ];

                $response = app(ProductReturnController::class)->quickComplete(new Request($payload));
                $info = $this->responseInfo($response);
                $barcode->refresh();
                $oneBatch = $barcode->batch_id ? ProductBatch::find($barcode->batch_id) : ProductBatch::where('product_id', $oneProduct->id)->where('store_id', 1)->first();
                app(InventoryReservationService::class)->syncProduct((int) $oneProduct->id);

                $productList = $this->productListSnapshot($oneProduct->fresh());
                $availability = $this->cartAvailabilitySnapshot($oneProduct->id, 1);

                return [
                    'passed' => $this->responseSucceeded($info)
                        && in_array($barcode->current_status, ['available', 'in_shop', 'in_warehouse', 'on_display'], true)
                        && (($productList['live_stock_quantity'] ?? 0) >= 1)
                        && (($availability['can_fulfill'] ?? false) === true),
                    'route_like' => 'POST /api/product-returns/quick-complete then GET /api/products then POST /api/order-management/cart-store-availability',
                    'actual' => [
                        'request' => $payload,
                        'return_response' => $info,
                        'returned_barcode' => $this->barcodeSnapshot($barcode),
                        'batch_after_return' => $oneBatch ? ['id' => $oneBatch->id, 'quantity' => (int) $oneBatch->quantity] : null,
                        'product_list_snapshot' => $productList,
                        'cart_availability_after_return' => $availability,
                        'reserved_product' => $this->reservationSnapshot($oneProduct->id),
                    ],
                    'dumb_explanation' => ($availability['can_fulfill'] ?? false)
                        ? 'Good. After return, social-commerce can add the product again just like Product List shows.'
                        : 'Bug reproduced. Product List may show stock, but social-commerce still says not available.',
                ];
            }
        );

        $addStep(
            'One-stock stale-table simulation: social-commerce availability self-heals',
            'Intentionally corrupting reserved_products for this product to simulate the old stale-table bug, then calling the real cart availability route.',
            'The cart availability route should rebuild reserved_products before answering, so stale zero should not block a real returned unit.',
            'Even after a stale reserved_products row is forced to zero available, the route should show the returned unit as addable and repair the row.',
            function () use (&$oneProduct) {
                if (!$oneProduct) {
                    return $this->skippedActual('Missing one-stock product.');
                }

                $row = ReservedProduct::firstOrCreate(['product_id' => $oneProduct->id]);
                $row->update([
                    'total_inventory' => 1,
                    'reserved_inventory' => 1,
                    'available_inventory' => 0,
                ]);

                $before = ReservedProduct::where('product_id', $oneProduct->id)->first()?->toArray();
                $availability = $this->cartAvailabilitySnapshot($oneProduct->id, 1);
                $after = ReservedProduct::where('product_id', $oneProduct->id)->first()?->toArray();

                return [
                    'passed' => ($availability['can_fulfill'] ?? false)
                        && (int) ($after['available_inventory'] ?? 0) >= 1,
                    'route_like' => 'POST /api/order-management/cart-store-availability',
                    'actual' => [
                        'forced_stale_reserved_product_before_route' => $before,
                        'cart_availability' => $availability,
                        'reserved_product_after_route' => $after,
                    ],
                    'dumb_explanation' => ($availability['can_fulfill'] ?? false)
                        ? 'Good. The exact old stale-reservation symptom is healed by the cart availability route itself.'
                        : 'Bad. A stale reserved_products row still made social-commerce think returned stock was unavailable.',
                ];
            }
        );

        $addStep(
            'One-stock case: resell the returned barcode through social-commerce',
            'Creating another manual assigned social-commerce order and packing it with the returned barcode.',
            'A properly returned barcode should be reusable in the package workflow.',
            'The order should be created, packed, and completed with the same returned barcode.',
            function () use ($runKey, &$oneProduct, &$customer, &$oneBarcodes, &$oneResaleOrder, &$context) {
                if (!$oneProduct || !$customer || $oneBarcodes->isEmpty()) {
                    return $this->skippedActual('Missing one-stock product/customer/barcode.');
                }

                $oneResaleOrder = $this->createAssignedSocialOrder($runKey, $oneProduct, $customer, 'ONE-STOCK-RESALE-AFTER-RETURN', 1);
                $context['one_stock']['resale_order'] = $oneResaleOrder?->order_number;
                $pack = $oneResaleOrder
                    ? $this->packAndComplete($oneResaleOrder, [$oneBarcodes[0]->barcode])
                    : ['fulfill' => ['status' => 0, 'body' => ['message' => 'Resale order was not created.']], 'complete' => ['status' => 0, 'body' => []]];

                $oneBarcodes[0]->refresh();
                $oneResaleOrder?->refresh();

                return [
                    'passed' => $oneResaleOrder
                        && $this->responseSucceeded($pack['fulfill'])
                        && $this->responseSucceeded($pack['complete'])
                        && $oneResaleOrder->status === 'confirmed'
                        && $oneBarcodes[0]->current_status === 'with_customer',
                    'route_like' => 'POST /api/orders then PATCH /api/orders/{id}/fulfill then PATCH /api/orders/{id}/complete',
                    'actual' => [
                        'resale_order' => $this->orderSnapshot($oneResaleOrder?->fresh(['items'])),
                        'pack_and_complete' => $pack,
                        'resold_barcode' => $this->barcodeSnapshot($oneBarcodes[0]->fresh()),
                        'reserved_product' => $this->reservationSnapshot($oneProduct->id),
                    ],
                    'dumb_explanation' => $oneResaleOrder && $oneResaleOrder->status === 'confirmed'
                        ? 'Good. The returned one-unit stock was actually reusable in social-commerce.'
                        : 'Bad. The returned one-unit stock still could not be sold again through social-commerce.',
                ];
            }
        );

        $addStep(
            'Multi-stock exchange case: create product with 3 barcodes',
            'Creating another temporary product with three units so the same bug can be tested with more than one stock.',
            'The workflow should also work when stock does not start at exactly one.',
            'Three barcodes should be created and social-commerce should initially be able to add two units.',
            function () use ($runKey, &$category, &$vendor, &$multiProduct, &$multiBatch, &$multiBarcodes, &$context) {
                if (!$category || !$vendor) {
                    return $this->skippedActual('Missing category or vendor.');
                }

                [$multiProduct, $multiBatch, $multiBarcodes, $raw] = $this->createProductWithBatch(
                    $runKey,
                    $category,
                    $vendor,
                    'MULTI-STOCK',
                    3
                );

                $context['multi_stock']['product_id'] = $multiProduct?->id;
                $context['multi_stock']['batch_id'] = $multiBatch?->id;
                $context['multi_stock']['barcodes'] = $multiBarcodes->pluck('barcode')->values()->all();

                $availability = $multiProduct ? $this->cartAvailabilitySnapshot($multiProduct->id, 2) : null;

                return [
                    'passed' => $multiProduct && $multiBatch && $multiBarcodes->count() === 3 && ($availability['can_fulfill'] ?? false),
                    'route_like' => 'POST /api/products, POST /api/batches, POST /api/order-management/cart-store-availability',
                    'actual' => [
                        'create_raw' => $raw,
                        'cart_availability_for_qty_2' => $availability,
                        'reserved_product' => $multiProduct ? $this->reservationSnapshot($multiProduct->id) : null,
                    ],
                    'dumb_explanation' => ($availability['can_fulfill'] ?? false)
                        ? 'The multi-stock product starts as addable for quantity 2.'
                        : 'The multi-stock product was not addable even before the exchange workflow.',
                ];
            }
        );

        $addStep(
            'Multi-stock exchange case: sell one original unit',
            'Selling one unit through assigned social-commerce before exchange.',
            'The original unit should be with the customer, leaving other units in stock.',
            'Original barcode #1 should be with_customer after packing and completion.',
            function () use ($runKey, &$multiProduct, &$customer, &$multiBarcodes, &$multiOriginalOrder, &$multiOriginalItem) {
                if (!$multiProduct || !$customer || $multiBarcodes->count() < 3) {
                    return $this->skippedActual('Missing multi-stock product/customer/barcodes.');
                }

                $multiOriginalOrder = $this->createAssignedSocialOrder($runKey, $multiProduct, $customer, 'MULTI-STOCK-ORIGINAL', 1);
                $pack = $multiOriginalOrder
                    ? $this->packAndComplete($multiOriginalOrder, [$multiBarcodes[0]->barcode])
                    : ['fulfill' => ['status' => 0, 'body' => ['message' => 'Order was not created.']], 'complete' => ['status' => 0, 'body' => []]];

                $multiOriginalItem = $multiOriginalOrder
                    ? OrderItem::where('order_id', $multiOriginalOrder->id)->where('product_barcode_id', $multiBarcodes[0]->id)->first()
                    : null;
                $multiBarcodes[0]->refresh();
                $multiOriginalOrder?->refresh();

                return [
                    'passed' => $multiOriginalOrder
                        && $this->responseSucceeded($pack['fulfill'])
                        && $this->responseSucceeded($pack['complete'])
                        && $multiBarcodes[0]->current_status === 'with_customer',
                    'route_like' => 'POST /api/orders then PATCH /api/orders/{id}/fulfill then PATCH /api/orders/{id}/complete',
                    'actual' => [
                        'order' => $this->orderSnapshot($multiOriginalOrder?->fresh(['items'])),
                        'pack_and_complete' => $pack,
                        'sold_barcode' => $this->barcodeSnapshot($multiBarcodes[0]->fresh()),
                        'reserved_product' => $this->reservationSnapshot($multiProduct->id),
                    ],
                    'dumb_explanation' => $multiBarcodes[0]->current_status === 'with_customer'
                        ? 'The first unit is sold and ready to be exchanged.'
                        : 'The first unit did not reach the expected sold/customer state.',
                ];
            }
        );

        $addStep(
            'Multi-stock exchange case: exchange original unit with replacement barcode',
            'Running Lookup exchange process: returned barcode #1 comes back, replacement barcode #2 goes to customer.',
            'The exchange route should restore the removed unit and sell only the replacement unit.',
            'Returned barcode #1 should be addable again; replacement barcode #2 should not be addable.',
            function () use (&$multiProduct, &$multiOriginalOrder, &$multiOriginalItem, &$multiBarcodes) {
                if (!$multiProduct || !$multiOriginalOrder || !$multiOriginalItem || $multiBarcodes->count() < 3) {
                    return $this->skippedActual('Missing multi-stock original order/item/barcodes.');
                }

                $removedBarcode = $multiBarcodes[0]->fresh();
                $replacementBarcode = $multiBarcodes[1]->fresh();
                $payload = [
                    'order_id' => $multiOriginalOrder->id,
                    'customer_id' => $multiOriginalOrder->customer_id,
                    'exchangeAtStoreId' => 1,
                    'removedProducts' => [[
                        'product_id' => $multiProduct->id,
                        'product_batch_id' => $multiOriginalItem->product_batch_id,
                        'quantity' => 1,
                        'unit_price' => 150,
                        'total_price' => 150,
                        'order_item_id' => $multiOriginalItem->id,
                        'product_barcode_id' => $removedBarcode->id,
                        'return_reason' => 'changed_mind',
                        'quality_check_passed' => true,
                    ]],
                    'replacementProducts' => [[
                        'product_id' => $multiProduct->id,
                        'batch_id' => $replacementBarcode->batch_id,
                        'quantity' => 1,
                        'unit_price' => 150,
                        'total_price' => 150,
                        'discount_amount' => 0,
                        'barcode' => $replacementBarcode->barcode,
                    ]],
                    'paymentRefund' => [
                        'type' => 'even',
                        'amount' => 0,
                        'method' => 'cash',
                    ],
                    'notes' => 'Low-stock edge diagnostic exchange.',
                ];

                $response = app(ExchangeController::class)->process(new Request($payload));
                $info = $this->responseInfo($response);
                $removedBarcode->refresh();
                $replacementBarcode->refresh();
                app(InventoryReservationService::class)->syncProduct((int) $multiProduct->id);

                $availabilityQty2 = $this->cartAvailabilitySnapshot($multiProduct->id, 2);

                return [
                    'passed' => $this->responseSucceeded($info)
                        && in_array($removedBarcode->current_status, ['available', 'in_shop', 'in_warehouse', 'on_display'], true)
                        && $replacementBarcode->current_status === 'with_customer'
                        && (($availabilityQty2['can_fulfill'] ?? false) === true),
                    'route_like' => 'POST /api/exchange/process then POST /api/order-management/cart-store-availability',
                    'actual' => [
                        'request' => $payload,
                        'exchange_response' => $info,
                        'returned_original_barcode' => $this->barcodeSnapshot($removedBarcode),
                        'replacement_sold_barcode' => $this->barcodeSnapshot($replacementBarcode),
                        'cart_availability_for_qty_2_after_exchange' => $availabilityQty2,
                        'reserved_product' => $this->reservationSnapshot($multiProduct->id),
                    ],
                    'dumb_explanation' => ($availabilityQty2['can_fulfill'] ?? false)
                        ? 'Good. After exchange, the restored/remaining stock is still addable in social-commerce.'
                        : 'Bug reproduced. After exchange, Product List may have stock but social-commerce thinks this store cannot fulfill.',
                ];
            }
        );

        $addStep(
            'Multi-stock negative test: replacement barcode cannot be packed again',
            'Trying to use the replacement barcode that just went to the customer in another social-commerce package scan.',
            'The exchanged replacement unit is sold, so it must not be reusable.',
            'Package scan should reject replacement barcode #2.',
            function () use ($runKey, &$multiProduct, &$customer, &$multiBarcodes) {
                if (!$multiProduct || !$customer || $multiBarcodes->count() < 2) {
                    return $this->skippedActual('Missing multi-stock product/customer/replacement barcode.');
                }

                $negativeOrder = $this->createAssignedSocialOrder($runKey, $multiProduct, $customer, 'NEGATIVE-EXCHANGE-REPLACEMENT-REUSE', 1, false);
                $item = $negativeOrder?->items()->first();
                $response = $item
                    ? app(OrderController::class)->fulfill(new Request([
                        'fulfillments' => [[
                            'order_item_id' => $item->id,
                            'barcodes' => [$multiBarcodes[1]->barcode],
                        ]],
                    ]), $negativeOrder->id)
                    : null;
                $info = $response ? $this->responseInfo($response) : ['status' => 0, 'body' => ['message' => 'Negative order was not created.']];

                if ($negativeOrder) {
                    app(OrderController::class)->cancel(new Request(['reason' => 'Low-stock exchange negative cleanup']), $negativeOrder->id);
                    app(InventoryReservationService::class)->syncProduct((int) $multiProduct->id);
                    $negativeOrder->refresh();
                }

                return [
                    'passed' => !$this->responseSucceeded($info),
                    'route_like' => 'POST /api/orders then PATCH /api/orders/{id}/fulfill',
                    'actual' => [
                        'negative_order' => $this->orderSnapshot($negativeOrder?->fresh(['items'])),
                        'attempted_replacement_barcode' => $multiBarcodes[1]->barcode,
                        'fulfill_response' => $info,
                    ],
                    'dumb_explanation' => !$this->responseSucceeded($info)
                        ? 'Good. The replacement unit that went to the customer was blocked from reuse.'
                        : 'Bad. The replacement barcode was packed again even though it was sold by exchange.',
                ];
            }
        );

        $addStep(
            'Multi-stock exchange case: resell restored + remaining stock through social-commerce',
            'Creating a new social-commerce order for quantity 2 and packing it with returned barcode #1 plus untouched barcode #3.',
            'After exchange, the restored item and the remaining untouched item should both be sellable.',
            'The quantity-2 social-commerce order should be created, packed, and completed.',
            function () use ($runKey, &$multiProduct, &$customer, &$multiBarcodes, &$multiResaleOrder, &$context) {
                if (!$multiProduct || !$customer || $multiBarcodes->count() < 3) {
                    return $this->skippedActual('Missing multi-stock product/customer/barcodes.');
                }

                $multiResaleOrder = $this->createAssignedSocialOrder($runKey, $multiProduct, $customer, 'MULTI-STOCK-RESALE-AFTER-EXCHANGE', 2);
                $context['multi_stock']['resale_order'] = $multiResaleOrder?->order_number;
                $pack = $multiResaleOrder
                    ? $this->packAndComplete($multiResaleOrder, [$multiBarcodes[0]->barcode, $multiBarcodes[2]->barcode])
                    : ['fulfill' => ['status' => 0, 'body' => ['message' => 'Resale order was not created.']], 'complete' => ['status' => 0, 'body' => []]];

                $multiBarcodes[0]->refresh();
                $multiBarcodes[2]->refresh();
                $multiResaleOrder?->refresh();

                return [
                    'passed' => $multiResaleOrder
                        && $this->responseSucceeded($pack['fulfill'])
                        && $this->responseSucceeded($pack['complete'])
                        && $multiResaleOrder->status === 'confirmed'
                        && $multiBarcodes[0]->current_status === 'with_customer'
                        && $multiBarcodes[2]->current_status === 'with_customer',
                    'route_like' => 'POST /api/orders then PATCH /api/orders/{id}/fulfill then PATCH /api/orders/{id}/complete',
                    'actual' => [
                        'resale_order' => $this->orderSnapshot($multiResaleOrder?->fresh(['items'])),
                        'pack_and_complete' => $pack,
                        'barcodes_used' => [
                            $this->barcodeSnapshot($multiBarcodes[0]->fresh()),
                            $this->barcodeSnapshot($multiBarcodes[2]->fresh()),
                        ],
                        'reserved_product' => $this->reservationSnapshot($multiProduct->id),
                    ],
                    'dumb_explanation' => $multiResaleOrder && $multiResaleOrder->status === 'confirmed'
                        ? 'Good. Multi-stock exchange did not poison the product for future social-commerce sale.'
                        : 'Bad. Multi-stock returned/remaining stock could not be sold again through social-commerce.',
                ];
            }
        );

        $addStep(
            'Cleanup: delete temporary batches and products',
            'Removing the temporary test batches/products after all diagnostic steps finish.',
            'Cleanup should not hide earlier failures; it simply prevents test clutter.',
            'Batches should be deleted with the same Product > Batch delete safety process, then products should be soft-deleted.',
            function () use (&$oneProduct, &$multiProduct) {
                $cleanup = [];
                foreach ([$oneProduct, $multiProduct] as $product) {
                    if (!$product) {
                        continue;
                    }

                    $batches = ProductBatch::where('product_id', $product->id)->get();
                    foreach ($batches as $batch) {
                        $response = app(ProductBatchController::class)->destroy($batch->id);
                        $cleanup[] = [
                            'type' => 'batch',
                            'id' => $batch->id,
                            'response' => $this->responseInfo($response),
                            'deleted_barcode_logs' => BatchDeletedBarcode::where('deleted_product_batch_id', $batch->id)->count(),
                        ];
                    }

                    app(InventoryReservationService::class)->syncProduct((int) $product->id);
                    $deleteResponse = app(ProductController::class)->destroy($product->id);
                    $cleanup[] = [
                        'type' => 'product',
                        'id' => $product->id,
                        'response' => $this->responseInfo($deleteResponse),
                        'is_soft_deleted' => (bool) Product::withTrashed()->find($product->id)?->trashed(),
                    ];
                }

                $failedCleanup = collect($cleanup)->filter(fn ($row) => !$this->responseSucceeded($row['response'] ?? []))->values();

                return [
                    'passed' => $failedCleanup->isEmpty(),
                    'route_like' => 'DELETE /api/batches/{id}, DELETE /api/products/{id}',
                    'actual' => [
                        'cleanup_rows' => $cleanup,
                        'failed_cleanup_rows' => $failedCleanup,
                    ],
                    'dumb_explanation' => $failedCleanup->isEmpty()
                        ? 'Temporary batches and products were cleaned up.'
                        : 'Some cleanup failed. The real test results above are still valid, but manual cleanup may be needed.',
                ];
            }
        );

        $passed = collect($tests)->where('passed', true)->count();
        $failed = count($tests) - $passed;

        return response()->json([
            'success' => $failed === 0,
            'message' => $failed === 0
                ? 'Low-stock edge diagnostic passed.'
                : 'Low-stock edge diagnostic completed with failures. It did not stop early; inspect failed rows.',
            'data' => [
                'run_key' => $runKey,
                'summary' => [
                    'total' => count($tests),
                    'passed' => $passed,
                    'failed' => $failed,
                ],
                'context' => $context,
                'tests' => $tests,
            ],
        ], $failed === 0 ? 200 : 207);
    }

    private function createProductWithBatch(string $runKey, Category $category, Vendor $vendor, string $suffix, int $quantity): array
    {
        $productPayload = [
            'category_id' => $category->id,
            'vendor_id' => $vendor->id,
            'sku' => $runKey . '-' . $suffix,
            'base_name' => $runKey . ' ' . $suffix . ' Product',
            'variation_suffix' => '- Edge',
            'description' => 'Created by full-testing.html low-stock edge card.',
        ];

        $productResponse = app(ProductController::class)->create(new Request($productPayload));
        $productInfo = $this->responseInfo($productResponse);
        $productId = $productInfo['body']['data']['id'] ?? null;
        $product = $productId ? Product::find($productId) : null;

        $batch = null;
        $barcodes = collect();
        $batchInfo = ['status' => 0, 'body' => ['message' => 'Product was not created.']];

        if ($product) {
            $batchPayload = [
                'product_id' => $product->id,
                'store_id' => 1,
                'quantity' => $quantity,
                'cost_price' => 100,
                'sell_price' => 150,
                'tax_percentage' => 0,
                'barcode_type' => 'CODE128',
                'notes' => 'Created by low-stock edge full test.',
            ];

            $batchResponse = app(ProductBatchController::class)->create(new Request($batchPayload));
            $batchInfo = $this->responseInfo($batchResponse);
            $batchId = $batchInfo['body']['data']['batch']['id'] ?? null;
            $batch = $batchId ? ProductBatch::find($batchId) : null;
            $barcodes = $batch ? ProductBarcode::where('batch_id', $batch->id)->orderBy('id')->get() : collect();
            app(InventoryReservationService::class)->syncProduct((int) $product->id);
        }

        return [$product, $batch, $barcodes, [
            'product_payload' => $productPayload,
            'product_response' => $productInfo,
            'batch_response' => $batchInfo,
        ]];
    }

    private function createAssignedSocialOrder(string $runKey, Product $product, Customer $customer, string $suffix, int $quantity, bool $throwOnFail = true): ?Order
    {
        $payload = [
            'order_type' => 'social_commerce',
            'store_id' => 1,
            'store_assignment_mode' => 'manual',
            'customer_id' => $customer->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 150,
                'discount_amount' => 0,
            ]],
            'shipping_amount' => 0,
            'notes' => $runKey . ' ' . $suffix,
            'shipping_address' => [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'address_line_1' => 'Low-stock diagnostic address',
                'city' => 'Dhaka',
                'state' => '',
                'postal_code' => '',
                'country' => 'Bangladesh',
            ],
        ];

        $response = app(OrderController::class)->create(new Request($payload));
        $info = $this->responseInfo($response);
        $id = $info['body']['data']['id'] ?? null;

        if (!$id && $throwOnFail) {
            throw new \Exception('Social-commerce order creation failed: ' . ($info['body']['message'] ?? 'unknown error'));
        }

        return $id ? Order::with('items')->find($id) : null;
    }

    private function packAndComplete(Order $order, array $barcodeValues): array
    {
        $order = $order->fresh(['items']);
        $item = $order?->items?->first();

        if (!$item) {
            return [
                'fulfill' => ['status' => 0, 'body' => ['message' => 'Order has no items.']],
                'complete' => ['status' => 0, 'body' => ['message' => 'Order has no items.']],
            ];
        }

        $fulfillResponse = app(OrderController::class)->fulfill(new Request([
            'fulfillments' => [[
                'order_item_id' => $item->id,
                'barcodes' => $barcodeValues,
            ]],
        ]), $order->id);
        $fulfill = $this->responseInfo($fulfillResponse);

        $complete = ['status' => 0, 'body' => ['message' => 'Skipped because fulfillment failed.']];
        if ($this->responseSucceeded($fulfill)) {
            $completeResponse = app(OrderController::class)->complete($order->id);
            $complete = $this->responseInfo($completeResponse);
        }

        return [
            'fulfill' => $fulfill,
            'complete' => $complete,
        ];
    }

    private function cartAvailabilitySnapshot(int $productId, int $quantity): array
    {
        $response = app(OrderManagementController::class)->getCartStoreAvailability(new Request([
            'store_id' => 1,
            'items' => [[
                'product_id' => $productId,
                'quantity' => $quantity,
            ]],
        ]));

        $info = $this->responseInfo($response);
        $storeRow = collect($info['body']['data']['stores'] ?? [])->firstWhere('store_id', 1);
        $detail = collect($storeRow['inventory_details'] ?? [])->firstWhere('product_id', $productId);

        return [
            'response_status' => $info['status'],
            'success' => (bool) ($info['body']['success'] ?? false),
            'can_fulfill' => (bool) ($storeRow['can_fulfill_entire_order'] ?? false),
            'store_row' => $storeRow,
            'inventory_detail' => $detail,
            'message' => $info['body']['message'] ?? null,
        ];
    }

    private function productListSnapshot(Product $product): array
    {
        $response = app(ProductController::class)->index(new Request([
            'search' => $product->name,
            'per_page' => 60,
            'group_by_sku' => true,
        ]));
        $info = $this->responseInfo($response);
        $rows = collect($info['body']['data']['data'] ?? []);
        $matched = $rows->first(function ($row) use ($product) {
            if ((int) ($row['id'] ?? 0) === (int) $product->id) {
                return true;
            }
            foreach (($row['variants'] ?? []) as $variant) {
                if ((int) ($variant['id'] ?? 0) === (int) $product->id) {
                    return true;
                }
            }
            return false;
        });

        $liveStock = (int) ProductBatch::where('product_id', $product->id)->where('is_active', true)->sum('quantity');
        $reserved = app(InventoryReservationService::class)->syncProduct((int) $product->id);

        return [
            'response_status' => $info['status'],
            'matched_in_product_list' => (bool) $matched,
            'matched_row' => $matched,
            'live_stock_quantity' => $liveStock,
            'reserved_products_row' => [
                'total_inventory' => (int) $reserved->total_inventory,
                'reserved_inventory' => (int) $reserved->reserved_inventory,
                'available_inventory' => (int) $reserved->available_inventory,
            ],
        ];
    }

    private function reservationSnapshot(?int $productId): ?array
    {
        if (!$productId) {
            return null;
        }

        $row = app(InventoryReservationService::class)->syncProduct((int) $productId);

        return [
            'product_id' => $productId,
            'total_inventory' => (int) $row->total_inventory,
            'reserved_inventory' => (int) $row->reserved_inventory,
            'available_inventory' => (int) $row->available_inventory,
        ];
    }

    private function responseInfo(?JsonResponse $response): array
    {
        if (!$response) {
            return ['status' => 0, 'body' => ['message' => 'No response object returned.']];
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => $response->getData(true),
        ];
    }

    private function responseSucceeded(array $info): bool
    {
        $status = (int) ($info['status'] ?? 0);
        $body = $info['body'] ?? [];

        return $status >= 200
            && $status < 300
            && (($body['success'] ?? true) === true);
    }

    private function skippedActual(string $reason): array
    {
        return [
            'passed' => false,
            'actual' => ['skipped' => true, 'reason' => $reason],
            'dumb_explanation' => 'This step was skipped because a previous required object was missing. The test continued instead of stopping.',
        ];
    }

    private function orderSnapshot(?Order $order): ?array
    {
        if (!$order) {
            return null;
        }

        $order->loadMissing(['items']);

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'fulfillment_status' => $order->fulfillment_status,
            'store_id' => $order->store_id,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'batch_id' => $item->product_batch_id,
                'barcode_id' => $item->product_barcode_id,
                'quantity' => (int) $item->quantity,
                'inventory_deducted' => (bool) $item->is_inventory_deducted,
            ])->values(),
        ];
    }

    private function barcodeSnapshot(?ProductBarcode $barcode): ?array
    {
        if (!$barcode) {
            return null;
        }

        return [
            'id' => $barcode->id,
            'barcode' => $barcode->barcode,
            'product_id' => $barcode->product_id,
            'batch_id' => $barcode->batch_id,
            'current_store_id' => $barcode->current_store_id,
            'current_status' => $barcode->current_status,
            'is_active' => (bool) $barcode->is_active,
            'is_defective' => (bool) $barcode->is_defective,
            'has_batch_deleted_record' => BatchDeletedBarcode::where('product_barcode_id', $barcode->id)->exists(),
        ];
    }
}
