<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBatch;
use App\Models\ReservedProduct;
use App\Models\ProductBarcode;
use Illuminate\Support\Facades\Log;
use App\Services\FloatingBarcodeRelabelService;
use App\Services\OrderBarcodeLifecycleService;
use Illuminate\Support\Facades\DB;

class InventoryReservationService
{
    public const NON_RESERVED_BARCODE_STATUSES = [
        'sold',
        'with_customer',
        'defective',
        'disposed',
        'vendor_return',
    ];

    /**
     * Every status that can still hold product reservation.
     * Keep this single source aligned with Product List and Free Reserved Product page.
     *
     * @return array<int, string>
     */
    public function liveReservationStatuses(): array
    {
        return array_values(array_unique(array_merge(
            Order::RESERVATION_STATUSES,
            ['confirmed']
        )));
    }

    /**
     * Physical stock used by product list/reservation availability.
     * Keep this aligned with ProductController::productListComputedSelects().
     */
    public function physicalStock(int $productId): int
    {
        return (int) ProductBatch::where('product_id', $productId)
            ->where('is_active', true)
            ->sum('quantity');
    }

    /**
     * Live reserved quantity from orders that still hold stock but have not
     * actually deducted inventory yet.
     */
    public function liveReservedQuantity(int $productId): int
    {
        $reservationStatuses = $this->liveReservationStatuses();

        return (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('product_barcodes', 'product_barcodes.id', '=', 'order_items.product_barcode_id')
            ->where('order_items.product_id', $productId)
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', $reservationStatuses)
            ->where(function ($q) {
                $q->whereNull('orders.order_type')
                  ->orWhere('orders.order_type', '!=', 'preorder');
            })
            ->where(function ($q) {
                $q->whereNull('order_items.is_inventory_deducted')
                  ->orWhere('order_items.is_inventory_deducted', false);
            })
            // If an item already owns a barcode that has moved to the customer/sold
            // lifecycle state, that unit is no longer a pending reservation against
            // sellable stock. This happens in lookup exchange flows where the
            // replacement barcode is marked with_customer even though the exchange
            // order item may still have is_inventory_deducted = false.
            ->where(function ($q) {
                $q->whereNull('order_items.product_barcode_id')
                  ->orWhereNull('product_barcodes.id')
                  ->orWhereNull('product_barcodes.current_status')
                  ->orWhereNotIn('product_barcodes.current_status', self::NON_RESERVED_BARCODE_STATUSES);
            })
            ->where(function ($q) {
                $q->whereNull('order_items.product_options')
                  ->orWhereNull('order_items.product_options->_barcode_restocked_to_inventory')
                  ->orWhere('order_items.product_options->_barcode_restocked_to_inventory', false);
            })
            ->sum('order_items.quantity');
    }

    /**
     * Return product ids that use barcode-level stock lifecycle.
     *
     * Store assignment and social-commerce cart checks should prefer this
     * source when it exists, because return/exchange can restore a barcode to a
     * store even when the original batch row is stale, cross-store, or not the
     * best source of truth for the sellable unit.
     *
     * @return array<int, bool>
     */
    public function barcodeTrackedProductIds(array $productIds): array
    {
        $ids = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return ProductBarcode::query()
            ->whereIn('product_id', $ids->all())
            ->distinct()
            ->pluck('product_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Fully revive every batch that has at least one sellable unit barcode.
     *
     * This is broader than the selected-store social-commerce heal. Inventory >
     * Batch Price Update loads batches by product_id without a status/store
     * filter, so a returned unit from a previously stock-out batch must revive
     * the batch row itself: store_id, quantity, availability, is_active, primary
     * barcode pointer, deleted-link blockers, and product/reserved totals.
     *
     * @param array<int> $productIds Empty means all products matching the other filters.
     * @param array<int> $storeIds Empty means use each barcode's current_store_id / batch store.
     */
    public function reviveSellableBarcodeBackedBatches(array $productIds = [], array $storeIds = [], ?string $searchTerm = null, bool $allowFullScan = false): void
    {
        $productIds = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $storeIds = collect($storeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $term = trim((string) ($searchTerm ?? ''));

        // Avoid a full-table opportunistic repair during normal requests. The
        // deployment migration intentionally passes $allowFullScan=true to repair
        // legacy batches that were already stuck before this code existed.
        if (!$allowFullScan && $productIds->isEmpty() && $storeIds->isEmpty() && $term === '') {
            return;
        }

        $iterations = 0;

        do {
            $touchedThisPass = 0;

            DB::transaction(function () use ($productIds, $storeIds, $term, &$touchedThisPass) {
            $query = ProductBarcode::query()
                ->with(['batch', 'product'])
                ->where('is_active', true)
                ->where('is_defective', false)
                ->whereIn('current_status', FloatingBarcodeRelabelService::SELLABLE_STATUSES)
                ->whereDoesntHave('deletedPurchaseOrderLink')
                ->whereDoesntHave('batchDeletedLink');

            if ($productIds->isNotEmpty()) {
                $query->whereIn('product_id', $productIds->all());
            }

            if ($storeIds->isNotEmpty()) {
                $query->where(function ($locationQuery) use ($storeIds) {
                    $locationQuery->whereIn('current_store_id', $storeIds->all())
                        ->orWhereHas('batch', function ($batchQuery) use ($storeIds) {
                            $batchQuery->whereIn('store_id', $storeIds->all());
                        });
                });
            }

            if ($term !== '') {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('barcode', 'like', $like)
                      ->orWhereHas('product', function ($productQuery) use ($like) {
                          $productQuery->where('name', 'like', $like)
                              ->orWhere('base_name', 'like', $like)
                              ->orWhere('variation_suffix', 'like', $like)
                              ->orWhere('sku', 'like', $like);
                      })
                      ->orWhereHas('batch', function ($batchQuery) use ($like) {
                          $batchQuery->where('batch_number', 'like', $like);
                      });
                });
            }

            // Only touch rows that are already sellable by barcode lifecycle but
            // whose batch row can still block social-commerce/batch-price pages.
            $query->where(function ($q) use ($storeIds) {
                $q->whereNull('batch_id')
                  ->orWhereDoesntHave('batch')
                  ->orWhereHas('batch', function ($batchQuery) use ($storeIds) {
                      $batchQuery->whereNull('store_id')
                          ->orWhere('is_active', false)
                          ->orWhere('availability', false)
                          ->orWhere('quantity', '<=', 0);

                      if ($storeIds->isNotEmpty()) {
                          $batchQuery->orWhereNotIn('store_id', $storeIds->all());
                      }
                  })
                  ->orWhere(function ($locationMismatch) use ($storeIds) {
                      $locationMismatch->whereNotNull('current_store_id')
                          ->whereHas('batch', function ($batchQuery) use ($storeIds) {
                              $batchQuery->whereNotNull('store_id')
                                  ->whereColumn('product_batches.store_id', '!=', 'product_barcodes.current_store_id');

                              if ($storeIds->isNotEmpty()) {
                                  $batchQuery->whereIn('product_batches.store_id', $storeIds->all());
                              }
                          });
                  });
            });

            $barcodes = $query->lockForUpdate()->limit(1000)->get();
            $touchedBatchIds = [];
            $touchedProductIds = [];

            foreach ($barcodes as $barcode) {
                $sourceBatch = $barcode->batch;
                $productId = (int) ($barcode->product_id ?: $sourceBatch?->product_id);
                $storeId = (int) ($barcode->current_store_id ?: $sourceBatch?->store_id);

                if ($productId <= 0 || $storeId <= 0) {
                    continue;
                }

                if ($storeIds->isNotEmpty() && !$storeIds->contains($storeId)) {
                    continue;
                }

                $targetBatch = null;

                if ($sourceBatch && (int) $sourceBatch->product_id === $productId && ((int) $sourceBatch->store_id === $storeId || empty($sourceBatch->store_id))) {
                    $targetBatch = ProductBatch::lockForUpdate()->find($sourceBatch->id);
                }

                if (!$targetBatch) {
                    $priceSource = $sourceBatch ?: ProductBatch::where('product_id', $productId)
                        ->whereNotNull('sell_price')
                        ->orderByDesc('updated_at')
                        ->first();

                    $targetBatch = ProductBatch::firstOrCreate([
                        'product_id' => $productId,
                        'store_id' => $storeId,
                        'batch_number' => 'RTN-RESTORE-P' . $productId . '-S' . $storeId,
                    ], [
                        'quantity' => 0,
                        'cost_price' => $priceSource?->cost_price ?? 0,
                        'sell_price' => $priceSource?->sell_price ?? 0,
                        'tax_percentage' => $priceSource?->tax_percentage ?? 0,
                        'manufactured_date' => $priceSource?->manufactured_date,
                        'expiry_date' => $priceSource?->expiry_date,
                        'availability' => true,
                        'is_active' => true,
                        'notes' => 'Auto-created/reused to revive returned barcode stock for social-commerce and batch price update.',
                    ]);
                }

                \App\Models\DeletedPurchaseOrderBarcode::where('product_barcode_id', $barcode->id)->delete();
                \App\Models\BatchDeletedBarcode::where('product_barcode_id', $barcode->id)->delete();

                $targetBatch->forceFill([
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'availability' => true,
                    'is_active' => true,
                    'barcode_id' => $targetBatch->barcode_id ?: $barcode->id,
                    'notes' => $this->appendBatchNote(
                        (string) ($targetBatch->notes ?? ''),
                        'Returned barcode revived this batch for social-commerce and batch-price-update.'
                    ),
                ])->save();

                $barcode->forceFill([
                    'product_id' => $productId,
                    'batch_id' => $targetBatch->id,
                    'current_store_id' => $storeId,
                    'current_status' => 'available',
                    'is_active' => true,
                    'is_defective' => false,
                    'location_updated_at' => now(),
                    'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                        'batch_fully_revived' => true,
                        'revived_batch_id' => $targetBatch->id,
                        'revived_store_id' => $storeId,
                        'revived_for_social_and_batch_price_at' => now()->toISOString(),
                    ]),
                ])->save();

                $touchedThisPass++;
                $touchedBatchIds[(int) $targetBatch->id] = true;
                $touchedProductIds[(int) $productId] = true;
            }

            foreach (array_keys($touchedBatchIds) as $batchId) {
                $this->reconcileBatchStockFromBarcodes((int) $batchId);
            }

            foreach (array_keys($touchedProductIds) as $productId) {
                $this->syncProduct((int) $productId);
            }

            if (!empty($touchedBatchIds)) {
                Log::info('Sellable barcode-backed batches fully revived', [
                    'batch_ids' => array_keys($touchedBatchIds),
                    'product_ids' => array_keys($touchedProductIds),
                    'store_ids' => $storeIds->all(),
                ]);
            }
            });

            $iterations++;
        } while ($allowFullScan && $touchedThisPass > 0 && $iterations < 100);
    }

    public function reconcileBatchStockFromBarcodes(int $batchId): void
    {
        $batch = ProductBatch::lockForUpdate()->find($batchId);
        if (!$batch) {
            return;
        }

        $sellableQuery = ProductBarcode::where('batch_id', $batch->id)
            ->where('product_id', $batch->product_id)
            ->where('current_store_id', $batch->store_id)
            ->where('is_active', true)
            ->where('is_defective', false)
            ->whereIn('current_status', FloatingBarcodeRelabelService::SELLABLE_STATUSES)
            ->whereDoesntHave('deletedPurchaseOrderLink')
            ->whereDoesntHave('batchDeletedLink');

        $hasBarcodeIdentities = ProductBarcode::where('batch_id', $batch->id)
            ->where('product_id', $batch->product_id)
            ->exists();

        $sellableBarcodes = (clone $sellableQuery)->get(['id', 'is_primary', 'is_replacement', 'replacement_status']);
        $sellableCount = (int) $sellableBarcodes->count();
        $nonReplacementSellableCount = (int) $sellableBarcodes
            ->filter(fn ($barcode) => empty($barcode->is_replacement))
            ->count();

        // Floating replacement/relabel barcodes are extra scan identities for an
        // existing physical unit. They must not inflate product_batches.quantity.
        // If a previously stock-out batch has only a replacement identity left,
        // keep at least one physical unit so the returned item can be assigned.
        $reconciledPhysicalQuantity = $hasBarcodeIdentities
            ? ($nonReplacementSellableCount > 0 ? $nonReplacementSellableCount : ($sellableCount > 0 ? 1 : 0))
            : max(0, (int) $batch->quantity);

        $primaryBarcodeId = (int) ($sellableBarcodes
            ->sort(function ($left, $right) {
                $primaryCompare = ((int) $right->is_primary) <=> ((int) $left->is_primary);
                return $primaryCompare !== 0 ? $primaryCompare : ((int) $left->id <=> (int) $right->id);
            })
            ->first()?->id ?: 0);

        $batch->forceFill([
            'quantity' => $reconciledPhysicalQuantity,
            'availability' => $hasBarcodeIdentities ? $sellableCount > 0 : $reconciledPhysicalQuantity > 0,
            'is_active' => $hasBarcodeIdentities ? $sellableCount > 0 : (bool) $batch->is_active,
            'barcode_id' => $primaryBarcodeId ?: $batch->barcode_id,
        ])->save();
    }

    private function appendBatchNote(string $existing, string $note): string
    {
        $line = '[' . now()->format('Y-m-d H:i:s') . '] ' . $note;
        if (str_contains($existing, $note)) {
            return $existing;
        }
        return trim($existing) === '' ? $line : trim($existing) . "
" . $line;
    }


    /**
     * Force a returned/exchanged unit barcode back into a fully sellable state
     * for one store and one live batch. This is intentionally stricter than
     * normal barcode status changes because social-commerce search/cart logic is
     * batch-driven while POS can be barcode-driven.
     */
    public function restoreReturnedBarcodeToSellableBatch(
        ProductBarcode $barcode,
        int $storeId,
        ?ProductBatch $preferredBatch = null,
        array $metadata = []
    ): ?ProductBatch {
        if ($storeId <= 0) {
            return null;
        }

        return DB::transaction(function () use ($barcode, $storeId, $preferredBatch, $metadata) {
            $barcode = ProductBarcode::with(['batch'])
                ->lockForUpdate()
                ->find($barcode->id);

            if (!$barcode) {
                return null;
            }

            $preferredBatch = $preferredBatch
                ? ProductBatch::lockForUpdate()->find($preferredBatch->id)
                : null;

            $sourceBatch = $barcode->batch;
            $productId = (int) ($preferredBatch?->product_id ?: $sourceBatch?->product_id ?: $barcode->product_id);
            if ($productId <= 0) {
                return null;
            }

            if ($preferredBatch && (int) $preferredBatch->product_id === $productId && (int) $preferredBatch->store_id === $storeId) {
                $targetBatch = $preferredBatch;
            } elseif ($sourceBatch && (int) $sourceBatch->product_id === $productId && (int) $sourceBatch->store_id === $storeId) {
                $targetBatch = $sourceBatch;
            } else {
                $priceSource = $preferredBatch ?: $sourceBatch ?: ProductBatch::where('product_id', $productId)
                    ->whereNotNull('sell_price')
                    ->orderByDesc('updated_at')
                    ->first();

                $targetBatch = ProductBatch::firstOrCreate([
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'batch_number' => 'RTN-RESTORE-P' . $productId . '-S' . $storeId,
                ], [
                    'quantity' => 0,
                    'cost_price' => $priceSource?->cost_price ?? 0,
                    'sell_price' => $priceSource?->sell_price ?? 0,
                    'tax_percentage' => $priceSource?->tax_percentage ?? 0,
                    'manufactured_date' => $priceSource?->manufactured_date,
                    'expiry_date' => $priceSource?->expiry_date,
                    'availability' => true,
                    'is_active' => true,
                    'notes' => 'Auto-created/reused to restore returned barcode stock for POS and social-commerce.',
                ]);
            }

            \App\Models\DeletedPurchaseOrderBarcode::where('product_barcode_id', $barcode->id)->delete();
            \App\Models\BatchDeletedBarcode::where('product_barcode_id', $barcode->id)->delete();

            $targetBatch->forceFill([
                'availability' => true,
                'is_active' => true,
            ])->save();

            $barcode->forceFill([
                'product_id' => $targetBatch->product_id,
                'batch_id' => $targetBatch->id,
                'current_store_id' => $storeId,
                'current_status' => 'available',
                'is_active' => true,
                'is_defective' => false,
                'location_updated_at' => now(),
                'location_metadata' => array_merge($barcode->location_metadata ?? [], $metadata, [
                    'returned_barcode_full_restore' => true,
                    'restored_batch_id' => $targetBatch->id,
                    'restored_store_id' => $storeId,
                    'restored_for_social_commerce_at' => now()->toISOString(),
                ]),
            ])->save();

            if (!$targetBatch->barcode_id) {
                $targetBatch->forceFill(['barcode_id' => $barcode->id])->save();
            }

            $this->reconcileBatchStockFromBarcodes((int) $targetBatch->id);
            $targetBatch->refresh();

            if ((int) $targetBatch->quantity < 1) {
                $targetBatch->forceFill([
                    'quantity' => 1,
                    'availability' => true,
                    'is_active' => true,
                ])->save();
            }

            $this->syncProduct((int) $targetBatch->product_id);

            return $targetBatch;
        });
    }


    /**
     * Self-heal returned/exchanged barcodes that are already sellable by lifecycle,
     * but whose batch row is stale after a stock-out return.
     *
     * Social-commerce search/cart availability is batch-driven, while POS barcode
     * scanning is barcode-driven. If a returned barcode is active/available at a
     * store but its batch is null, inactive, unavailable, zero quantity, or still
     * pointing at another store, create/reuse a small restored-stock batch in the
     * barcode's current store and relink the barcode to it.
     *
     * @param array<int> $productIds Empty means all products in the selected store.
     * @param array<int> $storeIds
     */
    public function healSellableBarcodeBatchLinksForStore(array $productIds, array $storeIds, ?string $searchTerm = null): void
    {
        $this->reviveSellableBarcodeBackedBatches($productIds, $storeIds, $searchTerm);
    }

    /**
     * Count sellable barcodes by their actual store location.
     *
     * A barcode is treated as sellable from a store when:
     * - it is active and not defective,
     * - it has one of the configured sellable lifecycle statuses,
     * - it is not tied to deleted PO/batch rescue records,
     * - it is not still attached to another open order, and
     * - either current_store_id points to the store, or current_store_id is null
     *   and the batch belongs to the store.
     *
     * We intentionally do not rely on reserved_products here. This method is
     * the self-healing source used by social-commerce availability after lookup
     * return/exchange.
     *
     * @return array<int, array<int, int>> store_id => [product_id => qty]
     */
    public function sellableBarcodeQuantitiesByStore(array $productIds, array $storeIds): array
    {
        $productIds = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $storeIds = collect($storeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty() || $storeIds->isEmpty()) {
            return [];
        }

        $storeExpression = 'COALESCE(product_barcodes.current_store_id, product_batches.store_id)';

        $rows = ProductBarcode::query()
            ->join('product_batches', 'product_batches.id', '=', 'product_barcodes.batch_id')
            ->whereIn('product_barcodes.product_id', $productIds->all())
            ->whereIn(DB::raw($storeExpression), $storeIds->all())
            ->where('product_barcodes.is_active', true)
            ->where('product_barcodes.is_defective', false)
            ->whereIn('product_barcodes.current_status', FloatingBarcodeRelabelService::SELLABLE_STATUSES)
            ->where('product_batches.is_active', true)
            ->where('product_batches.quantity', '>', 0)
            // Match the barcode packing validator: a current_store_id can override
            // a missing batch store, but it must not contradict an existing batch store.
            ->where(function ($q) {
                $q->whereNull('product_barcodes.current_store_id')
                  ->orWhereNull('product_batches.store_id')
                  ->orWhereColumn('product_barcodes.current_store_id', 'product_batches.store_id');
            })
            ->whereDoesntHave('deletedPurchaseOrderLink')
            ->whereDoesntHave('batchDeletedLink')
            ->whereDoesntHave('orderItems', function ($q) {
                $q->whereHas('order', function ($orderQuery) {
                    $orderQuery->whereNotIn('status', OrderBarcodeLifecycleService::NON_LOCKING_ORDER_STATUSES);
                });
            })
            ->select(
                'product_barcodes.product_id',
                'product_barcodes.batch_id',
                DB::raw($storeExpression . ' as sellable_store_id'),
                DB::raw('product_batches.quantity as batch_quantity'),
                DB::raw('COUNT(*) as barcode_quantity')
            )
            ->groupBy(
                'product_barcodes.product_id',
                'product_barcodes.batch_id',
                'product_batches.quantity',
                DB::raw($storeExpression)
            )
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $storeId = (int) $row->sellable_store_id;
            $productId = (int) $row->product_id;
            $batchCapacity = max(0, (int) $row->batch_quantity);
            $barcodeQuantity = max(0, (int) $row->barcode_quantity);

            // Do not let stale extra barcode identities advertise more stock than
            // the physical batch can actually release.
            $counts[$storeId][$productId] = ($counts[$storeId][$productId] ?? 0) + min($barcodeQuantity, $batchCapacity);
        }

        return $counts;
    }

    /**
     * Rebuild the reserved_products row for one product.
     */
    public function syncProduct(int $productId, bool $recalculateReservedFromOrders = true): ReservedProduct
    {
        return DB::transaction(function () use ($productId, $recalculateReservedFromOrders) {
            $row = ReservedProduct::where('product_id', $productId)->lockForUpdate()->first();

            if (!$row) {
                $row = new ReservedProduct(['product_id' => $productId]);
            }

            $total = $this->physicalStock($productId);
            $reserved = $recalculateReservedFromOrders
                ? $this->liveReservedQuantity($productId)
                : (int) ($row->reserved_inventory ?? 0);

            $reserved = max(0, $reserved);

            $row->total_inventory = $total;
            $row->reserved_inventory = $reserved;
            $row->available_inventory = max(0, $total - $reserved);
            $row->save();

            return $row;
        });
    }

    public function reserve(int $productId, int $quantity): ReservedProduct
    {
        // Avoid accumulated drift from duplicate observer paths. The database
        // order_items rows are the source of truth for reservation quantity.
        return $this->syncProduct($productId);
    }

    public function release(int $productId, int $quantity): ReservedProduct
    {
        // Rebuild from live order_items instead of subtracting a guessed delta.
        // This fixes cases where Product List showed 3 reserved but only 1 live
        // order line actually existed for the product.
        return $this->syncProduct($productId);
    }

    /**
     * Apply a safe delta to reserved inventory. Negative values are clamped so
     * reserved_inventory can never become negative, and available_inventory is
     * always rebuilt from total - reserved.
     */
    public function adjustReserved(int $productId, int $delta): ReservedProduct
    {
        return DB::transaction(function () use ($productId, $delta) {
            $row = ReservedProduct::where('product_id', $productId)->lockForUpdate()->first();

            if (!$row) {
                $row = new ReservedProduct(['product_id' => $productId]);
                $row->reserved_inventory = 0;
            }

            $total = $this->physicalStock($productId);
            $reserved = max(0, (int) ($row->reserved_inventory ?? 0) + $delta);

            $row->total_inventory = $total;
            $row->reserved_inventory = $reserved;
            $row->available_inventory = max(0, $total - $reserved);
            $row->save();

            return $row;
        });
    }
}
