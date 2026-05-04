<?php

namespace App\Observers;

use App\Models\ProductDispatch;
use App\Models\ReservedProduct;
use Illuminate\Support\Facades\Log;

class ProductDispatchObserver
{
    /**
     * Handle the ProductDispatch "created" event.
     */
    public function created(ProductDispatch $productDispatch): void
    {
        // Initial reservations are handled when items are added
    }

    /**
     * Handle the ProductDispatch "updated" event.
     */
    public function updated(ProductDispatch $productDispatch): void
    {
        // Handle status changes that affect reservations
        if ($productDispatch->wasChanged('status')) {
            $oldStatus = $productDispatch->getOriginal('status');
            $newStatus = $productDispatch->status;

            Log::info("Dispatch status changed: {$oldStatus} -> {$newStatus}", [
                'dispatch_id' => $productDispatch->id
            ]);

            // If moving to a state where items are no longer reserved but deducted (e.g., approved/dispatched)
            // or if moving to a state where they are released (cancelled/rejected)
            if (in_array($newStatus, ['cancelled', 'rejected'])) {
                $this->releaseReservations($productDispatch);
            }
        }
    }

    /**
     * Release reservations for all items in the dispatch
     */
    protected function releaseReservations(ProductDispatch $dispatch)
    {
        foreach ($dispatch->items as $item) {
            $this->decrementReservation($item->product_id, $item->quantity);
        }
    }

    /**
     * Decrement reserved quantity in the central reserved_products table
     */
    protected function decrementReservation($productId, $quantity)
    {
        $reserved = ReservedProduct::where('product_id', $productId)->first();
        if ($reserved) {
            $reserved->decrement('reserved_inventory', $quantity);
            $reserved->increment('available_inventory', $quantity);
            
            Log::info("Reservation released for product {$productId}: {$quantity} units");
        }
    }
}
