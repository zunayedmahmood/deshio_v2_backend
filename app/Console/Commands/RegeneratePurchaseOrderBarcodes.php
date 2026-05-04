<?php

namespace App\Console\Commands;

use App\Models\ProductBarcode;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RegeneratePurchaseOrderBarcodes extends Command
{
    protected $signature = 'purchase-orders:regenerate-barcodes
        {po : Purchase order ID or PO number}
        {--dry-run : Only show what would be generated, do not create anything}
        {--barcode-type=CODE128 : Barcode type to use for newly generated barcodes}';

    protected $description = 'Backfill missing individual product barcodes for the batches of an already received purchase order without increasing stock.';

    public function handle(): int
    {
        $poIdentifier = (string) $this->argument('po');
        $dryRun = (bool) $this->option('dry-run');
        $barcodeType = (string) ($this->option('barcode-type') ?: 'CODE128');

        $po = PurchaseOrder::with([
            'store',
            'items.product',
            'items.productBatch.barcodes',
            'items.productBatch.barcode',
        ])
            ->where('id', $poIdentifier)
            ->orWhere('po_number', $poIdentifier)
            ->first();

        if (! $po) {
            $this->error("Purchase order not found: {$poIdentifier}");
            return self::FAILURE;
        }

        $this->info("PO: {$po->po_number} | Status: {$po->status} | Store ID: {$po->store_id}");

        if (! in_array($po->status, ['received', 'partially_received'], true)) {
            $this->warn('This PO is not marked as received/partially_received. No stock should be generated unless the PO has received items and batches.');
        }

        $summary = [
            'items_checked' => 0,
            'items_without_batch' => 0,
            'expected_barcodes' => 0,
            'existing_barcodes' => 0,
            'missing_barcodes' => 0,
            'created_barcodes' => 0,
            'primary_assigned' => 0,
        ];

        $rows = [];

        foreach ($po->items as $item) {
            $summary['items_checked']++;

            $batch = $item->productBatch;

            // Fallback for cases where product_batch_id is empty but batch_number was saved on the PO item.
            if (! $batch && $item->batch_number) {
                $batch = ProductBatch::where('batch_number', $item->batch_number)
                    ->where('product_id', $item->product_id)
                    ->where('store_id', $po->store_id)
                    ->first();
            }

            if (! $batch) {
                $summary['items_without_batch']++;
                $rows[] = [
                    $item->id,
                    $item->product_name,
                    'NO BATCH',
                    (int) $item->quantity_received,
                    0,
                    0,
                    'Skipped: PO item has no product batch',
                ];
                continue;
            }

            $expected = (int) ($item->quantity_received > 0 ? $item->quantity_received : $batch->quantity);
            $existing = ProductBarcode::where('batch_id', $batch->id)->count();
            $missing = max(0, $expected - $existing);

            $summary['expected_barcodes'] += $expected;
            $summary['existing_barcodes'] += $existing;
            $summary['missing_barcodes'] += $missing;

            $rows[] = [
                $item->id,
                $item->product_name,
                $batch->batch_number,
                $expected,
                $existing,
                $missing,
                $missing > 0 ? 'Needs backfill' : 'OK',
            ];
        }

        $this->table(
            ['PO Item ID', 'Product', 'Batch', 'Expected', 'Existing', 'Missing', 'Status'],
            $rows
        );

        if ($summary['missing_barcodes'] <= 0) {
            $this->info('No missing barcodes found. Nothing to regenerate.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("Dry run only. Missing barcodes that would be created: {$summary['missing_barcodes']}");
            return self::SUCCESS;
        }

        DB::beginTransaction();

        try {
            foreach ($po->items as $item) {
                $batch = $item->productBatch;

                if (! $batch && $item->batch_number) {
                    $batch = ProductBatch::where('batch_number', $item->batch_number)
                        ->where('product_id', $item->product_id)
                        ->where('store_id', $po->store_id)
                        ->first();
                }

                if (! $batch) {
                    continue;
                }

                $expected = (int) ($item->quantity_received > 0 ? $item->quantity_received : $batch->quantity);
                $existing = ProductBarcode::where('batch_id', $batch->id)->count();
                $missing = max(0, $expected - $existing);

                if ($missing <= 0) {
                    $this->ensurePrimaryBarcode($batch, $summary);
                    continue;
                }

                $store = $batch->store ?: $po->store;
                $initialStatus = $store && $store->is_warehouse ? 'in_warehouse' : 'in_shop';
                $hasAnyPrimary = ProductBarcode::where('batch_id', $batch->id)->where('is_primary', true)->exists();
                $createdForBatch = [];

                for ($i = 0; $i < $missing; $i++) {
                    $barcode = ProductBarcode::create([
                        'product_id' => $item->product_id,
                        'batch_id' => $batch->id,
                        'type' => $barcodeType,
                        'is_primary' => (! $hasAnyPrimary && $existing === 0 && $i === 0),
                        'is_active' => true,
                        'is_defective' => false,
                        'generated_at' => now(),
                        'current_store_id' => $batch->store_id ?: $po->store_id,
                        'current_status' => $initialStatus,
                        'location_updated_at' => now(),
                        'location_metadata' => [
                            'source' => 'purchase_order_barcode_regeneration',
                            'purchase_order_id' => $po->id,
                            'po_number' => $po->po_number,
                            'purchase_order_item_id' => $item->id,
                            'batch_id' => $batch->id,
                            'regenerated_at' => now()->format('Y-m-d H:i:s'),
                        ],
                    ]);

                    $createdForBatch[] = $barcode;
                    $summary['created_barcodes']++;
                }

                if (! empty($createdForBatch)) {
                    $this->line("Created {$missing} barcode(s) for batch {$batch->batch_number}");
                }

                $this->ensurePrimaryBarcode($batch->fresh(), $summary, $createdForBatch[0] ?? null);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed to regenerate PO barcodes: ' . $e->getMessage());
            report($e);
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Created {$summary['created_barcodes']} missing barcode(s).");
        $this->info("Primary barcode assigned/updated for {$summary['primary_assigned']} batch(es).");

        return self::SUCCESS;
    }

    private function ensurePrimaryBarcode(ProductBatch $batch, array &$summary, ?ProductBarcode $preferred = null): void
    {
        $hasValidPrimary = false;

        if ($batch->barcode_id) {
            $hasValidPrimary = ProductBarcode::where('id', $batch->barcode_id)
                ->where('batch_id', $batch->id)
                ->exists();
        }

        if ($hasValidPrimary) {
            return;
        }

        $primary = $preferred ?: ProductBarcode::where('batch_id', $batch->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        if (! $primary) {
            return;
        }

        ProductBarcode::where('batch_id', $batch->id)->update(['is_primary' => false]);
        $primary->update(['is_primary' => true]);
        $batch->update(['barcode_id' => $primary->id]);

        $summary['primary_assigned']++;
    }
}
