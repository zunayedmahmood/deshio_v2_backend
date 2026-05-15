<?php

namespace App\Observers;

use App\Models\OrderItem;
use App\Services\InventoryReservationService;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class OrderItemObserver
{
    /**
     * Handle the OrderItem "created" event.
     */
    public function created(OrderItem $orderItem): void
    {
        $order = $orderItem->order;
        if ($order && $this->itemShouldAffectReservation($order, $orderItem)) {
            $this->incrementReservation($orderItem->product_id, $orderItem->quantity);
        }
    }

    /**
     * Handle the OrderItem "updated" event.
     */
    public function updated(OrderItem $orderItem): void
    {
        $order = $orderItem->order;
        if (!$order || $order->order_type === 'preorder' || !$this->orderCanHoldReservations($order)) {
            return;
        }

        // If item is already deducted, it should no longer have an active reservation.
        // This also handles edited confirmed orders where a newly added line is later completed.
        if ($orderItem->isDirty('is_inventory_deducted') && $orderItem->is_inventory_deducted) {
            $oldQty = $orderItem->getOriginal('quantity');
            $this->decrementReservation($orderItem->product_id, $oldQty);
            return;
        }

        // If it's already deducted, skip any other updates (no reservation to change).
        if ($orderItem->is_inventory_deducted) {
            return;
        }

        // Handle product swap.
        if ($orderItem->isDirty('product_id')) {
            $oldProductId = $orderItem->getOriginal('product_id');
            $oldQty = $orderItem->getOriginal('quantity');
            $newProductId = $orderItem->product_id;
            $newQty = $orderItem->quantity;

            $this->decrementReservation($oldProductId, $oldQty);
            $this->incrementReservation($newProductId, $newQty);
        }
        // Handle quantity change for the same product.
        else if ($orderItem->isDirty('quantity')) {
            $oldQty = $orderItem->getOriginal('quantity');
            $newQty = $orderItem->quantity;
            $diff = $newQty - $oldQty;

            if ($diff > 0) {
                $this->incrementReservation($orderItem->product_id, $diff);
            } else if ($diff < 0) {
                $this->decrementReservation($orderItem->product_id, abs($diff));
            }
        }
    }

    /**
     * Handle the OrderItem "deleted" event.
     */
    public function deleted(OrderItem $orderItem): void
    {
        $order = $orderItem->order;
        if ($order && $this->itemShouldAffectReservation($order, $orderItem)) {
            $this->decrementReservation($orderItem->product_id, $orderItem->quantity);
        }
    }

    private function itemShouldAffectReservation(Order $order, OrderItem $orderItem): bool
    {
        if ($order->order_type === 'preorder' || $orderItem->is_inventory_deducted) {
            return false;
        }

        return $this->orderCanHoldReservations($order);
    }

    private function orderCanHoldReservations(Order $order): bool
    {
        // Confirmed orders normally have deducted inventory, but edited confirmed
        // orders can contain newly added, not-yet-deducted lines that must stay reserved.
        return $order->isReservedStatus() || $order->status === 'confirmed';
    }

    private function incrementReservation($productId, $quantity): void
    {
        app(InventoryReservationService::class)->reserve((int) $productId, (int) $quantity);
        Log::info("Incremented reservation for product {$productId} by {$quantity}");
    }

    private function decrementReservation($productId, $quantity): void
    {
        app(InventoryReservationService::class)->release((int) $productId, (int) $quantity);
        Log::info("Released reservation for product {$productId} by up to {$quantity}");
    }
}
