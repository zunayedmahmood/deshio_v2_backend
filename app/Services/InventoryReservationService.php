<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBatch;
use App\Models\ReservedProduct;
use App\Models\ProductBarcode;
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
