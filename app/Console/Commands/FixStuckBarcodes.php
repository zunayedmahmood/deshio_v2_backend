<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ProductBarcode;
use App\Models\ProductDispatch;
use App\Models\ProductBatch;
use App\Traits\DatabaseAgnosticSearch;

class FixStuckBarcodes extends Command
{
    use DatabaseAgnosticSearch;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'barcodes:fix-stuck 
                           {--dry-run : Show what would be fixed without actually making changes}
                           {--dispatch-id= : Fix barcodes for a specific dispatch ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix barcodes that are stuck in transit status after dispatch delivery';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Checking for stuck barcodes...');
        
        $dryRun = $this->option('dry-run');
        $dispatchId = $this->option('dispatch-id');
        
        // Find barcodes stuck in transit status
        $stuckBarcodesQuery = ProductBarcode::where('current_status', 'in_transit');
        
        if ($dispatchId) {
            // If specific dispatch ID is provided, focus on that dispatch
            $dispatch = ProductDispatch::find($dispatchId);
            if (!$dispatch) {
                $this->error("Dispatch with ID {$dispatchId} not found!");
                return 1;
            }
            
            $this->info("Focusing on dispatch: {$dispatch->dispatch_number} (Status: {$dispatch->status})");
            
            // Get barcodes that were part of this dispatch
            $dispatchBarcodeIds = [];
            foreach ($dispatch->items as $item) {
                foreach ($item->scannedBarcodes as $barcode) {
                    $dispatchBarcodeIds[] = $barcode->id;
                }
            }
            
            if (!empty($dispatchBarcodeIds)) {
                $stuckBarcodesQuery->whereIn('id', $dispatchBarcodeIds);
            } else {
                $this->warn("No scanned barcodes found for this dispatch. Checking by batch association...");
                $batchIds = $dispatch->items->pluck('product_batch_id')->toArray();
                $stuckBarcodesQuery->whereIn('batch_id', $batchIds);
            }
        }
        
        $stuckBarcodes = $stuckBarcodesQuery->get();
        
        if ($stuckBarcodes->count() === 0) {
            $this->info('✅ No stuck barcodes found!');
            return 0;
        }
        
        $this->warn("Found {$stuckBarcodes->count()} barcodes stuck in transit status:");
        
        $fixed = 0;
        $errors = 0;
        
        foreach ($stuckBarcodes as $barcode) {
            $batch = $barcode->batch;
            $metadata = $barcode->location_metadata ?? [];
            $dispatchNumber = $metadata['dispatch_number'] ?? 'Unknown';
            
            $this->line("- Barcode: {$barcode->barcode} | Dispatch: {$dispatchNumber} | Current Store: {$barcode->current_store_id} | Batch Store: " . ($batch->store_id ?: 'NULL'));
            
            if (!$dryRun) {
                try {
                    // Try to find the correct destination store and batch
                    $destinationStoreId = $metadata['destination_store_id'] ?? $batch->store_id;
                    $destinationBatchId = $metadata['destination_batch_id'] ?? null;
                    
                    // If we don't have destination batch info, try to find it
                    if (!$destinationBatchId && isset($metadata['dispatch_number'])) {
                        $relatedDispatch = ProductDispatch::where('dispatch_number', $metadata['dispatch_number'])->first();
                        if ($relatedDispatch && $relatedDispatch->status === 'delivered') {
                            // Find destination batch created for this product
                            $batchQuery = ProductBatch::query()
                                ->where('store_id', $relatedDispatch->destination_store_id)
                                ->where('product_id', $barcode->product_id);
                            $this->whereLike($batchQuery, 'batch_number', 'DST-' . $metadata['dispatch_number'], 'end');
                            $destinationBatch = $batchQuery->first();
                            
                            if ($destinationBatch) {
                                $destinationBatchId = $destinationBatch->id;
                                $destinationStoreId = $destinationBatch->store_id;
                            }
                        }
                    }
                    
                    if (!$destinationStoreId) {
                        $this->error("  ❌ Cannot determine destination store for barcode {$barcode->barcode}");
                        $errors++;
                        continue;
                    }
                    
                    // Update the barcode
                    $updateData = [
                        'current_status' => 'available',
                        'current_store_id' => $destinationStoreId,
                        'location_updated_at' => now(),
                        'location_metadata' => array_merge($metadata, [
                            'fixed_at' => now()->toISOString(),
                            'fixed_by' => 'system',
                            'fix_reason' => 'stuck_in_transit_status'
                        ])
                    ];
                    
                    if ($destinationBatchId) {
                        $updateData['batch_id'] = $destinationBatchId;
                    }
                    
                    $barcode->update($updateData);
                    
                    $this->info("  ✅ Fixed barcode {$barcode->barcode} - moved to store {$destinationStoreId}" . 
                                ($destinationBatchId ? " and batch {$destinationBatchId}" : ""));
                    $fixed++;
                    
                } catch (\Exception $e) {
                    $this->error("  ❌ Failed to fix barcode {$barcode->barcode}: " . $e->getMessage());
                    $errors++;
                }
            }
        }
        
        if ($dryRun) {
            $this->info("🔍 DRY RUN: Would fix {$stuckBarcodes->count()} barcodes");
            $this->info("Run without --dry-run to actually apply the fixes");
        } else {
            $this->info("✅ Fixed {$fixed} barcodes");
            if ($errors > 0) {
                $this->warn("⚠️  {$errors} barcodes could not be fixed");
            }
        }
        
        return 0;
    }
}