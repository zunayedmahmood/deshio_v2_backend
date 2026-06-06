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
        $reservationStatuses = array_values(array_unique(array_merge(
            Order::RESERVATION_STATUSES,
            ['confirmed']
        )));

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
                  ->orWhereNotIn('product_barcodes.current_status', [
                      'sold',
                      'with_customer',
                      'defective',
                      'disposed',
                      'vendor_return',
                  ]);
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
        $storeIds = collect($storeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($storeIds->isEmpty()) {
            return;
        }

        $productIds = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $term = trim((string) ($searchTerm ?? ''));

        DB::transaction(function () use ($productIds, $storeIds, $term) {
            $query = ProductBarcode::query()
                ->with(['batch', 'product'])
                ->whereIn('current_store_id', $storeIds->all())
                ->where('is_active', true)
                ->where('is_defective', false)
                ->whereIn('current_status', FloatingBarcodeRelabelService::SELLABLE_STATUSES)
                ->whereDoesntHave('deletedPurchaseOrderLink')
                ->whereDoesntHave('batchDeletedLink')
                ->where(function ($q) use ($storeIds) {
                    $q->whereNull('batch_id')
                      ->orWhereDoesntHave('batch')
                      ->orWhereHas('batch', function ($batchQuery) use ($storeIds) {
                          $batchQuery->whereNotIn('store_id', $storeIds->all())
                              ->orWhere('is_active', false)
                              ->orWhere('availability', false)
                              ->orWhere('quantity', '<=', 0);
                      });
                });

            if ($productIds->isNotEmpty()) {
                $query->whereIn('product_id', $productIds->all());
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

            $barcodes = $query->lockForUpdate()->limit(500)->get();
            $touchedBatchIds = [];
            $touchedProductIds = [];

            foreach ($barcodes as $barcode) {
                $storeId = (int) $barcode->current_store_id;
                if ($storeId <= 0) {
                    continue;
                }

                $sourceBatch = $barcode->batch;
                $targetBatch = null;

                if ($sourceBatch && (int) $sourceBatch->store_id === $storeId) {
                    $targetBatch = $sourceBatch;
                } else {
                    $priceSource = $sourceBatch ?: ProductBatch::where('product_id', $barcode->product_id)
                        ->whereNotNull('sell_price')
                        ->orderByDesc('updated_at')
                        ->first();

                    $targetBatch = ProductBatch::firstOrCreate([
                        'product_id' => $barcode->product_id,
                        'store_id' => $storeId,
                        'batch_number' => 'RTN-RESTORE-P' . (int) $barcode->product_id . '-S' . $storeId,
                    ], [
                        'quantity' => 0,
                        'cost_price' => $priceSource?->cost_price ?? 0,
                        'sell_price' => $priceSource?->sell_price ?? 0,
                        'tax_percentage' => $priceSource?->tax_percentage ?? 0,
                        'manufactured_date' => $priceSource?->manufactured_date,
                        'expiry_date' => $priceSource?->expiry_date,
                        'availability' => true,
                        'is_active' => true,
                        'notes' => 'Auto-created to relink returned barcode stock for social-commerce search.',
                    ]);
                }

                if (!$targetBatch) {
                    continue;
                }

                if ((int) $barcode->batch_id !== (int) $targetBatch->id || (int) $barcode->product_id !== (int) $targetBatch->product_id) {
                    $barcode->forceFill([
                        'batch_id' => $targetBatch->id,
                        'product_id' => $targetBatch->product_id,
                        'current_store_id' => $storeId,
                        'current_status' => $barcode->current_status ?: 'available',
                        'is_active' => true,
                        'is_defective' => false,
                        'location_updated_at' => now(),
                        'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                            'batch_relinked_for_social_commerce' => true,
                            'relinked_batch_id' => $targetBatch->id,
                            'relinked_store_id' => $storeId,
                            'relinked_at' => now()->toISOString(),
                        ]),
                    ])->save();
                }

                $touchedBatchIds[(int) $targetBatch->id] = true;
                $touchedProductIds[(int) $targetBatch->product_id] = true;
            }

            foreach (array_keys($touchedBatchIds) as $batchId) {
                $batch = ProductBatch::lockForUpdate()->find($batchId);
                if (!$batch) {
                    continue;
                }

                $sellableCount = ProductBarcode::where('batch_id', $batch->id)
                    ->where('current_store_id', $batch->store_id)
                    ->where('is_active', true)
                    ->where('is_defective', false)
                    ->whereIn('current_status', FloatingBarcodeRelabelService::SELLABLE_STATUSES)
                    ->whereDoesntHave('deletedPurchaseOrderLink')
                    ->whereDoesntHave('batchDeletedLink')
                    ->count();

                $batch->forceFill([
                    'quantity' => max((int) $batch->quantity, (int) $sellableCount),
                    'availability' => true,
                    'is_active' => true,
                ])->save();
            }

            foreach (array_keys($touchedProductIds) as $productId) {
                $this->syncProduct((int) $productId);
            }

            if (!empty($touchedBatchIds)) {
                Log::info('Sellable returned barcode batch links self-healed for social-commerce', [
                    'batch_ids' => array_keys($touchedBatchIds),
                    'product_ids' => array_keys($touchedProductIds),
                    'store_ids' => $storeIds->all(),
                ]);
            }
        });
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
        return $this->adjustReserved($productId, abs($quantity));
    }

    public function release(int $productId, int $quantity): ReservedProduct
    {
        return $this->adjustReserved($productId, -abs($quantity));
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
