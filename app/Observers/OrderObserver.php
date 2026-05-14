<?php

namespace App\Observers;

use App\Models\Order;
use App\Models\ReservedProduct;
use App\Services\SalesTargetAggregationService;
use Illuminate\Support\Facades\Log;

class OrderObserver
{
    protected $aggregationService;

    public function __construct(SalesTargetAggregationService $aggregationService)
    {
        $this->aggregationService = $aggregationService;
    }

    public function created(Order $order): void
    {
        $this->aggregationService->syncOrderChange($order);
    }

    public function updated(Order $order): void
    {
        if ($order->wasChanged(['status', 'created_by', 'salesman_id', 'store_id', 'total_amount', 'order_date'])) {
            $this->aggregationService->syncOrderChange($order, $order->getOriginal());
        }

        // Handle inventory reservation transitions
        if ($order->wasChanged('status')) {
            $oldStatus = $order->getOriginal('status');
            $newStatus = $order->status;

            $wasReserved = in_array($oldStatus, Order::RESERVATION_STATUSES);
            $isReserved = in_array($newStatus, Order::RESERVATION_STATUSES);

            if ($wasReserved && !$isReserved) {
                // Order moved OUT of reservation (e.g. cancelled, delivered, confirmed)
                // Note: confirmed is non-reserved because stock is deducted in complete()
                $this->releaseOrderReservations($order);
            } elseif (!$wasReserved && $isReserved) {
                // Order moved INTO reservation (e.g. un-cancelled, or back to pending)
                $this->createOrderReservations($order);
            }
        }
    }

    public function deleted(Order $order): void
    {
        $this->aggregationService->syncOrderChange($order, $order->toArray());

        // Release reservations if the order was in a reserved state
        if ($order->isReservedStatus()) {
            $this->releaseOrderReservations($order);
        }
    }

    public function restored(Order $order): void
    {
        $this->aggregationService->syncOrderChange($order);

        // Re-create reservations if restored into a reserved state
        if ($order->isReservedStatus()) {
            $this->createOrderReservations($order);
        }
    }

    public function forceDeleted(Order $order): void
    {
        $this->aggregationService->syncOrderChange($order, $order->toArray());
        
        if ($order->isReservedStatus()) {
            $this->releaseOrderReservations($order);
        }
    }

    /**
     * Release reservations for all items in the order
     */
    protected function releaseOrderReservations(Order $order)
    {
        Log::info("Releasing reservations for order #{$order->order_number} due to status change or deletion.");
        foreach ($order->items as $item) {
            $reserved = ReservedProduct::where('product_id', $item->product_id)->lockForUpdate()->first();
            if ($reserved) {
                $releaseQty = min((int) $reserved->reserved_inventory, (int) $item->quantity);
                if ($releaseQty > 0) {
                    $reserved->decrement('reserved_inventory', $releaseQty);
                    $reserved->increment('available_inventory', $releaseQty);
                }
            }
        }
    }

    /**
     * Create reservations for all items in the order
     */
    protected function createOrderReservations(Order $order)
    {
        Log::info("Creating reservations for order #{$order->order_number} due to status change or restoration.");
        foreach ($order->items as $item) {
            $reserved = ReservedProduct::where('product_id', $item->product_id)->lockForUpdate()->first();
            if ($reserved) {
                $reserved->increment('reserved_inventory', $item->quantity);
                $reserved->decrement('available_inventory', $item->quantity);
            } else {
                ReservedProduct::create([
                    'product_id' => $item->product_id,
                    'total_inventory' => 0,
                    'reserved_inventory' => $item->quantity,
                    'available_inventory' => -$item->quantity,
                ]);
            }
        }
    }
}