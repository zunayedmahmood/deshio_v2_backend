<?php

namespace App\Observers;

use App\Models\ProductBatch;
use App\Services\InventoryReservationService;

class ProductBatchObserver
{
    public function saved(ProductBatch $batch): void
    {
        app(InventoryReservationService::class)->syncProduct($batch->product_id);
    }

    public function deleted(ProductBatch $batch): void
    {
        app(InventoryReservationService::class)->syncProduct($batch->product_id);
    }
}
