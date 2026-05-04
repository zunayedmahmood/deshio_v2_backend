<?php

namespace App\Services;

use App\Models\Promotion;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Collection;

/**
 * AutomaticDiscountService
 * 
 * Handles automatic discount calculations for sale campaigns
 * that apply system-wide without requiring promo codes.
 */
class AutomaticDiscountService
{
    /**
     * Get active automatic discounts for given products
     * 
     * @param array $productIds Array of product IDs
     * @return Collection Collection of Promotion models
     */
    public function getActiveDiscountsForProducts(array $productIds): Collection
    {
        if (empty($productIds)) {
            return collect([]);
        }

        $now = now();
        
        // Get all products with their categories in one query
        $products = Product::whereIn('id', $productIds)
            ->pluck('category_id', 'id');
        
        $categoryIds = $products->unique()->values()->toArray();
        
        return Promotion::where('is_automatic', true)
            ->where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $now);
            })
            ->where(function ($q) use ($productIds, $categoryIds) {
                $q->where(function ($subQ) use ($productIds) {
                    // Match products
                    foreach ($productIds as $productId) {
                        $subQ->orWhereJsonContains('applicable_products', (string)$productId);
                    }
                })->orWhere(function ($subQ) use ($categoryIds) {
                    // Match categories
                    foreach ($categoryIds as $categoryId) {
                        if ($categoryId) {
                            $subQ->orWhereJsonContains('applicable_categories', (string)$categoryId);
                        }
                    }
                });
            })
            ->orderBy('discount_value', 'desc')
            ->get();
    }
    
    /**
     * Get active automatic discounts for given categories
     * 
     * @param array $categoryIds Array of category IDs
     * @return Collection Collection of Promotion models
     */
    public function getActiveDiscountsForCategories(array $categoryIds): Collection
    {
        if (empty($categoryIds)) {
            return collect([]);
        }

        $now = now();
        
        return Promotion::where('is_automatic', true)
            ->where('is_active', true)
            ->where('start_date', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $now);
            })
            ->where(function ($q) use ($categoryIds) {
                foreach ($categoryIds as $categoryId) {
                    $q->orWhereJsonContains('applicable_categories', (string)$categoryId);
                }
            })
            ->orderBy('discount_value', 'desc')
            ->get();
    }
    
    /**
     * Calculate discount for a single product
     * 
     * @param int $productId Product ID
     * @param float $originalPrice Original price
     * @return array Discount calculation details
     */
    public function calculateProductDiscount(int $productId, float $originalPrice): array
    {
        if ($originalPrice <= 0) {
            return $this->emptyDiscountResult($originalPrice);
        }

        $discounts = $this->getActiveDiscountsForProducts([$productId]);
        
        if ($discounts->isEmpty()) {
            return $this->emptyDiscountResult($originalPrice);
        }
        
        // Apply highest discount if multiple campaigns exist
        return $this->applyBestDiscount($discounts, $originalPrice);
    }
    
    /**
     * Calculate discounts for multiple items (for cart/order)
     * 
     * @param array $items Array of items with product_id, quantity, unit_price
     * @return array Discount breakdown per item and total
     */
    public function calculateCartDiscounts(array $items): array
    {
        $productIds = array_column($items, 'product_id');
        $discounts = $this->getActiveDiscountsForProducts($productIds);
        
        if ($discounts->isEmpty()) {
            return [
                'total_discount' => 0,
                'items' => array_map(function($item) {
                    return array_merge($item, $this->emptyDiscountResult($item['unit_price']));
                }, $items),
                'campaigns_applied' => [],
            ];
        }
        
        $totalDiscount = 0;
        $campaignsApplied = [];
        $processedItems = [];
        
        foreach ($items as $item) {
            $itemDiscounts = $discounts->filter(function($discount) use ($item) {
                return $this->promotionAppliesTo($discount, $item['product_id']);
            });
            
            if ($itemDiscounts->isEmpty()) {
                $processedItems[] = array_merge($item, $this->emptyDiscountResult($item['unit_price']));
                continue;
            }
            
            $result = $this->applyBestDiscount($itemDiscounts, $item['unit_price']);
            
            // Calculate total discount for this item (discount per unit * quantity)
            $itemTotalDiscount = $result['discount_amount'] * $item['quantity'];
            $totalDiscount += $itemTotalDiscount;
            
            // Track campaigns applied
            if ($result['active_campaign']) {
                $campaignId = $result['active_campaign']['id'];
                if (!isset($campaignsApplied[$campaignId])) {
                    $campaignsApplied[$campaignId] = $result['active_campaign'];
                }
            }
            
            // Merge item with discount info
            $processedItems[] = array_merge($item, [
                'original_price' => $result['original_price'],
                'discounted_price' => $result['discounted_price'],
                'discount_amount_per_unit' => $result['discount_amount'],
                'discount_amount_total' => $itemTotalDiscount,
                'discount_percentage' => $result['discount_percentage'],
                'active_campaign' => $result['active_campaign'],
            ]);
        }
        
        return [
            'total_discount' => round($totalDiscount, 2),
            'items' => $processedItems,
            'campaigns_applied' => array_values($campaignsApplied),
        ];
    }
    
    /**
     * Check if a promotion applies to a specific product
     */
    private function promotionAppliesTo(Promotion $promotion, int $productId): bool
    {
        // Check direct product match
        $applicableProducts = $promotion->applicable_products ?? [];
        if (in_array($productId, $applicableProducts) || in_array((string)$productId, $applicableProducts)) {
            return true;
        }
        
        // Check category match
        $product = Product::find($productId);
        if ($product && $product->category_id) {
            $applicableCategories = $promotion->applicable_categories ?? [];
            if (in_array($product->category_id, $applicableCategories) || 
                in_array((string)$product->category_id, $applicableCategories)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Apply the best (highest) discount from available promotions
     */
    private function applyBestDiscount(Collection $discounts, float $price): array
    {
        $maxDiscount = 0;
        $bestCampaign = null;
        
        foreach ($discounts as $discount) {
            $amount = $this->calculateDiscountAmount($discount, $price);
            
            if ($amount > $maxDiscount) {
                $maxDiscount = $amount;
                $bestCampaign = $discount;
            }
        }
        
        $discountedPrice = max(0, $price - $maxDiscount);
        
        return [
            'original_price' => round($price, 2),
            'discounted_price' => round($discountedPrice, 2),
            'discount_amount' => round($maxDiscount, 2),
            'discount_percentage' => $price > 0 ? round(($maxDiscount / $price) * 100, 2) : 0,
            'active_campaign' => $bestCampaign ? [
                'id' => $bestCampaign->id,
                'name' => $bestCampaign->name,
                'code' => $bestCampaign->code,
                'type' => $bestCampaign->type,
                'value' => $bestCampaign->discount_value,
                'start_date' => $bestCampaign->start_date->toIso8601String(),
                'end_date' => $bestCampaign->end_date?->toIso8601String(),
            ] : null,
        ];
    }
    
    /**
     * Calculate discount amount based on promotion type
     */
    private function calculateDiscountAmount(Promotion $promotion, float $price): float
    {
        $amount = 0;
        
        if ($promotion->type === 'percentage') {
            $amount = ($price * $promotion->discount_value) / 100;
        } elseif ($promotion->type === 'fixed') {
            $amount = $promotion->discount_value;
        }
        
        // Apply maximum discount cap if set
        if ($promotion->maximum_discount && $amount > $promotion->maximum_discount) {
            $amount = $promotion->maximum_discount;
        }
        
        // Don't discount more than the price itself
        if ($amount > $price) {
            $amount = $price;
        }
        
        return $amount;
    }
    
    /**
     * Return empty discount result
     */
    private function emptyDiscountResult(float $price): array
    {
        return [
            'original_price' => round($price, 2),
            'discounted_price' => round($price, 2),
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'active_campaign' => null,
        ];
    }
}
