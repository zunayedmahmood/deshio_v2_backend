<?php

namespace App\Observers;

use App\Models\OrderItem;
use App\Models\ReservedProduct;
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
        if ($order && $order->isReservedStatus() && !$orderItem->is_inventory_deducted) {
            if ($order->order_type === 'preorder') {
                return;
            }
            $this->incrementReservation($orderItem->product_id, $orderItem->quantity);
        }
    }

    /**
     * Handle the OrderItem "updated" event.
     */
    public function updated(OrderItem $orderItem): void
    {
        $order = $orderItem->order;
        if ($order && $order->isReservedStatus()) {
            if ($order->order_type === 'preorder') {
                return;
            }

            // If item is already deducted, it shouldn't have an active reservation.
            // If it just became deducted, we should remove the reservation.
            if ($orderItem->isDirty('is_inventory_deducted') && $orderItem->is_inventory_deducted) {
                $oldQty = $orderItem->getOriginal('quantity');
                $this->decrementReservation($orderItem->product_id, $oldQty);
                return;
            }

            // If it's already deducted, skip any other updates (no reservation to change)
            if ($orderItem->is_inventory_deducted) {
                return;
            }

            // Handle product swap
            if ($orderItem->isDirty('product_id')) {
                $oldProductId = $orderItem->getOriginal('product_id');
                $oldQty = $orderItem->getOriginal('quantity');
                $newProductId = $orderItem->product_id;
                $newQty = $orderItem->quantity;

                $this->decrementReservation($oldProductId, $oldQty);
                $this->incrementReservation($newProductId, $newQty);
            } 
            // Handle quantity change for the same product
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
    }

    /**
     * Handle the OrderItem "deleted" event.
     */
    public function deleted(OrderItem $orderItem): void
    {
        $order = $orderItem->order;
        if ($order && $order->isReservedStatus() && !$orderItem->is_inventory_deducted) {
            if ($order->order_type === 'preorder') {
                return;
            }
            $this->decrementReservation($orderItem->product_id, $orderItem->quantity);
        }
    }

    private function incrementReservation($productId, $quantity): void
    {
        $reservedRecord = ReservedProduct::where('product_id', $productId)->lockForUpdate()->first();
        
        if ($reservedRecord) {
            $reservedRecord->increment('reserved_inventory', $quantity);
            $reservedRecord->decrement('available_inventory', $quantity);
            Log::info("Incremented reservation for product {$productId} by {$quantity}");
        } else {
            ReservedProduct::create([
                'product_id' => $productId,
                'total_inventory' => 0,
                'reserved_inventory' => $quantity,
                'available_inventory' => -$quantity,
            ]);
            Log::info("Created new reservation record for product {$productId} with {$quantity} reserved");
        }
    }

    private function decrementReservation($productId, $quantity): void
    {
        $reservedRecord = ReservedProduct::where('product_id', $productId)->lockForUpdate()->first();
        if ($reservedRecord) {
            $reservedRecord->decrement('reserved_inventory', $quantity);
            $reservedRecord->increment('available_inventory', $quantity);
            Log::info("Decremented reservation for product {$productId} by {$quantity}");
        }
    }
}
