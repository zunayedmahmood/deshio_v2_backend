<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BarcodeMappingTestController extends Controller
{
    private const INITIAL_SKU_COUNT = 10;
    private const PRODUCTS_PER_SKU = 10;
    private const TARGET_BARCODE_COUNT = 1000;
    private const UNITS_PER_PRODUCT = 5;
    private const STORE_ID = 2;
    private const UNIT_COST = 1;
    private const UNIT_SELL_PRICE = 1.5;
    private const DELETE_PASSWORD = '12345678';

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        @ignore_user_abort(true);

        return response()->stream(function () use ($request) {
            $emit = function (array $payload) {
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            try {
                $this->runTest($request, $emit);
            } catch (\Throwable $e) {
                Log::error('Barcode mapping test failed', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $emit([
                    'type' => 'fatal_error',
                    'stage' => 'failed',
                    'percent' => 100,
                    'message' => $e->getMessage(),
                    'trace' => $this->shortTrace($e),
                ]);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function runTest(Request $request, callable $emit): void
    {
        $startedAt = now();
        $po = null;
        $cleanup = null;
        $summary = null;

        $targetProductCount = (int) ceil(self::TARGET_BARCODE_COUNT / self::UNITS_PER_PRODUCT);

        $emit([
            'type' => 'progress',
            'stage' => 'starting',
            'percent' => 0,
            'message' => 'Starting barcode mapping test.',
            'config' => [
                'initial_sku_count' => self::INITIAL_SKU_COUNT,
                'products_per_sku' => self::PRODUCTS_PER_SKU,
                'target_product_count' => $targetProductCount,
                'units_per_product' => self::UNITS_PER_PRODUCT,
                'target_barcodes' => self::TARGET_BARCODE_COUNT,
                'store_id' => self::STORE_ID,
                'unit_cost' => self::UNIT_COST,
                'unit_sell_price' => self::UNIT_SELL_PRICE,
                'note' => '1000 barcodes with 5 units per product requires 200 products. The selector starts with 10 random SKUs and continues filling from more SKUs until the product quota is met.',
            ],
        ]);

        try {
            $selectedProducts = $this->selectProducts($emit, $targetProductCount);
            if ($selectedProducts->count() < $targetProductCount) {
                throw new \RuntimeException('Only ' . $selectedProducts->count() . ' eligible products found. Need ' . $targetProductCount . ' products to generate ' . self::TARGET_BARCODE_COUNT . ' barcodes with ' . self::UNITS_PER_PRODUCT . ' units per product.');
            }

            $po = $this->createPurchaseOrder($selectedProducts, $emit);
            $this->approvePurchaseOrder($po, $emit);
            $this->receivePurchaseOrder($po, $emit);

            $barcodeRows = $this->loadGeneratedBarcodes($po, $emit);
            $summary = $this->checkBarcodes($barcodeRows, $emit);
        } catch (\Throwable $e) {
            $summary = [
                'fatal_error' => [
                    'message' => $e->getMessage(),
                    'trace' => $this->shortTrace($e),
                ],
            ];

            $emit([
                'type' => 'fatal_error',
                'stage' => 'running',
                'percent' => 100,
                'message' => $e->getMessage(),
                'trace' => $this->shortTrace($e),
            ]);
        } finally {
            if ($po) {
                $cleanup = $this->deletePurchaseOrder($po, $emit);
            }
        }

        $finalSummary = array_merge($summary ?? [], [
            'purchase_order_id' => $po?->id,
            'po_number' => $po?->po_number,
            'cleanup' => $cleanup,
            'started_at' => $startedAt->toDateTimeString(),
            'finished_at' => now()->toDateTimeString(),
        ]);

        $emit([
            'type' => empty($summary['fatal_error']) ? 'complete' : 'complete_with_error',
            'stage' => 'complete',
            'percent' => 100,
            'message' => empty($summary['fatal_error'])
                ? 'Barcode mapping test completed and cleanup attempted.'
                : 'Barcode mapping test stopped after a fatal setup/runtime error; cleanup attempted if a PO was created.',
            'summary' => $finalSummary,
        ]);
    }

    private function selectProducts(callable $emit, int $targetProductCount): Collection
    {
        $emit([
            'type' => 'progress',
            'stage' => 'selecting_products',
            'percent' => 0,
            'message' => 'Selecting random SKU groups and product variants.',
        ]);

        $selected = collect();
        $usedIds = [];
        $usedSkus = [];
        $selectionLog = [];
        $iteration = 0;

        while ($selected->count() < $targetProductCount) {
            $remaining = $targetProductCount - $selected->count();
            $skuLimit = empty($usedSkus) ? self::INITIAL_SKU_COUNT : min(self::INITIAL_SKU_COUNT, max(1, (int) ceil($remaining / self::PRODUCTS_PER_SKU)));

            $skuList = Product::query()
                ->where('is_archived', false)
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->whereNotIn('sku', array_keys($usedSkus))
                ->select('sku')
                ->groupBy('sku')
                ->inRandomOrder()
                ->limit($skuLimit)
                ->pluck('sku');

            if ($skuList->isEmpty()) {
                break;
            }

            foreach ($skuList->values() as $sku) {
                if ($selected->count() >= $targetProductCount) {
                    break;
                }

                $usedSkus[$sku] = true;
                $availableCount = Product::query()
                    ->where('is_archived', false)
                    ->where('sku', $sku)
                    ->whereNotIn('id', array_keys($usedIds))
                    ->count();

                $take = min(self::PRODUCTS_PER_SKU, $targetProductCount - $selected->count());
                $products = Product::query()
                    ->where('is_archived', false)
                    ->where('sku', $sku)
                    ->whereNotIn('id', array_keys($usedIds))
                    ->inRandomOrder()
                    ->limit($take)
                    ->get();

                foreach ($products as $product) {
                    $selected->push($product);
                    $usedIds[$product->id] = true;
                }

                $selectionLog[] = [
                    'sku' => $sku,
                    'available_products_in_sku' => $availableCount,
                    'selected_from_sku' => $products->count(),
                    'selected_total' => $selected->count(),
                ];
            }

            $iteration++;
            $percent = $targetProductCount > 0
                ? min(100, (int) floor(($selected->count() / $targetProductCount) * 100))
                : 100;
            $percent = $this->roundDownToTen($percent);

            $emit([
                'type' => 'progress',
                'stage' => 'selecting_products',
                'percent' => $percent,
                'message' => 'Selecting products: ' . $percent . '%',
                'selected_products' => $selected->count(),
                'target_products' => $targetProductCount,
                'sku_groups_used' => count($usedSkus),
                'last_selection_batch' => array_slice($selectionLog, -10),
            ]);

            if ($iteration > 100) {
                break;
            }
        }

        $selected = $selected->take($targetProductCount)->values();

        $emit([
            'type' => 'progress',
            'stage' => 'selecting_products',
            'percent' => 100,
            'message' => 'Selected ' . $selected->count() . ' products from ' . $selected->pluck('sku')->unique()->count() . ' SKU groups.',
            'selected_products' => $selected->count(),
            'target_products' => $targetProductCount,
            'selected_skus' => $selected->pluck('sku')->unique()->values()->all(),
            'selection_log' => $selectionLog,
        ]);

        return $selected;
    }

    private function createPurchaseOrder(Collection $products, callable $emit): PurchaseOrder
    {
        $emit([
            'type' => 'progress',
            'stage' => 'generating_po',
            'percent' => 0,
            'message' => 'Creating diagnostic purchase order using PurchaseOrderController::create.',
        ]);

        $vendorId = $products->pluck('vendor_id')->filter()->first() ?: Vendor::query()->value('id');
        if (!$vendorId) {
            throw new \RuntimeException('No vendor found. Purchase order creation requires a vendor.');
        }

        $items = $products->map(function (Product $product) {
            return [
                'product_id' => $product->id,
                'quantity_ordered' => self::UNITS_PER_PRODUCT,
                'unit_cost' => self::UNIT_COST,
                'unit_sell_price' => self::UNIT_SELL_PRICE,
                'notes' => 'Barcode mapping diagnostic test item',
            ];
        })->values()->all();

        $controller = app(PurchaseOrderController::class);
        $payload = $this->responsePayload($controller->create(Request::create('/api/purchase-orders', 'POST', [
            'vendor_id' => $vendorId,
            'store_id' => self::STORE_ID,
            'notes' => 'Barcode mapping diagnostic test generated from public/barcode-mapping-test.html. Safe to delete after test.',
            'items' => $items,
        ])));

        if (!($payload['success'] ?? false) || empty($payload['data']['id'])) {
            throw new \RuntimeException('PO creation failed: ' . ($payload['message'] ?? 'Unknown error'));
        }

        $po = PurchaseOrder::with('items')->findOrFail($payload['data']['id']);

        $emit([
            'type' => 'progress',
            'stage' => 'generating_po',
            'percent' => 100,
            'message' => 'Created PO ' . $po->po_number . '.',
            'purchase_order_id' => $po->id,
            'po_number' => $po->po_number,
            'items' => $po->items->count(),
        ]);

        return $po;
    }

    private function approvePurchaseOrder(PurchaseOrder $po, callable $emit): void
    {
        $emit([
            'type' => 'progress',
            'stage' => 'approving_po',
            'percent' => 0,
            'message' => 'Approving PO using PurchaseOrderController::approve.',
        ]);

        $controller = app(PurchaseOrderController::class);
        $payload = $this->responsePayload($controller->approve($po->id));

        if (!($payload['success'] ?? false)) {
            throw new \RuntimeException('PO approval failed: ' . ($payload['message'] ?? 'Unknown error'));
        }

        $emit([
            'type' => 'progress',
            'stage' => 'approving_po',
            'percent' => 100,
            'message' => 'PO approved.',
        ]);
    }

    private function receivePurchaseOrder(PurchaseOrder $po, callable $emit): void
    {
        $po->load('items');

        $emit([
            'type' => 'progress',
            'stage' => 'receiving_po',
            'percent' => 0,
            'message' => 'Receiving PO using PurchaseOrderController::receive. This generates the barcodes through the real receiving code path.',
        ]);

        $items = $po->items->map(function ($item, int $index) use ($po) {
            return [
                'item_id' => $item->id,
                'quantity_received' => self::UNITS_PER_PRODUCT,
                'batch_number' => 'BMT-' . $po->id . '-' . now()->format('YmdHis') . '-' . ($index + 1),
            ];
        })->values()->all();

        $controller = app(PurchaseOrderController::class);
        $payload = $this->responsePayload($controller->receive(Request::create('/api/purchase-orders/' . $po->id . '/receive', 'POST', [
            'items' => $items,
        ]), $po->id));

        if (!($payload['success'] ?? false)) {
            throw new \RuntimeException('PO receiving failed: ' . ($payload['message'] ?? 'Unknown error'));
        }

        $emit([
            'type' => 'progress',
            'stage' => 'receiving_po',
            'percent' => 100,
            'message' => 'PO received and barcode generation completed.',
        ]);
    }

    private function loadGeneratedBarcodes(PurchaseOrder $po, callable $emit): Collection
    {
        $emit([
            'type' => 'progress',
            'stage' => 'loading_barcodes',
            'percent' => 0,
            'message' => 'Loading generated barcodes through received PO batches and product/batch page logic.',
        ]);

        $po->load('items.product');
        $itemsByBatchId = $po->items
            ->filter(fn ($item) => !empty($item->product_batch_id))
            ->keyBy('product_batch_id');

        $batchIds = $itemsByBatchId->keys()->values();
        if ($batchIds->isEmpty()) {
            throw new \RuntimeException('No product batches were linked to the received PO items. Cannot load generated barcodes.');
        }

        $batchController = app(ProductBatchController::class);
        $batchPageProducts = [];
        $batchPageErrors = [];

        foreach ($batchIds->values() as $index => $batchId) {
            try {
                $payload = $this->responsePayload($batchController->show($batchId));
                if (($payload['success'] ?? false) && isset($payload['data']['product'])) {
                    $batchPageProducts[(int) $batchId] = $payload['data']['product'];
                } else {
                    $batchPageErrors[] = [
                        'batch_id' => (int) $batchId,
                        'message' => $payload['message'] ?? 'Product/batch page returned an unexpected response.',
                    ];
                }
            } catch (\Throwable $e) {
                $batchPageErrors[] = [
                    'batch_id' => (int) $batchId,
                    'message' => $e->getMessage(),
                ];
            }

            if (($index + 1) % max(1, (int) ceil($batchIds->count() / 10)) === 0 || $index === $batchIds->count() - 1) {
                $percent = $this->roundDownToTen((int) round((($index + 1) / max(1, $batchIds->count())) * 100));
                $emit([
                    'type' => 'progress',
                    'stage' => 'loading_barcodes',
                    'percent' => $percent,
                    'message' => 'Reading product/batch page records: ' . $percent . '%',
                    'batches_checked' => $index + 1,
                    'total_batches' => $batchIds->count(),
                    'batch_page_error_count' => count($batchPageErrors),
                ]);
            }
        }

        $barcodes = ProductBarcode::query()
            ->with(['product', 'batch'])
            ->whereIn('batch_id', $batchIds)
            ->orderBy('id')
            ->get();

        $barcodeRows = $barcodes->map(function (ProductBarcode $barcode) use ($itemsByBatchId, $batchPageProducts) {
            $poItem = $itemsByBatchId->get($barcode->batch_id);
            $expectedProduct = $poItem?->product;
            $batchPageProduct = $batchPageProducts[(int) $barcode->batch_id] ?? null;

            return [
                'barcode' => $barcode,
                'expected' => [
                    'id' => $expectedProduct?->id,
                    'name' => $expectedProduct?->name,
                    'sku' => $expectedProduct?->sku,
                    'description' => $expectedProduct?->description,
                    'source' => 'purchase_order_item.product',
                ],
                'po_item' => [
                    'id' => $poItem?->id,
                    'product_id' => $poItem?->product_id,
                    'product_name' => $poItem?->product_name,
                    'product_sku' => $poItem?->product_sku,
                    'product_batch_id' => $poItem?->product_batch_id,
                ],
                'barcode_table_product' => $barcode->product ? [
                    'id' => $barcode->product->id,
                    'name' => $barcode->product->name,
                    'sku' => $barcode->product->sku,
                    'description' => $barcode->product->description,
                ] : null,
                'batch_table_product_id' => $barcode->batch?->product_id,
                'batch_page_product' => $batchPageProduct,
            ];
        });

        $emit([
            'type' => 'progress',
            'stage' => 'loading_barcodes',
            'percent' => 100,
            'message' => 'Loaded ' . $barcodeRows->count() . ' generated barcodes.',
            'barcode_count' => $barcodeRows->count(),
            'expected_barcode_count' => self::TARGET_BARCODE_COUNT,
            'batch_page_error_count' => count($batchPageErrors),
            'batch_page_errors' => $batchPageErrors,
        ]);

        return $barcodeRows;
    }

    private function checkBarcodes(Collection $barcodeRows, callable $emit): array
    {
        $total = $barcodeRows->count();
        $posMatches = 0;
        $lookupMatches = 0;
        $mismatches = [];
        $errors = [];
        $internalIntegrityIssues = [];
        $posController = app(ProductBarcodeController::class);
        $lookupController = app(LookupController::class);

        $emit([
            'type' => 'progress',
            'stage' => 'checking_barcodes',
            'percent' => 0,
            'message' => 'Checking each barcode through POS scan and lookup barcode-history logic.',
            'barcode_count' => $total,
        ]);

        $progressInterval = max(1, min(10, (int) ceil(max(1, $total) / 100)));

        foreach ($barcodeRows->values() as $index => $row) {
            /** @var ProductBarcode $barcode */
            $barcode = $row['barcode'];
            $expected = $row['expected'];

            $integrityIssue = $this->internalIntegrityIssue($row);
            if ($integrityIssue) {
                $internalIntegrityIssues[] = $integrityIssue;
                $emit([
                    'type' => 'mismatch',
                    'stage' => 'checking_barcodes',
                    'source' => 'internal_mapping',
                    'message' => 'Internal PO/batch/barcode product mapping mismatch.',
                    'detail' => $integrityIssue,
                ]);
            }

            try {
                $posPayload = $this->responsePayload($posController->scan(Request::create('/api/barcodes/scan', 'POST', [
                    'barcode' => $barcode->barcode,
                    'store_id' => self::STORE_ID,
                ])));
                $posProduct = $this->extractProductFromPayload($posPayload);
                $posOk = ($posPayload['success'] ?? false) && $this->productsMatch($expected, $posProduct);
                if ($posOk) {
                    $posMatches++;
                } else {
                    $mismatch = $this->mismatchRow($row, 'pos_scan', $expected, $posProduct, $posPayload['message'] ?? null, $posPayload);
                    $mismatches[] = $mismatch;
                    $emit([
                        'type' => 'mismatch',
                        'stage' => 'checking_barcodes',
                        'source' => 'pos_scan',
                        'message' => 'POS scan product mapping mismatch.',
                        'detail' => $mismatch,
                    ]);
                }
            } catch (\Throwable $e) {
                $error = $this->errorRow($row, 'pos_scan', $e->getMessage(), $e);
                $errors[] = $error;
                $emit([
                    'type' => 'error',
                    'stage' => 'checking_barcodes',
                    'source' => 'pos_scan',
                    'message' => 'POS scan error: ' . $e->getMessage(),
                    'detail' => $error,
                ]);
            }

            try {
                $lookupPayload = $this->responsePayload($lookupController->productLookup(Request::create('/api/lookup/product', 'GET', [
                    'barcode' => $barcode->barcode,
                ])));
                $lookupProduct = $this->extractProductFromPayload($lookupPayload);
                $lookupOk = ($lookupPayload['success'] ?? false) && $this->productsMatch($expected, $lookupProduct);
                if ($lookupOk) {
                    $lookupMatches++;
                } else {
                    $mismatch = $this->mismatchRow($row, 'lookup_barcode_history', $expected, $lookupProduct, $lookupPayload['message'] ?? null, $lookupPayload);
                    $mismatches[] = $mismatch;
                    $emit([
                        'type' => 'mismatch',
                        'stage' => 'checking_barcodes',
                        'source' => 'lookup_barcode_history',
                        'message' => 'Lookup barcode-history product mapping mismatch.',
                        'detail' => $mismatch,
                    ]);
                }
            } catch (\Throwable $e) {
                $error = $this->errorRow($row, 'lookup_barcode_history', $e->getMessage(), $e);
                $errors[] = $error;
                $emit([
                    'type' => 'error',
                    'stage' => 'checking_barcodes',
                    'source' => 'lookup_barcode_history',
                    'message' => 'Lookup barcode-history error: ' . $e->getMessage(),
                    'detail' => $error,
                ]);
            }

            if (($index + 1) % $progressInterval === 0 || $index === $total - 1) {
                $percent = $total > 0 ? (int) round((($index + 1) / $total) * 100) : 100;
                $percent = $this->roundDownToTen($percent);
                if ($index === $total - 1) {
                    $percent = 100;
                }

                $emit([
                    'type' => 'progress',
                    'stage' => 'checking_barcodes',
                    'percent' => $percent,
                    'message' => 'Checking barcode ' . ($index + 1) . ' of ' . $total . ' (' . $percent . '%)',
                    'checked_barcodes' => $index + 1,
                    'total_barcodes' => $total,
                    'pos_matches' => $posMatches,
                    'lookup_matches' => $lookupMatches,
                    'mismatch_count' => count($mismatches),
                    'error_count' => count($errors),
                    'internal_integrity_issue_count' => count($internalIntegrityIssues),
                ]);
            }
        }

        return [
            'target_barcodes' => self::TARGET_BARCODE_COUNT,
            'actual_barcodes' => $total,
            'units_per_product' => self::UNITS_PER_PRODUCT,
            'total_scan_checks' => $total * 2,
            'pos_matches' => $posMatches,
            'lookup_matches' => $lookupMatches,
            'mismatch_count' => count($mismatches),
            'error_count' => count($errors),
            'internal_integrity_issue_count' => count($internalIntegrityIssues),
            'mismatches' => $mismatches,
            'errors' => $errors,
            'internal_integrity_issues' => $internalIntegrityIssues,
        ];
    }

    private function deletePurchaseOrder(PurchaseOrder $po, callable $emit): array
    {
        $emit([
            'type' => 'progress',
            'stage' => 'cleanup',
            'percent' => 0,
            'message' => 'Deleting diagnostic PO using PurchaseOrderController::destroy.',
            'purchase_order_id' => $po->id,
            'po_number' => $po->po_number,
        ]);

        try {
            $controller = app(PurchaseOrderController::class);
            $payload = $this->responsePayload($controller->destroy(Request::create('/api/purchase-orders/' . $po->id, 'DELETE', [
                'password' => self::DELETE_PASSWORD,
            ]), $po->id));

            $ok = (bool) ($payload['success'] ?? false);
            $result = [
                'success' => $ok,
                'purchase_order_id' => $po->id,
                'po_number' => $po->po_number,
                'message' => $payload['message'] ?? ($ok ? 'Deleted.' : 'Delete failed.'),
                'payload' => $payload,
            ];

            $emit([
                'type' => $ok ? 'progress' : 'error',
                'stage' => 'cleanup',
                'percent' => 100,
                'message' => $result['message'],
                'cleanup' => $result,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $result = [
                'success' => false,
                'purchase_order_id' => $po->id,
                'po_number' => $po->po_number,
                'message' => $e->getMessage(),
                'trace' => $this->shortTrace($e),
            ];

            $emit([
                'type' => 'error',
                'stage' => 'cleanup',
                'percent' => 100,
                'message' => 'Cleanup failed: ' . $e->getMessage(),
                'cleanup' => $result,
            ]);

            return $result;
        }
    }

    private function extractProductFromPayload(array $payload): ?array
    {
        $product = $payload['data']['product']
            ?? $payload['product']
            ?? $payload['data']['barcode']['product']
            ?? null;

        return is_array($product) ? $product : null;
    }

    private function productsMatch(array $expected, ?array $actual): bool
    {
        if (!$actual) {
            return false;
        }

        // Product ID is the source of truth for barcode mapping. Names/SKUs/descriptions
        // can legitimately be formatted differently between endpoints, but a different
        // product ID means the physical barcode is mapped to the wrong product.
        return (int) ($expected['id'] ?? 0) > 0
            && (int) ($expected['id'] ?? 0) === (int) ($actual['id'] ?? 0);
    }

    private function internalIntegrityIssue(array $row): ?array
    {
        /** @var ProductBarcode $barcode */
        $barcode = $row['barcode'];
        $expected = $row['expected'];
        $expectedId = (int) ($expected['id'] ?? 0);
        $issues = [];

        if ((int) $barcode->product_id !== $expectedId) {
            $issues[] = 'product_barcodes.product_id does not match purchase_order_items.product_id';
        }

        if ((int) ($row['batch_table_product_id'] ?? 0) !== $expectedId) {
            $issues[] = 'product_batches.product_id does not match purchase_order_items.product_id';
        }

        $batchPageProductId = (int) ($row['batch_page_product']['id'] ?? 0);
        if ($batchPageProductId && $batchPageProductId !== $expectedId) {
            $issues[] = 'Product/batch page product id does not match purchase_order_items.product_id';
        }

        if (empty($issues)) {
            return null;
        }

        return [
            'barcode_id' => $barcode->id,
            'barcode' => $barcode->barcode,
            'batch_id' => $barcode->batch_id,
            'po_item' => $row['po_item'],
            'expected' => $expected,
            'barcode_table_product' => $row['barcode_table_product'],
            'batch_table_product_id' => $row['batch_table_product_id'],
            'batch_page_product' => $row['batch_page_product'],
            'issues' => $issues,
        ];
    }

    private function mismatchRow(array $row, string $source, array $expected, ?array $actual, ?string $message = null, array $rawPayload = []): array
    {
        /** @var ProductBarcode $barcode */
        $barcode = $row['barcode'];

        return [
            'barcode_id' => $barcode->id,
            'barcode' => $barcode->barcode,
            'batch_id' => $barcode->batch_id,
            'source' => $source,
            'message' => $message,
            'expected' => $expected,
            'actual' => $actual,
            'po_item' => $row['po_item'],
            'barcode_table_product' => $row['barcode_table_product'],
            'batch_table_product_id' => $row['batch_table_product_id'],
            'batch_page_product' => $row['batch_page_product'],
            'different_fields' => $this->differentProductFields($expected, $actual),
            'response_success' => $rawPayload['success'] ?? null,
        ];
    }

    private function errorRow(array $row, string $source, string $message, ?\Throwable $e = null): array
    {
        /** @var ProductBarcode $barcode */
        $barcode = $row['barcode'];

        return [
            'barcode_id' => $barcode->id,
            'barcode' => $barcode->barcode,
            'batch_id' => $barcode->batch_id,
            'source' => $source,
            'message' => $message,
            'po_item' => $row['po_item'],
            'expected' => $row['expected'],
            'trace' => $e ? $this->shortTrace($e) : null,
        ];
    }

    private function differentProductFields(array $expected, ?array $actual): array
    {
        $fields = ['id', 'sku', 'name', 'description'];
        $different = [];

        foreach ($fields as $field) {
            $same = $field === 'id'
                ? (int) ($expected[$field] ?? 0) === (int) ($actual[$field] ?? 0)
                : $this->sameText($expected[$field] ?? null, $actual[$field] ?? null);

            if (!$same) {
                $different[$field] = [
                    'expected' => $expected[$field] ?? null,
                    'actual' => $actual[$field] ?? null,
                ];
            }
        }

        return $different;
    }

    private function sameText($a, $b): bool
    {
        return trim((string) ($a ?? '')) === trim((string) ($b ?? ''));
    }

    private function responsePayload($response): array
    {
        $content = method_exists($response, 'getContent') ? $response->getContent() : '';
        $payload = json_decode($content, true);

        return is_array($payload) ? $payload : [
            'success' => false,
            'message' => 'Invalid JSON response',
            'raw' => $content,
        ];
    }

    private function roundDownToTen(int $percent): int
    {
        return max(0, min(100, (int) (floor($percent / 10) * 10)));
    }

    private function shortTrace(\Throwable $e): array
    {
        return collect($e->getTrace())
            ->take(8)
            ->map(function ($trace) {
                return [
                    'file' => $trace['file'] ?? null,
                    'line' => $trace['line'] ?? null,
                    'function' => $trace['function'] ?? null,
                    'class' => $trace['class'] ?? null,
                ];
            })
            ->all();
    }
}
