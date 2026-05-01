<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\AdCampaign;
use App\Models\OrderItemCampaignCredit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdAttributionService 
{
    /**
     * Compute credits for an entire order
     * 
     * @param Order $order
     * @param string $creditMode 'FULL', 'SPLIT', or 'BOTH'
     */
    public function computeCreditsForOrder(Order $order, string $creditMode = 'BOTH'): void
    {
        $saleTime = $order->order_date ?? $order->created_at;
        
        Log::info("Computing attribution for order {$order->order_number}", [
            'order_id' => $order->id,
            'sale_time' => $saleTime,
            'credit_mode' => $creditMode,
        ]);
        
        foreach ($order->items as $item) {
            $this->computeCreditsForOrderItem($item, $saleTime, $creditMode);
        }
    }
    
    /**
     * Compute credits for a single order item
     * 
     * @param OrderItem $item
     * @param \DateTime $saleTime
     * @param string $creditMode
     */
    public function computeCreditsForOrderItem(
        OrderItem $item, 
        \DateTime $saleTime, 
        string $creditMode = 'BOTH'
    ): void 
    {
        // Step 1: Find matching campaigns
        $matchedCampaigns = $this->findMatchingCampaigns($item->product_id, $saleTime);
        
        $k = $matchedCampaigns->count();
        
        if ($k === 0) {
            // No attribution - log for analysis
            Log::debug("No campaigns matched for order item", [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'sale_time' => $saleTime,
            ]);
            return;
        }
        
        Log::info("Found {$k} matching campaigns for order item {$item->id}");
        
        // Step 2: Calculate credited amounts
        $itemQty = $item->quantity;
        $itemRevenue = $this->calculateRevenue($item);
        $itemProfit = $this->calculateProfit($item);
        
        // Step 3: Create credit records (with idempotency)
        DB::transaction(function() use (
            $item, 
            $matchedCampaigns, 
            $k, 
            $saleTime, 
            $creditMode, 
            $itemQty, 
            $itemRevenue, 
            $itemProfit
        ) {
            // Delete existing credits for idempotency
            OrderItemCampaignCredit::where('order_item_id', $item->id)
                ->where('sale_time', $saleTime)
                ->delete();
            
            foreach ($matchedCampaigns as $campaign) {
                // FULL credit mode
                if (in_array($creditMode, ['FULL', 'BOTH'])) {
                    OrderItemCampaignCredit::create([
                        'order_id' => $item->order_id,
                        'order_item_id' => $item->id,
                        'campaign_id' => $campaign->id,
                        'sale_time' => $saleTime,
                        'credit_mode' => 'FULL',
                        'credited_qty' => $itemQty,
                        'credited_revenue' => $itemRevenue,
                        'credited_profit' => $itemProfit,
                        'matched_campaigns_count' => $k,
                    ]);
                }
                
                // SPLIT credit mode
                if (in_array($creditMode, ['SPLIT', 'BOTH'])) {
                    OrderItemCampaignCredit::create([
                        'order_id' => $item->order_id,
                        'order_item_id' => $item->id,
                        'campaign_id' => $campaign->id,
                        'sale_time' => $saleTime,
                        'credit_mode' => 'SPLIT',
                        'credited_qty' => round($itemQty / $k, 4),
                        'credited_revenue' => round($itemRevenue / $k, 2),
                        'credited_profit' => $itemProfit !== null ? round($itemProfit / $k, 2) : null,
                        'matched_campaigns_count' => $k,
                    ]);
                }
            }
            
            Log::info("Created credit records for order item {$item->id}", [
                'campaigns_matched' => $k,
                'credit_mode' => $creditMode,
                'records_created' => $creditMode === 'BOTH' ? $k * 2 : $k,
            ]);
        });
    }
    
    /**
     * Find all campaigns matching a product at a given time
     * 
     * @param int $productId
     * @param \DateTime $saleTime
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function findMatchingCampaigns(int $productId, \DateTime $saleTime)
    {
        return AdCampaign::activeAt($saleTime)
            ->whereHas('targetedProducts', function($q) use ($productId, $saleTime) {
                $q->where('product_id', $productId)
                  ->effectiveAt($saleTime);
            })
            ->with('targetedProducts')
            ->get();
    }
    
    /**
     * Calculate net revenue for an order item
     * 
     * @param OrderItem $item
     * @return float
     */
    private function calculateRevenue(OrderItem $item): float
    {
        $subtotal = $item->unit_price * $item->quantity;
        $discount = $item->discount_amount ?? 0;
        
        return $subtotal - $discount;
    }
    
    /**
     * Calculate profit for an order item
     * 
     * @param OrderItem $item
     * @return float|null
     */
    private function calculateProfit(OrderItem $item): ?float
    {
        // Try to get cost from batch first, then product, then COGS field
        $costPrice = $item->batch?->cost_price 
            ?? $item->product?->cost_price 
            ?? ($item->cogs ? $item->cogs / $item->quantity : null);
        
        if ($costPrice === null) {
            return null; // Can't calculate profit without cost
        }
        
        $revenue = $this->calculateRevenue($item);
        $cost = $costPrice * $item->quantity;
        
        return $revenue - $cost;
    }
    
    /**
     * Reverse credits for an order (refund/cancellation)
     * 
     * @param Order $order
     */
    public function reverseCreditsForOrder(Order $order): void
    {
        $affectedRows = OrderItemCampaignCredit::where('order_id', $order->id)
            ->where('is_reversed', false)
            ->update([
                'is_reversed' => true,
                'reversed_at' => now(),
            ]);
        
        Log::info("Reversed {$affectedRows} credits for order {$order->order_number}");
    }
    
    /**
     * Unreverse credits (if order is reinstated)
     * 
     * @param Order $order
     */
    public function unreverseCreditsForOrder(Order $order): void
    {
        $affectedRows = OrderItemCampaignCredit::where('order_id', $order->id)
            ->where('is_reversed', true)
            ->update([
                'is_reversed' => false,
                'reversed_at' => null,
            ]);
        
        Log::info("Unreversed {$affectedRows} credits for order {$order->order_number}");
    }
}
