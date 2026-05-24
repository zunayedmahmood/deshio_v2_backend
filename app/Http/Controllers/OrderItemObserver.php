<?php

namespace App\Observers;

use App\Models\OrderItem;
use App\Models\ReservedProduct;
use Illuminate\Support\Facades\Log;

class OrderItemObserver
{
    /**
     * Handle the OrderItem "created" event.
     */
    public function created(OrderItem $orderItem): void
    {
        $order = $orderItem->order;
        $reservationStatuses = ['pending_assignment', 'pending', 'assigned_to_store', 'picking', 'processing', 'ready_for_pickup'];
        if ($order && in_array($order->status, $reservationStatuses)) {
            if ($order->order_type === 'preorder') { // Preorders might not have batch/stock yet
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
        $reservationStatuses = ['pending_assignment', 'pending', 'assigned_to_store', 'picking', 'processing', 'ready_for_pickup'];
        if ($order && in_array($order->status, $reservationStatuses)) {
            if ($order->order_type === 'preorder') {
                return;
            }


            if ($orderItem->isDirty('quantity')) {
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
        $reservationStatuses = ['pending_assignment', 'pending', 'assigned_to_store', 'picking', 'processing', 'ready_for_pickup'];
        // Check if the order still exists (it might have been deleted too)
        if ($order && in_array($order->status, $reservationStatuses)) {
            if ($order->order_type === 'preorder') {
                return;
            }
            $this->decrementReservation($orderItem->product_id, $orderItem->quantity);
        }
    }

    private function incrementReservation($productId, $quantity): void
    {
        if ($reservedRecord = ReservedProduct::where('product_id', $productId)->first()) {
            $reservedRecord->increment('reserved_inventory', $quantity);
            $reservedRecord->decrement('available_inventory', $quantity);
            Log::info("Incremented reservation for product {$productId} by {$quantity}");
        } else {
            ReservedProduct::create([
                'product_id' => $productId,
                'total_inventory' => 0, // Will be corrected by ProductBatchObserver if batches exist
                'reserved_inventory' => $quantity,
                'available_inventory' => -$quantity,
            ]);
            Log::info("Created new reservation record for product {$productId} with {$quantity} reserved");
        }
    }

    private function decrementReservation($productId, $quantity): void
    {
        if ($reservedRecord = ReservedProduct::where('product_id', $productId)->first()) {
            $reservedRecord->decrement('reserved_inventory', $quantity);
            $reservedRecord->increment('available_inventory', $quantity);
            Log::info("Decremented reservation for product {$productId} by {$quantity}");
        }
    }
}
