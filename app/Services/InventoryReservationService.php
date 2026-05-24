<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBatch;
use App\Models\ReservedProduct;
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
            ->where(function ($q) {
                $q->whereNull('order_items.product_options')
                  ->orWhereNull('order_items.product_options->_barcode_restocked_to_inventory')
                  ->orWhere('order_items.product_options->_barcode_restocked_to_inventory', false);
            })
            ->sum('order_items.quantity');
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
