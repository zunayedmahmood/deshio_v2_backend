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
use App\Models\Store;
use App\Models\Vendor;
use App\Services\InventoryReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SocialCommerceLifecycleTestController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    public function run(Request $request): JsonResponse
    {
        $runKey = 'SCL-' . now()->format('YmdHis') . '-' . substr((string) uniqid(), -5);
        $tests = [];
        $context = [
            'run_key' => $runKey,
            'store_id' => 1,
            'product_id' => null,
            'batch_id' => null,
            'barcodes' => [],
            'orders' => [],
        ];

        $store = null;
        $category = null;
        $vendor = null;
        $product = null;
        $batch = null;
        $barcodes = collect();
        $customer = null;
        $orderReturn = null;
        $orderExchange = null;
        $orderNegativeSoldReuse = null;
        $orderResell = null;
        $orderDeletedBatchNegative = null;
        $returnOrderItem = null;
        $exchangeOrderItem = null;

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
                    'dumb_explanation' => 'The tester continued to the next step, but this step crashed before it could finish.',
                ];

                Log::warning('Social commerce lifecycle diagnostic step failed', [
                    'step' => $name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        };

        $addStep(
            'Setup: confirm Store ID 1 exists',
            'Looking for store_id=1 because this test intentionally follows the real store-assigned social-commerce workflow for store 1.',
            'The selected store must exist and be active before we create assigned social-commerce orders.',
            'Store #1 should exist and be usable for assigned social-commerce orders.',
            function () use (&$store) {
                $store = Store::find(1);

                return [
                    'passed' => (bool) ($store && $store->is_active),
                    'route_like' => 'DB check for store_id=1, same target store used by the real routes below',
                    'actual' => $store ? [
                        'id' => $store->id,
                        'name' => $store->name,
                        'is_active' => (bool) $store->is_active,
                        'is_online' => (bool) $store->is_online,
                    ] : ['message' => 'Store ID 1 was not found.'],
                    'dumb_explanation' => $store
                        ? 'Good. The test has a real branch/store to use.'
                        : 'Store #1 is missing, so later store-specific steps may fail.',
                ];
            }
        );

        $addStep(
            'Create test product through product create logic',
            'Creating one temporary product that will be sold, returned, exchanged, resold, and then deleted.',
            'Product creation should use the same backend controller behind the product creation route.',
            'A new active product should be created with a unique SKU/name.',
            function () use ($runKey, &$category, &$vendor, &$product, &$context) {
                $category = Category::firstOrCreate(
                    ['slug' => 'social-commerce-lifecycle-test'],
                    [
                        'title' => 'Social Commerce Lifecycle Test',
                        'description' => 'Temporary category used by public/full-testing.html lifecycle diagnostics.',
                        'is_active' => true,
                    ]
                );

                $vendor = Vendor::firstOrCreate(
                    ['email' => 'social-commerce-lifecycle-test@deshio.local'],
                    [
                        'name' => 'Social Commerce Lifecycle Test Vendor',
                        'phone' => '01000000001',
                        'address' => 'Diagnostic Address',
                        'type' => 'supplier',
                        'is_active' => true,
                    ]
                );

                $payload = [
                    'category_id' => $category->id,
                    'vendor_id' => $vendor->id,
                    'sku' => $runKey . '-SKU',
                    'base_name' => $runKey . ' Test Product',
                    'variation_suffix' => '- Lifecycle',
                    'description' => 'Created by full-testing.html social-commerce lifecycle card.',
                ];

                $response = app(ProductController::class)->create(new Request($payload));
                $info = $this->responseInfo($response);
                $productId = $info['body']['data']['id'] ?? null;
                $product = $productId ? Product::find($productId) : null;
                $context['product_id'] = $product?->id;

                return [
                    'passed' => $this->responseSucceeded($info) && $product !== null,
                    'route_like' => 'POST /api/products',
                    'actual' => [
                        'request' => $payload,
                        'response' => $info,
                        'product' => $product ? ['id' => $product->id, 'name' => $product->name, 'sku' => $product->sku] : null,
                    ],
                    'dumb_explanation' => $product
                        ? 'A fresh product was created. The rest of the test will only touch this product.'
                        : 'The product could not be created, so later stock/order tests may not have a product to use.',
                ];
            }
        );

        $addStep(
            'Create batch with unit barcodes in Store 1',
            'Creating one batch with six unit barcodes under store_id=1.',
            'Batch creation should use the same backend process used by Product > Batch, including barcode generation.',
            'One batch should be created with six sellable barcodes attached to that batch and store.',
            function () use (&$product, &$store, &$batch, &$barcodes, &$context) {
                if (!$product || !$store) {
                    return $this->skippedActual('Missing product or Store #1 from previous steps.');
                }

                $payload = [
                    'product_id' => $product->id,
                    'store_id' => 1,
                    'quantity' => 6,
                    'cost_price' => 100,
                    'sell_price' => 150,
                    'tax_percentage' => 0,
                    'barcode_type' => 'CODE128',
                    'notes' => 'Created by social-commerce lifecycle full test.',
                ];

                $response = app(ProductBatchController::class)->create(new Request($payload));
                $info = $this->responseInfo($response);
                $batchId = $info['body']['data']['batch']['id'] ?? null;
                $batch = $batchId ? ProductBatch::find($batchId) : null;
                $barcodes = $batch ? ProductBarcode::where('batch_id', $batch->id)->orderBy('id')->get() : collect();
                $context['batch_id'] = $batch?->id;
                $context['barcodes'] = $barcodes->pluck('barcode')->values()->all();

                app(InventoryReservationService::class)->syncProduct((int) $product->id);

                return [
                    'passed' => $this->responseSucceeded($info) && $batch !== null && $barcodes->count() >= 6,
                    'route_like' => 'POST /api/batches',
                    'actual' => [
                        'request' => $payload,
                        'response_status' => $info['status'],
                        'batch' => $batch ? ['id' => $batch->id, 'quantity' => $batch->quantity, 'store_id' => $batch->store_id] : null,
                        'barcodes' => $barcodes->map(fn ($bc) => [
                            'id' => $bc->id,
                            'barcode' => $bc->barcode,
                            'status' => $bc->current_status,
                            'store_id' => $bc->current_store_id,
                        ])->values(),
                        'reservation' => $this->reservationSnapshot($product->id),
                    ],
                    'dumb_explanation' => $barcodes->count() >= 6
                        ? 'The product now has enough physical barcode units for original sales, exchange replacement, resale, and negative tests.'
                        : 'The batch was not created with enough barcodes, so later scan tests may fail.',
                ];
            }
        );

        $addStep(
            'Create two assigned social-commerce orders',
            'Creating two real social-commerce orders and assigning both directly to store_id=1, just like manual assignment from the social-commerce cart.',
            'The order creation route should accept manual store assignment and move product orders directly into the packing queue.',
            'Both orders should be assigned_to_store with fulfillment_status=pending_fulfillment.',
            function () use ($runKey, &$product, &$customer, &$orderReturn, &$orderExchange, &$context) {
                if (!$product) {
                    return $this->skippedActual('Missing product from previous steps.');
                }

                $customer = Customer::firstOrCreate(
                    ['phone' => '017' . random_int(10000000, 99999999)],
                    [
                        'name' => $runKey . ' Lifecycle Customer',
                        'email' => strtolower($runKey) . '@deshio-test.local',
                        'address' => 'Lifecycle test customer address',
                        'customer_type' => 'social_commerce',
                        'status' => 'active',
                        'created_by' => auth()->id(),
                    ]
                );

                $make = function (string $suffix) use ($runKey, $product, $customer) {
                    $payload = [
                        'order_type' => 'social_commerce',
                        'store_id' => 1,
                        'store_assignment_mode' => 'manual',
                        'customer_id' => $customer->id,
                        'items' => [[
                            'product_id' => $product->id,
                            'quantity' => 1,
                            'unit_price' => 150,
                            'discount_amount' => 0,
                        ]],
                        'shipping_amount' => 0,
                        'notes' => $runKey . ' ' . $suffix,
                        'shipping_address' => [
                            'name' => $customer->name,
                            'phone' => $customer->phone,
                            'address_line_1' => 'Lifecycle diagnostic address',
                            'city' => 'Dhaka',
                            'state' => '',
                            'postal_code' => '',
                            'country' => 'Bangladesh',
                        ],
                    ];

                    $response = app(OrderController::class)->create(new Request($payload));
                    $info = $this->responseInfo($response);
                    $id = $info['body']['data']['id'] ?? null;
                    return [
                        'payload' => $payload,
                        'info' => $info,
                        'order' => $id ? Order::with('items')->find($id) : null,
                    ];
                };

                $a = $make('RETURN-ORDER');
                $b = $make('EXCHANGE-ORDER');
                $orderReturn = $a['order'];
                $orderExchange = $b['order'];
                $context['orders']['return_order'] = $orderReturn?->order_number;
                $context['orders']['exchange_order'] = $orderExchange?->order_number;

                $passed = $orderReturn && $orderExchange
                    && $orderReturn->status === 'assigned_to_store'
                    && $orderExchange->status === 'assigned_to_store'
                    && $orderReturn->fulfillment_status === 'pending_fulfillment'
                    && $orderExchange->fulfillment_status === 'pending_fulfillment'
                    && (int) $orderReturn->store_id === 1
                    && (int) $orderExchange->store_id === 1;

                return [
                    'passed' => $passed,
                    'route_like' => 'POST /api/orders',
                    'actual' => [
                        'return_order_response' => $a['info'],
                        'exchange_order_response' => $b['info'],
                        'orders' => [
                            $this->orderSnapshot($orderReturn?->fresh(['items'])),
                            $this->orderSnapshot($orderExchange?->fresh(['items'])),
                        ],
                        'reservation' => $this->reservationSnapshot($product->id),
                    ],
                    'dumb_explanation' => $passed
                        ? 'Both orders are now in the store-assigned packing stage.'
                        : 'At least one order did not enter the expected packing stage.',
                ];
            }
        );

        $addStep(
            'Pack and complete the two original orders with barcodes',
            'Scanning barcode #1 into the return-order and barcode #2 into the exchange-order, then completing both orders so those barcode units become sold/with customer.',
            'Online packing should attach the exact scanned barcode to the exact order item, then order completion should deduct stock and mark the barcode sold.',
            'Both orders should become confirmed and both scanned barcodes should become with_customer.',
            function () use (&$barcodes, &$orderReturn, &$orderExchange, &$returnOrderItem, &$exchangeOrderItem) {
                if (!$orderReturn || !$orderExchange || $barcodes->count() < 2) {
                    return $this->skippedActual('Missing orders or barcodes from previous steps.');
                }

                $barcodeReturn = $barcodes[0];
                $barcodeExchange = $barcodes[1];

                $packReturn = $this->packAndComplete($orderReturn, [$barcodeReturn->barcode]);
                $packExchange = $this->packAndComplete($orderExchange, [$barcodeExchange->barcode]);

                $returnOrderItem = OrderItem::where('order_id', $orderReturn->id)->where('product_barcode_id', $barcodeReturn->id)->first();
                $exchangeOrderItem = OrderItem::where('order_id', $orderExchange->id)->where('product_barcode_id', $barcodeExchange->id)->first();

                $barcodeReturn->refresh();
                $barcodeExchange->refresh();
                $orderReturn->refresh();
                $orderExchange->refresh();

                $passed = $this->responseSucceeded($packReturn['fulfill'])
                    && $this->responseSucceeded($packReturn['complete'])
                    && $this->responseSucceeded($packExchange['fulfill'])
                    && $this->responseSucceeded($packExchange['complete'])
                    && $orderReturn->status === 'confirmed'
                    && $orderExchange->status === 'confirmed'
                    && $orderReturn->fulfillment_status === 'fulfilled'
                    && $orderExchange->fulfillment_status === 'fulfilled'
                    && $barcodeReturn->current_status === 'with_customer'
                    && $barcodeExchange->current_status === 'with_customer';

                return [
                    'passed' => $passed,
                    'route_like' => 'PATCH /api/orders/{id}/fulfill then PATCH /api/orders/{id}/complete',
                    'actual' => [
                        'return_order_pack' => $packReturn,
                        'exchange_order_pack' => $packExchange,
                        'return_order' => $this->orderSnapshot($orderReturn->fresh(['items'])),
                        'exchange_order' => $this->orderSnapshot($orderExchange->fresh(['items'])),
                        'sold_barcodes' => [
                            $this->barcodeSnapshot($barcodeReturn->fresh()),
                            $this->barcodeSnapshot($barcodeExchange->fresh()),
                        ],
                    ],
                    'dumb_explanation' => $passed
                        ? 'The package workflow attached the physical barcodes, and completion sold those exact barcode units.'
                        : 'The barcode packing/completion path did not finish exactly as expected.',
                ];
            }
        );

        $addStep(
            'Negative test: sold barcode should not pack another order',
            'Trying to use the already-sold return-order barcode in a new social-commerce order before it is returned.',
            'The package scanner should reject a barcode that is already with a customer.',
            'The new order should not be fulfilled with the sold barcode.',
            function () use ($runKey, &$product, &$customer, &$barcodes, &$orderNegativeSoldReuse, &$context) {
                if (!$product || !$customer || $barcodes->count() < 1) {
                    return $this->skippedActual('Missing product/customer/barcode from previous steps.');
                }

                $orderNegativeSoldReuse = $this->createAssignedSocialOrder($runKey, $product, $customer, 'NEGATIVE-SOLD-REUSE', 1);
                $context['orders']['negative_sold_reuse'] = $orderNegativeSoldReuse?->order_number;

                $item = $orderNegativeSoldReuse?->items()->first();
                $response = $item
                    ? app(OrderController::class)->fulfill(new Request([
                        'fulfillments' => [[
                            'order_item_id' => $item->id,
                            'barcodes' => [$barcodes[0]->barcode],
                        ]],
                    ]), $orderNegativeSoldReuse->id)
                    : null;
                $info = $response ? $this->responseInfo($response) : ['status' => 0, 'body' => ['message' => 'No order item to test.']];

                // Cancel this intentionally failed order so its temporary reservation is released.
                if ($orderNegativeSoldReuse) {
                    app(OrderController::class)->cancel(new Request(['reason' => 'Lifecycle negative test cleanup']), $orderNegativeSoldReuse->id);
                    app(InventoryReservationService::class)->syncProduct((int) $product->id);
                    $orderNegativeSoldReuse->refresh();
                }

                return [
                    'passed' => !$this->responseSucceeded($info),
                    'route_like' => 'POST /api/orders then PATCH /api/orders/{id}/fulfill',
                    'actual' => [
                        'attempted_barcode' => $barcodes[0]->barcode,
                        'fulfill_response' => $info,
                        'cleanup_order' => $this->orderSnapshot($orderNegativeSoldReuse),
                        'reservation' => $this->reservationSnapshot($product->id),
                    ],
                    'dumb_explanation' => !$this->responseSucceeded($info)
                        ? 'Good. A barcode already sold to a customer was blocked from being packed again.'
                        : 'Bad. A sold barcode was allowed to pack a second order before return/exchange.',
                ];
            }
        );

        $addStep(
            'Return one order from Lookup return modal route',
            'Returning the first sold barcode as a normal customer return received at Store 1.',
            'The return quick-complete route should restore the exact sold barcode into sellable stock.',
            'Returned barcode #1 should become available/sellable again.',
            function () use (&$product, &$orderReturn, &$returnOrderItem, &$barcodes) {
                if (!$orderReturn || !$returnOrderItem || $barcodes->count() < 1) {
                    return $this->skippedActual('Missing sold return order/item/barcode from previous steps.');
                }

                $barcode = $barcodes[0]->fresh();
                $payload = [
                    'order_id' => $orderReturn->id,
                    'received_at_store_id' => 1,
                    'return_reason' => 'changed_mind',
                    'return_type' => 'customer_return',
                    'customer_notes' => 'Lifecycle diagnostic normal return.',
                    'items' => [[
                        'order_item_id' => $returnOrderItem->id,
                        'quantity' => 1,
                        'product_barcode_id' => $barcode->id,
                        'unit_price' => 150,
                        'manual_sold_at_price' => 150,
                        'total_price' => 150,
                        'reason' => 'Lifecycle diagnostic normal return.',
                    ]],
                ];

                $response = app(ProductReturnController::class)->quickComplete(new Request($payload));
                $info = $this->responseInfo($response);
                $barcode->refresh();
                app(InventoryReservationService::class)->syncProduct((int) $product->id);

                return [
                    'passed' => $this->responseSucceeded($info)
                        && in_array($barcode->current_status, ['available', 'in_shop', 'in_warehouse', 'on_display'], true)
                        && !$barcode->batchDeletedLink()->exists(),
                    'route_like' => 'POST /api/product-returns/quick-complete',
                    'actual' => [
                        'request' => $payload,
                        'response' => $info,
                        'returned_barcode' => $this->barcodeSnapshot($barcode),
                        'reservation' => $this->reservationSnapshot($product->id),
                    ],
                    'dumb_explanation' => in_array($barcode->current_status, ['available', 'in_shop', 'in_warehouse', 'on_display'], true)
                        ? 'The returned barcode is back in sellable stock.'
                        : 'The return did not restore the barcode into a sellable state.',
                ];
            }
        );

        $addStep(
            'Exchange the other order through Lookup exchange modal route',
            'Exchanging the second sold barcode and using barcode #3 as the replacement item.',
            'The exchange process should restore the removed barcode and sell the replacement barcode.',
            'Returned/exchanged barcode #2 should become sellable, while replacement barcode #3 should become with_customer.',
            function () use (&$product, &$orderExchange, &$exchangeOrderItem, &$barcodes) {
                if (!$product || !$orderExchange || !$exchangeOrderItem || $barcodes->count() < 3) {
                    return $this->skippedActual('Missing exchange order/item or replacement barcode from previous steps.');
                }

                $removedBarcode = $barcodes[1]->fresh();
                $replacementBarcode = $barcodes[2]->fresh();
                $replacementBatchId = $replacementBarcode->batch_id;

                $payload = [
                    'order_id' => $orderExchange->id,
                    'customer_id' => $orderExchange->customer_id,
                    'exchangeAtStoreId' => 1,
                    'removedProducts' => [[
                        'product_id' => $product->id,
                        'product_batch_id' => $exchangeOrderItem->product_batch_id,
                        'quantity' => 1,
                        'unit_price' => 150,
                        'total_price' => 150,
                        'order_item_id' => $exchangeOrderItem->id,
                        'product_barcode_id' => $removedBarcode->id,
                        'return_reason' => 'changed_mind',
                        'quality_check_passed' => true,
                    ]],
                    'replacementProducts' => [[
                        'product_id' => $product->id,
                        'batch_id' => $replacementBatchId,
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
                    'notes' => 'Lifecycle diagnostic exchange.',
                ];

                $response = app(ExchangeController::class)->process(new Request($payload));
                $info = $this->responseInfo($response);
                $removedBarcode->refresh();
                $replacementBarcode->refresh();
                app(InventoryReservationService::class)->syncProduct((int) $product->id);

                return [
                    'passed' => $this->responseSucceeded($info)
                        && in_array($removedBarcode->current_status, ['available', 'in_shop', 'in_warehouse', 'on_display'], true)
                        && $replacementBarcode->current_status === 'with_customer',
                    'route_like' => 'POST /api/exchange/process',
                    'actual' => [
                        'request' => $payload,
                        'response' => $info,
                        'exchanged_returned_barcode' => $this->barcodeSnapshot($removedBarcode),
                        'replacement_sold_barcode' => $this->barcodeSnapshot($replacementBarcode),
                        'reservation' => $this->reservationSnapshot($product->id),
                    ],
                    'dumb_explanation' => in_array($removedBarcode->current_status, ['available', 'in_shop', 'in_warehouse', 'on_display'], true)
                        ? 'The exchanged item came back into stock, and the replacement item went out to the customer.'
                        : 'The exchange did not restore the returned barcode correctly.',
                ];
            }
        );

        $addStep(
            'Resell the returned and exchanged barcodes in a new social-commerce order',
            'Creating one new assigned social-commerce order for quantity 2, then packing it with the two barcodes restored by return/exchange.',
            'Returned/exchanged barcodes should be reusable after they are properly restored from Lookup return/exchange.',
            'The resale order should complete successfully with barcode #1 and barcode #2.',
            function () use ($runKey, &$product, &$customer, &$barcodes, &$orderResell, &$context) {
                if (!$product || !$customer || $barcodes->count() < 2) {
                    return $this->skippedActual('Missing product/customer/restored barcodes from previous steps.');
                }

                $orderResell = $this->createAssignedSocialOrder($runKey, $product, $customer, 'RESALE-RETURNED-BARCODES', 2);
                $context['orders']['resale_order'] = $orderResell?->order_number;

                $pack = $orderResell
                    ? $this->packAndComplete($orderResell, [$barcodes[0]->barcode, $barcodes[1]->barcode])
                    : ['fulfill' => ['status' => 0, 'body' => ['message' => 'Order was not created']], 'complete' => ['status' => 0, 'body' => []]];

                $barcodes[0]->refresh();
                $barcodes[1]->refresh();
                $orderResell?->refresh();

                return [
                    'passed' => $orderResell
                        && $this->responseSucceeded($pack['fulfill'])
                        && $this->responseSucceeded($pack['complete'])
                        && $orderResell->status === 'confirmed'
                        && $barcodes[0]->current_status === 'with_customer'
                        && $barcodes[1]->current_status === 'with_customer',
                    'route_like' => 'POST /api/orders then PATCH /api/orders/{id}/fulfill then PATCH /api/orders/{id}/complete',
                    'actual' => [
                        'resale_order' => $this->orderSnapshot($orderResell?->fresh(['items'])),
                        'pack_and_complete' => $pack,
                        'resold_barcodes' => [
                            $this->barcodeSnapshot($barcodes[0]->fresh()),
                            $this->barcodeSnapshot($barcodes[1]->fresh()),
                        ],
                        'reservation' => $this->reservationSnapshot($product->id),
                    ],
                    'dumb_explanation' => $orderResell && $orderResell->status === 'confirmed'
                        ? 'Good. Returned and exchanged barcodes were resold through the social-commerce packing path.'
                        : 'The restored barcodes could not be resold through social-commerce.',
                ];
            }
        );

        $addStep(
            'Prepare pending order before batch deletion',
            'Creating one more assigned social-commerce order before deleting the batch. This order will test that deleted-batch barcodes cannot be packed later.',
            'An order created before a batch deletion should still be protected at scan time if the barcode becomes deleted-batch blocked.',
            'The pending order should be created and wait for packing.',
            function () use ($runKey, &$product, &$customer, &$orderDeletedBatchNegative, &$context) {
                if (!$product || !$customer) {
                    return $this->skippedActual('Missing product/customer from previous steps.');
                }

                $orderDeletedBatchNegative = $this->createAssignedSocialOrder($runKey, $product, $customer, 'NEGATIVE-DELETED-BATCH-PACK', 1);
                $context['orders']['negative_deleted_batch'] = $orderDeletedBatchNegative?->order_number;

                return [
                    'passed' => $orderDeletedBatchNegative
                        && $orderDeletedBatchNegative->status === 'assigned_to_store'
                        && $orderDeletedBatchNegative->fulfillment_status === 'pending_fulfillment',
                    'route_like' => 'POST /api/orders',
                    'actual' => [
                        'order' => $this->orderSnapshot($orderDeletedBatchNegative?->fresh(['items'])),
                        'reservation' => $product ? $this->reservationSnapshot($product->id) : null,
                    ],
                    'dumb_explanation' => $orderDeletedBatchNegative
                        ? 'The test now has a real packing-stage order to challenge after batch deletion.'
                        : 'The negative test order could not be created.',
                ];
            }
        );

        $addStep(
            'Delete product batch through Product > Batch delete logic',
            'Deleting the test batch with the same single-batch deletion process used by Product > Batch.',
            'Batch deletion should remove the batch, preserve barcode identities, and log those barcodes into batch_deleted_barcodes.',
            'The batch should disappear and all its barcodes should have batch_deleted_barcodes safety records.',
            function () use (&$product, &$batch, &$barcodes) {
                if (!$product || !$batch) {
                    return $this->skippedActual('Missing product/batch from previous steps.');
                }

                $barcodeIds = $barcodes->pluck('id')->values()->all();
                $response = app(ProductBatchController::class)->destroy($batch->id);
                $info = $this->responseInfo($response);
                $loggedCount = BatchDeletedBarcode::whereIn('product_barcode_id', $barcodeIds)->count();
                $batchStillExists = ProductBatch::whereKey($batch->id)->exists();
                app(InventoryReservationService::class)->syncProduct((int) $product->id);

                return [
                    'passed' => $this->responseSucceeded($info)
                        && !$batchStillExists
                        && $loggedCount === count($barcodeIds),
                    'route_like' => 'DELETE /api/batches/{id}',
                    'actual' => [
                        'response' => $info,
                        'deleted_batch_id' => $batch->id,
                        'batch_still_exists' => $batchStillExists,
                        'barcodes_expected_to_log' => count($barcodeIds),
                        'barcodes_logged_in_batch_deleted_barcodes' => $loggedCount,
                        'reservation' => $this->reservationSnapshot($product->id),
                    ],
                    'dumb_explanation' => !$batchStillExists && $loggedCount === count($barcodeIds)
                        ? 'The batch was deleted and all old barcode identities were recorded as blocked/deleted-batch barcodes.'
                        : 'The batch deletion safety records did not match the expected barcode count.',
                ];
            }
        );

        $addStep(
            'Negative test: deleted-batch barcode should not pack social-commerce order',
            'Trying to pack the pending order with a barcode from the batch that was just deleted.',
            'The same barcode sale validator used by online packing should block deleted-batch barcodes.',
            'Packing should fail with a deleted-batch style message.',
            function () use (&$orderDeletedBatchNegative, &$barcodes, &$product) {
                if (!$orderDeletedBatchNegative || $barcodes->count() < 4) {
                    return $this->skippedActual('Missing pending negative order or deleted-batch barcode from previous steps.');
                }

                $item = $orderDeletedBatchNegative->items()->first();
                $testBarcode = $barcodes[3]->barcode;
                $response = $item
                    ? app(OrderController::class)->fulfill(new Request([
                        'fulfillments' => [[
                            'order_item_id' => $item->id,
                            'barcodes' => [$testBarcode],
                        ]],
                    ]), $orderDeletedBatchNegative->id)
                    : null;
                $info = $response ? $this->responseInfo($response) : ['status' => 0, 'body' => ['message' => 'No order item to test.']];

                if ($orderDeletedBatchNegative) {
                    app(OrderController::class)->cancel(new Request(['reason' => 'Lifecycle deleted-batch negative cleanup']), $orderDeletedBatchNegative->id);
                    if ($product) {
                        app(InventoryReservationService::class)->syncProduct((int) $product->id);
                    }
                    $orderDeletedBatchNegative->refresh();
                }

                $message = strtolower((string) ($info['body']['message'] ?? ''));
                $blockedForExpectedReason = str_contains($message, 'deleted batch') || str_contains($message, 'deleted-batch') || str_contains($message, 'deleted');

                return [
                    'passed' => !$this->responseSucceeded($info) && $blockedForExpectedReason,
                    'route_like' => 'PATCH /api/orders/{id}/fulfill',
                    'actual' => [
                        'attempted_barcode' => $testBarcode,
                        'fulfill_response' => $info,
                        'cancelled_negative_order' => $this->orderSnapshot($orderDeletedBatchNegative),
                        'reservation' => $product ? $this->reservationSnapshot($product->id) : null,
                    ],
                    'dumb_explanation' => !$this->responseSucceeded($info)
                        ? 'Good. The deleted-batch barcode could not be packed into a social-commerce order.'
                        : 'Bad. A barcode from a deleted batch was allowed to pack.',
                ];
            }
        );

        $addStep(
            'Delete the product after batches are gone',
            'Deleting the test product after its batch has already been removed.',
            'Product deletion should work only after batches are gone, matching the product deletion rule.',
            'The product should be soft-deleted / no longer active after the batch is removed.',
            function () use (&$product) {
                if (!$product) {
                    return $this->skippedActual('Missing product from previous steps.');
                }

                $response = app(ProductController::class)->destroy($product->id);
                $info = $this->responseInfo($response);
                $freshWithTrashed = Product::withTrashed()->find($product->id);

                return [
                    'passed' => $this->responseSucceeded($info) && $freshWithTrashed && $freshWithTrashed->trashed(),
                    'route_like' => 'DELETE /api/products/{id}',
                    'actual' => [
                        'response' => $info,
                        'product_id' => $product->id,
                        'exists_with_trashed' => (bool) $freshWithTrashed,
                        'is_soft_deleted' => (bool) ($freshWithTrashed?->trashed()),
                    ],
                    'dumb_explanation' => $freshWithTrashed?->trashed()
                        ? 'The temporary product was deleted after its batch was deleted.'
                        : 'The product deletion did not finish as expected.',
                ];
            }
        );

        $passed = collect($tests)->where('passed', true)->count();
        $failed = count($tests) - $passed;

        return response()->json([
            'success' => $failed === 0,
            'message' => $failed === 0
                ? 'Social-commerce lifecycle diagnostic passed.'
                : 'Social-commerce lifecycle diagnostic completed with failures. It did not stop early; inspect failed rows.',
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

    private function createAssignedSocialOrder(string $runKey, Product $product, Customer $customer, string $suffix, int $quantity): ?Order
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
                'address_line_1' => 'Lifecycle diagnostic address',
                'city' => 'Dhaka',
                'state' => '',
                'postal_code' => '',
                'country' => 'Bangladesh',
            ],
        ];

        $response = app(OrderController::class)->create(new Request($payload));
        $info = $this->responseInfo($response);
        $id = $info['body']['data']['id'] ?? null;

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
            'dumb_explanation' => 'This step was skipped because a previous required object was missing. The test kept going instead of stopping.',
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
            'total_amount' => (float) $order->total_amount,
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
}
