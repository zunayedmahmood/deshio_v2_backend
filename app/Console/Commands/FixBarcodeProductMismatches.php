<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ProductBarcode;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;

class FixBarcodeProductMismatches extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'barcodes:fix-mismatches {--dry-run : Only show what would be changed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Identify and fix product_barcodes where product_id does not match its parent batch product_id';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Auditing barcode data integrity...');
        $dryRun = $this->option('dry-run');

        // Find mismatches where batch exists but product_id differs
        $mismatches = ProductBarcode::whereHas('batch', function ($query) {
            $query->whereColumn('product_batches.product_id', '!=', 'product_barcodes.product_id');
        })->with(['product', 'batch.product'])->get();

        if ($mismatches->isEmpty()) {
            $this->info('✅ No barcode-to-product mismatches found. Your data is consistent!');
            return 0;
        }

        $this->warn("🚨 Found {$mismatches->count()} mismatched barcode(s)!");

        if ($dryRun) {
            $this->info('--- DRY RUN MODE ---');
        }

        $fixedCount = 0;

        foreach ($mismatches as $barcode) {
            $oldProductId = $barcode->product_id;
            $correctProductId = $barcode->batch->product_id;
            
            $oldSku = $barcode->product->sku ?? 'Unknown';
            $correctSku = $barcode->batch->product->sku ?? 'Unknown';

            $this->line("Barcode: <info>{$barcode->barcode}</info>");
            $this->line("  - Current: ID {$oldProductId} (SKU: {$oldSku})");
            $this->line("  - Correct: ID {$correctProductId} (SKU: {$correctSku})");

            if (!$dryRun) {
                $barcode->update([
                    'product_id' => $correctProductId,
                    'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                        'mismatch_fixed_at' => now()->toISOString(),
                        'previous_product_id' => $oldProductId,
                        'fix_reason' => 'data_integrity_audit_sync'
                    ])
                ]);
                $this->info('  ✅ Fixed');
                $fixedCount++;
            } else {
                $this->comment('  (Would fix)');
            }
        }

        if ($dryRun) {
            $this->info("Audit complete. Run without --dry-run to fix {$mismatches->count()} records.");
        } else {
            $this->info("✅ Data integrity restoration complete. Fixed {$fixedCount} records.");
        }

        return 0;
    }
}
