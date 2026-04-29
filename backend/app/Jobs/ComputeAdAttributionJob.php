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

class ComputeAdAttributionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $orderId;
    
    /**
     * Number of times the job may be attempted
     */
    public $tries = 3;
    
    /**
     * Number of seconds to wait before retrying
     */
    public $backoff = [10, 30, 60];
    
    /**
     * Create a new job instance
     */
    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }
    
    /**
     * Execute the job
     */
    public function handle(AdAttributionService $attributionService)
    {
        $order = Order::with(['items.product', 'items.batch'])
            ->find($this->orderId);
        
        if (!$order) {
            Log::warning("Order {$this->orderId} not found for attribution");
            return;
        }
        
        try {
            $attributionService->computeCreditsForOrder($order, 'BOTH');
            
            Log::info("Attribution computed successfully for order {$order->order_number}");
            
        } catch (\Exception $e) {
            Log::error("Attribution failed for order {$order->order_number}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw to trigger job retry
            throw $e;
        }
    }
    
    /**
     * Handle a job failure
     */
    public function failed(\Throwable $exception)
    {
        Log::error("Attribution job permanently failed for order {$this->orderId}", [
            'error' => $exception->getMessage(),
        ]);
        
        // Optionally notify admin or create alert
    }
}
