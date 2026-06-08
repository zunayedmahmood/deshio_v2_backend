<?php

use App\Services\InventoryReservationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Repair legacy low-stock / returned-batch inventory drift.
     *
     * This is intentionally a one-way data reconciliation. It rebuilds derived
     * availability from the actual sellable barcode + open order state instead
     * of trying to preserve stale counters created before the latest fixes.
     */
    public function up(): void
    {
        /** @var InventoryReservationService $reservationService */
        $reservationService = app(InventoryReservationService::class);

        // 1) Full-scan old returned/exchanged barcode stock. Normal requests use
        // scoped healing only; the migration is the safe place to repair every
        // legacy batch that was already stuck before deployment.
        $reservationService->reviveSellableBarcodeBackedBatches([], [], null, true);

        // 2) Reconcile batch flags/primary barcode/physical quantity from barcode
        // truth. Replacement/relabel barcode identities are not counted as extra
        // physical stock by the service.
        DB::table('product_batches')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($batches) use ($reservationService) {
                foreach ($batches as $batch) {
                    $reservationService->reconcileBatchStockFromBarcodes((int) $batch->id);
                }
            });

        // 3) Rebuild reserved_products from live not-yet-deducted order items and
        // active batch stock, so product/list, social-commerce assignment, and
        // lookup agree after old auto-assigned/reserved orders.
        DB::table('products')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($reservationService) {
                foreach ($products as $product) {
                    $reservationService->syncProduct((int) $product->id);
                }
            });
    }

    public function down(): void
    {
        // No rollback. This migration repairs derived stock/reservation state from
        // current barcode, batch, and open-order data; reverting would reintroduce
        // stale legacy counters and broken batch links.
    }
};
