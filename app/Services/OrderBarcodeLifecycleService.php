<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBarcode;
use App\Models\ProductBatch;
use App\Models\ProductMovement;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderBarcodeLifecycleService
{
    /**
     * Order statuses that no longer reserve/own a barcode.
     * Any order outside this list is treated as an open barcode lock.
     */
    public const NON_LOCKING_ORDER_STATUSES = [
        'cancelled',
        'delivered',
        'completed',
        'refunded',
        'returned',
    ];

    public const SAFE_RESELL_STATUSES = ['available', 'in_shop', 'in_warehouse', 'on_display'];

    /**
     * Return open order item rows that are still locking this barcode.
     */
    public function openOrderItemsForBarcode(ProductBarcode $barcode, ?int $ignoreOrderItemId = null)
    {
        return OrderItem::with(['order', 'batch'])
            ->where('product_barcode_id', $barcode->id)
            ->when($ignoreOrderItemId, fn ($q) => $q->where('id', '!=', $ignoreOrderItemId))
            ->whereHas('order', function ($q) {
                $q->whereNotIn('status', self::NON_LOCKING_ORDER_STATUSES);
            })
            ->get();
    }

    /**
     * Release one order item barcode link and optionally restore deducted stock.
     */
    public function releaseOrderItemBarcode(
        OrderItem $item,
        string $reason,
        ?int $targetStoreId = null,
        bool $restoreStock = false,
        bool $clearInventoryDeducted = false,
        ?int $performedBy = null
    ): ?array {
        return DB::transaction(function () use ($item, $reason, $targetStoreId, $restoreStock, $clearInventoryDeducted, $performedBy) {
            $item = OrderItem::with(['order', 'barcode', 'batch'])
                ->lockForUpdate()
                ->find($item->id);

            if (!$item || !$item->product_barcode_id) {
                return null;
            }

            $order = $item->order;
            $barcode = ProductBarcode::with(['product', 'batch', 'currentStore'])
                ->whereKey($item->product_barcode_id)
                ->lockForUpdate()
                ->first();

            if (!$barcode) {
                $item->update(['product_barcode_id' => null]);
                return null;
            }

            $oldStatus = $barcode->current_status;
            $oldStoreId = $barcode->current_store_id;
            $batch = $item->batch ?: $barcode->batch;
            $targetStoreId = $targetStoreId ?: $barcode->current_store_id ?: $batch?->store_id ?: $order?->store_id;
            $targetStatus = $targetStoreId ? 'in_shop' : 'available';
            $stockRestored = false;

            if ($restoreStock && $item->is_inventory_deducted && $batch) {
                $batch->addStock(1);
                $stockRestored = true;
            }

            if (!$barcode->is_defective && !in_array($barcode->current_status, ['defective', 'disposed', 'vendor_return'], true)) {
                // Keep replacement barcode state consistent when a sold unit is returned to stock.
                if (in_array($barcode->current_status, ['sold', 'with_customer'], true) || $barcode->is_replacement) {
                    app(FloatingBarcodeRelabelService::class)->returnBarcodeFromSold($barcode, $order ?: new Order());
                    $barcode->refresh();
                }

                $metadata = array_merge($barcode->location_metadata ?? [], [
                    'released_from_order_item_id' => $item->id,
                    'released_from_order_id' => $order?->id,
                    'released_from_order_number' => $order?->order_number,
                    'release_reason' => $reason,
                    'released_at' => now()->toISOString(),
                    'released_by' => $performedBy ?: auth('api')->id() ?: auth()->id(),
                    'previous_status_before_release' => $oldStatus,
                    'previous_store_before_release' => $oldStoreId,
                    'stock_restored' => $stockRestored,
                ]);

                $barcode->update([
                    'is_active' => true,
                    'current_store_id' => $targetStoreId,
                    'current_status' => $targetStatus,
                    'location_updated_at' => now(),
                    'location_metadata' => $metadata,
                ]);

                $this->safeMovementLog($barcode, $oldStoreId, $targetStoreId, $oldStatus, $targetStatus, $reason, $performedBy);
            }

            $itemUpdate = ['product_barcode_id' => null];
            if ($clearInventoryDeducted && $item->is_inventory_deducted) {
                $itemUpdate['is_inventory_deducted'] = false;
            }
            $item->update($itemUpdate);

            Log::info('Order barcode link released', [
                'barcode_id' => $barcode->id,
                'barcode' => $barcode->barcode,
                'order_id' => $order?->id,
                'order_item_id' => $item->id,
                'reason' => $reason,
                'stock_restored' => $stockRestored,
            ]);

            return [
                'barcode_id' => $barcode->id,
                'barcode' => $barcode->barcode,
                'order_id' => $order?->id,
                'order_number' => $order?->order_number,
                'order_item_id' => $item->id,
                'old_status' => $oldStatus,
                'new_status' => $barcode->fresh()->current_status,
                'stock_restored' => $stockRestored,
            ];
        });
    }

    /**
     * Release all barcode links attached to an order.
     */
    public function releaseAllOrderBarcodes(
        Order $order,
        string $reason,
        bool $restoreStock = false,
        bool $clearInventoryDeducted = false,
        ?int $targetStoreId = null,
        ?int $performedBy = null
    ): array {
        $released = [];
        $order->loadMissing(['items.barcode', 'items.batch']);

        foreach ($order->items as $item) {
            if (!$item->product_barcode_id) {
                continue;
            }

            $result = $this->releaseOrderItemBarcode(
                $item,
                $reason,
                $targetStoreId ?: $order->store_id,
                $restoreStock,
                $clearInventoryDeducted,
                $performedBy
            );

            if ($result) {
                $released[] = $result;
            }
        }

        return $released;
    }

    /**
     * Detach a barcode from open order item rows after a return/exchange has restored the unit.
     */
    public function detachBarcodeFromOrderItems(ProductBarcode $barcode, ?int $orderId, string $reason, array $metadata = []): int
    {
        $query = OrderItem::where('product_barcode_id', $barcode->id)
            ->whereHas('order', function ($q) {
                $q->whereNotIn('status', self::NON_LOCKING_ORDER_STATUSES);
            });

        if ($orderId) {
            $query->where('order_id', $orderId);
        }

        $items = $query->lockForUpdate()->get();
        $count = 0;

        $affectedProductIds = [];

        foreach ($items as $item) {
            $options = $item->product_options ?? [];
            $options['_barcode_restocked_to_inventory'] = true;
            $options['_barcode_restocked_id'] = $barcode->id;
            $options['_barcode_restocked_reason'] = $reason;
            $options['_barcode_restocked_at'] = now()->toISOString();

            $item->update([
                'product_barcode_id' => null,
                'is_inventory_deducted' => false,
                'product_options' => $options,
            ]);
            $affectedProductIds[(int) $item->product_id] = true;
            $count++;
        }

        foreach (array_keys($affectedProductIds) as $productId) {
            app(InventoryReservationService::class)->syncProduct((int) $productId);
        }

        if ($count > 0) {
            $barcode->update([
                'location_metadata' => array_merge($barcode->location_metadata ?? [], $metadata, [
                    'order_barcode_link_detached' => true,
                    'detach_reason' => $reason,
                    'detached_order_id' => $orderId,
                    'detached_at' => now()->toISOString(),
                    'detached_by' => auth('api')->id() ?: auth()->id(),
                ]),
            ]);
        }

        return $count;
    }

    /**
     * Manual admin rescue for barcodes blocked by stale open order item links.
     */
    public function reviveBarcodeFromOrderLock(
        string $barcodeText,
        int $targetStoreId,
        string $targetStatus = 'available',
        bool $restoreStock = true,
        ?int $performedBy = null
    ): array {
        return DB::transaction(function () use ($barcodeText, $targetStoreId, $targetStatus, $restoreStock, $performedBy) {
            $barcode = ProductBarcode::with(['product', 'batch', 'currentStore'])
                ->where('barcode', trim($barcodeText))
                ->lockForUpdate()
                ->first();

            if (!$barcode) {
                throw new \Exception('Barcode not found in system.');
            }

            if ($barcode->is_defective || in_array($barcode->current_status, ['defective', 'disposed', 'vendor_return'], true)) {
                throw new \Exception('This barcode is defective/disposed/vendor-returned. Use the defect/return workflow instead of reviving it.');
            }

            $targetStore = Store::findOrFail($targetStoreId);
            if (!in_array($targetStatus, self::SAFE_RESELL_STATUSES, true)) {
                $targetStatus = 'available';
            }

            $lockedItems = $this->openOrderItemsForBarcode($barcode);
            if ($lockedItems->isEmpty() && !in_array($barcode->current_status, self::SAFE_RESELL_STATUSES, true)) {
                throw new \Exception('No open order lock found for this barcode. It is not safe to revive a sold/customer barcode without a return/exchange record.');
            }

            $oldStatus = $barcode->current_status;
            $oldStoreId = $barcode->current_store_id;
            $stockRestored = false;
            $releasedLinks = [];

            foreach ($lockedItems as $item) {
                $batch = $item->batch ?: $barcode->batch;
                $itemStockRestored = false;

                // Restore at most one physical unit for this barcode, even if duplicate stale links exist.
                if ($restoreStock && !$stockRestored && $item->is_inventory_deducted && $batch) {
                    $batch->addStock(1);
                    $stockRestored = true;
                    $itemStockRestored = true;
                }

                $itemUpdate = ['product_barcode_id' => null];
                if ($item->is_inventory_deducted) {
                    $itemUpdate['is_inventory_deducted'] = false;
                }
                $item->update($itemUpdate);

                $releasedLinks[] = [
                    'order_item_id' => $item->id,
                    'order_id' => $item->order?->id,
                    'order_number' => $item->order?->order_number,
                    'order_status' => $item->order?->status,
                    'stock_restored' => $itemStockRestored,
                ];
            }

            if (in_array($barcode->current_status, ['sold', 'with_customer'], true) || $barcode->is_replacement) {
                $order = $lockedItems->first()?->order ?: new Order();
                app(FloatingBarcodeRelabelService::class)->returnBarcodeFromSold($barcode, $order);
                $barcode->refresh();
            }

            $barcode->update([
                'is_active' => true,
                'current_store_id' => $targetStore->id,
                'current_status' => $targetStatus,
                'location_updated_at' => now(),
                'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                    'manual_order_lock_revive' => true,
                    'revived_at' => now()->toISOString(),
                    'revived_by' => $performedBy ?: auth('api')->id() ?: auth()->id(),
                    'revived_to_store_id' => $targetStore->id,
                    'revived_to_status' => $targetStatus,
                    'previous_status_before_revive' => $oldStatus,
                    'previous_store_before_revive' => $oldStoreId,
                    'released_order_links' => $releasedLinks,
                    'stock_restored' => $stockRestored,
                ]),
            ]);

            $this->safeMovementLog($barcode, $oldStoreId, $targetStore->id, $oldStatus, $targetStatus, 'manual_order_lock_revive', $performedBy);
            app(InventoryReservationService::class)->syncProduct((int) $barcode->product_id);

            $barcode->refresh()->load(['product', 'batch', 'currentStore']);

            return [
                'barcode' => $barcode,
                'target_store' => $targetStore,
                'old_status' => $oldStatus,
                'new_status' => $barcode->current_status,
                'old_store_id' => $oldStoreId,
                'released_links' => $releasedLinks,
                'released_order_links_count' => count($releasedLinks),
                'stock_restored' => $stockRestored,
            ];
        });
    }

    private function safeMovementLog(ProductBarcode $barcode, $oldStoreId, $newStoreId, $oldStatus, $newStatus, string $reason, ?int $performedBy = null): void
    {
        if ((string) $oldStoreId === (string) $newStoreId && (string) $oldStatus === (string) $newStatus) {
            return;
        }

        try {
            ProductMovement::create([
                'product_id' => $barcode->product_id,
                'product_batch_id' => $barcode->batch_id,
                'product_barcode_id' => $barcode->id,
                'from_store_id' => $oldStoreId,
                'to_store_id' => $newStoreId,
                'movement_type' => 'adjustment',
                'quantity' => 1,
                'unit_cost' => $barcode->batch?->cost_price ?? 0,
                'unit_price' => $barcode->batch?->sell_price ?? 0,
                'total_cost' => $barcode->batch?->cost_price ?? 0,
                'total_value' => $barcode->batch?->sell_price ?? 0,
                'movement_date' => now(),
                'reference_type' => $reason,
                'status_before' => $oldStatus,
                'status_after' => $newStatus,
                'notes' => "Barcode lifecycle fix: {$reason}",
                'performed_by' => $performedBy ?: auth('api')->id() ?: auth()->id(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Barcode lifecycle movement log failed', [
                'barcode_id' => $barcode->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
