<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\AdAttributionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackfillAdAttributionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $fromDate;
    protected $toDate;
    protected $statuses;
    
    public $timeout = 3600; // 1 hour timeout
    
    /**
     * Create a new job instance
     */
    public function __construct(string $fromDate, string $toDate, array $statuses = null)
    {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->statuses = $statuses ?? ['confirmed', 'processing', 'shipped', 'delivered'];
    }
    
    /**
     * Execute the job
     */
    public function handle(AdAttributionService $attributionService)
    {
        Log::info("Starting attribution backfill", [
            'from' => $this->fromDate,
            'to' => $this->toDate,
            'statuses' => $this->statuses,
        ]);
        
        $processed = 0;
        $failed = 0;
        
        Order::with(['items.product', 'items.batch'])
            ->whereIn('status', $this->statuses)
            ->whereBetween('order_date', [$this->fromDate, $this->toDate])
            ->chunk(100, function($orders) use ($attributionService, &$processed, &$failed) {
                foreach ($orders as $order) {
                    try {
                        $attributionService->computeCreditsForOrder($order, 'BOTH');
                        $processed++;
                    } catch (\Exception $e) {
                        $failed++;
                        Log::error("Backfill failed for order {$order->order_number}", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                
                // Log progress every 100 orders
                Log::info("Backfill progress: {$processed} processed, {$failed} failed");
            });
        
        Log::info("Backfill completed", [
            'from' => $this->fromDate,
            'to' => $this->toDate,
            'total_processed' => $processed,
            'total_failed' => $failed,
        ]);
    }
}
