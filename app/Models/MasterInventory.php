<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use App\Traits\AutoLogsActivity;

class MasterInventory extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'product_id',
        'total_quantity',
        'available_quantity',
        'reserved_quantity',
        'damaged_quantity',
        'minimum_stock_level',
        'maximum_stock_level',
        'reorder_point',
        'average_cost_price',
        'average_sell_price',
        'total_value',
        'stock_status',
        'store_breakdown',
        'batch_breakdown',
        'last_updated_at',
        'last_counted_at',
        'notes',
    ];

    protected $casts = [
        'total_quantity' => 'integer',
        'available_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'damaged_quantity' => 'integer',
        'minimum_stock_level' => 'integer',
        'maximum_stock_level' => 'integer',
        'reorder_point' => 'integer',
        'average_cost_price' => 'decimal:2',
        'average_sell_price' => 'decimal:2',
        'total_value' => 'decimal:2',
        'store_breakdown' => 'array',
        'batch_breakdown' => 'array',
        'last_updated_at' => 'datetime',
        'last_counted_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock_status', 'out_of_stock');
    }

    public function scopeLowStock($query)
    {
        return $query->where('stock_status', 'low_stock');
    }

    public function scopeNormalStock($query)
    {
        return $query->where('stock_status', 'normal');
    }

    public function scopeOverstocked($query)
    {
        return $query->where('stock_status', 'overstocked');
    }

    public function scopeBelowReorderPoint($query)
    {
        return $query->whereRaw('available_quantity <= reorder_point');
    }

    public function scopeAboveMaximumLevel($query)
    {
        return $query->whereRaw('available_quantity > maximum_stock_level')
                    ->whereNotNull('maximum_stock_level');
    }

    public function isOutOfStock(): bool
    {
        return $this->available_quantity <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->available_quantity <= $this->minimum_stock_level && $this->available_quantity > 0;
    }

    public function isOverstocked(): bool
    {
        return $this->maximum_stock_level && $this->available_quantity > $this->maximum_stock_level;
    }

    public function needsReorder(): bool
    {
        return $this->available_quantity <= $this->reorder_point;
    }

    public function getStockLevelPercentage(): float
    {
        if ($this->maximum_stock_level && $this->maximum_stock_level > 0) {
            return round(($this->available_quantity / $this->maximum_stock_level) * 100, 2);
        }

        return $this->available_quantity > 0 ? 100.0 : 0.0;
    }

    public function getStoreQuantities(): array
    {
        return $this->store_breakdown ?? [];
    }

    public function getBatchQuantities(): array
    {
        return $this->batch_breakdown ?? [];
    }

    public function getQuantityAtStore($storeId): int
    {
        $storeBreakdown = $this->getStoreQuantities();
        return $storeBreakdown[$storeId] ?? 0;
    }

    public function syncInventory()
    {
        $productId = $this->product_id;

        // Get all active batches for this product
        $batches = ProductBatch::where('product_id', $productId)
                              ->active()
                              ->with('store')
                              ->get();

        $totalQuantity = 0;
        $availableQuantity = 0;
        $reservedQuantity = 0;
        $damagedQuantity = 0;
        $totalCostValue = 0;
        $totalSellValue = 0;
        $storeBreakdown = [];
        $batchBreakdown = [];

        foreach ($batches as $batch) {
            $batchQuantity = $batch->quantity;
            $storeId = $batch->store_id;

            $totalQuantity += $batchQuantity;

            if ($batch->isAvailable()) {
                $availableQuantity += $batchQuantity;
            }

            // Calculate weighted averages
            $totalCostValue += $batchQuantity * $batch->cost_price;
            $totalSellValue += $batchQuantity * $batch->sell_price;

            // Store breakdown
            if (!isset($storeBreakdown[$storeId])) {
                $storeBreakdown[$storeId] = 0;
            }
            $storeBreakdown[$storeId] += $batchQuantity;

            // Batch breakdown
            $batchBreakdown[$batch->id] = $batchQuantity;
        }

        // Calculate averages
        $averageCostPrice = $totalQuantity > 0 ? $totalCostValue / $totalQuantity : 0;
        $averageSellPrice = $totalQuantity > 0 ? $totalSellValue / $totalQuantity : 0;
        $totalValue = $availableQuantity * $averageSellPrice;

        // Determine stock status
        $stockStatus = $this->calculateStockStatus($availableQuantity);

        // Update master inventory
        $this->update([
            'total_quantity' => $totalQuantity,
            'available_quantity' => $availableQuantity,
            'reserved_quantity' => $reservedQuantity,
            'damaged_quantity' => $damagedQuantity,
            'average_cost_price' => round($averageCostPrice, 2),
            'average_sell_price' => round($averageSellPrice, 2),
            'total_value' => round($totalValue, 2),
            'stock_status' => $stockStatus,
            'store_breakdown' => $storeBreakdown,
            'batch_breakdown' => $batchBreakdown,
            'last_updated_at' => now(),
        ]);

        return $this;
    }

    protected function calculateStockStatus($availableQuantity): string
    {
        if ($availableQuantity <= 0) {
            return 'out_of_stock';
        }

        if ($availableQuantity <= $this->minimum_stock_level) {
            return 'low_stock';
        }

        if ($this->maximum_stock_level && $availableQuantity > $this->maximum_stock_level) {
            return 'overstocked';
        }

        return 'normal';
    }

    public static function syncAllInventories()
    {
        $products = Product::all();

        foreach ($products as $product) {
            $masterInventory = static::firstOrCreate(
                ['product_id' => $product->id],
                [
                    'minimum_stock_level' => 0,
                    'reorder_point' => 0,
                ]
            );

            $masterInventory->syncInventory();
        }

        return true;
    }

    public static function syncProductInventory($productId)
    {
        $masterInventory = static::firstOrCreate(
            ['product_id' => $productId],
            [
                'minimum_stock_level' => 0,
                'reorder_point' => 0,
            ]
        );

        return $masterInventory->syncInventory();
    }

    public static function getLowStockAlerts()
    {
        return static::lowStock()
                    ->orWhere->belowReorderPoint()
                    ->with('product')
                    ->orderBy('available_quantity')
                    ->get();
    }

    public static function getOutOfStockItems()
    {
        return static::outOfStock()
                    ->with('product')
                    ->get();
    }

    public static function getOverstockedItems()
    {
        return static::overstocked()
                    ->with('product')
                    ->orderBy('available_quantity', 'desc')
                    ->get();
    }

    public static function getInventorySummary()
    {
        return [
            'total_products' => static::count(),
            'out_of_stock' => static::outOfStock()->count(),
            'low_stock' => static::lowStock()->count(),
            'normal_stock' => static::normalStock()->count(),
            'overstocked' => static::overstocked()->count(),
            'total_value' => static::sum('total_value'),
            'total_items' => static::sum('total_quantity'),
            'available_items' => static::sum('available_quantity'),
        ];
    }

    public static function getStoreInventorySummary($storeId)
    {
        $inventories = static::all();

        $summary = [
            'store_id' => $storeId,
            'total_products' => 0,
            'total_quantity' => 0,
            'total_value' => 0,
            'low_stock_items' => 0,
            'out_of_stock_items' => 0,
        ];

        foreach ($inventories as $inventory) {
            $storeQuantity = $inventory->getQuantityAtStore($storeId);

            if ($storeQuantity > 0) {
                $summary['total_products']++;
                $summary['total_quantity'] += $storeQuantity;
                $summary['total_value'] += $storeQuantity * $inventory->average_sell_price;

                if ($inventory->isOutOfStock()) {
                    $summary['out_of_stock_items']++;
                } elseif ($inventory->isLowStock()) {
                    $summary['low_stock_items']++;
                }
            }
        }

        return $summary;
    }

    public function setStockLevels($minimum, $maximum = null, $reorderPoint = null)
    {
        $this->update([
            'minimum_stock_level' => $minimum,
            'maximum_stock_level' => $maximum,
            'reorder_point' => $reorderPoint ?? $minimum,
        ]);

        // Recalculate stock status
        $this->update(['stock_status' => $this->calculateStockStatus($this->available_quantity)]);

        return $this;
    }

    public function getStockHealthScore(): int
    {
        $score = 100;

        if ($this->isOutOfStock()) {
            $score -= 50;
        } elseif ($this->isLowStock()) {
            $score -= 25;
        }

        if ($this->isOverstocked()) {
            $score -= 20;
        }

        if ($this->needsReorder()) {
            $score -= 15;
        }

        return max(0, $score);
    }

    public function getRecommendedActions(): array
    {
        $actions = [];

        if ($this->isOutOfStock()) {
            $actions[] = 'Urgent: Replenish stock immediately';
        } elseif ($this->needsReorder()) {
            $actions[] = 'Reorder stock soon';
        }

        if ($this->isOverstocked()) {
            $actions[] = 'Consider redistributing excess stock to other stores';
            $actions[] = 'Create rebalancing request to move stock to understocked locations';
        }

        if ($this->isLowStock()) {
            $actions[] = 'Monitor stock levels closely';
            $actions[] = 'Check for available stock in other stores for rebalancing';
        }

        return $actions;
    }

    public function suggestRebalancing()
    {
        if (!$this->isOverstocked()) {
            return [];
        }

        $suggestions = [];
        $storeBreakdown = $this->getStoreQuantities();

        // Find overstocked stores
        $overstockedStores = array_filter($storeBreakdown, function($quantity) {
            return $this->maximum_stock_level && $quantity > $this->maximum_stock_level;
        });

        // Find understocked stores (this would need to be expanded to check other products/stores)
        // For now, just return potential rebalancing from overstocked stores
        foreach ($overstockedStores as $storeId => $quantity) {
            $excessQuantity = $quantity - $this->maximum_stock_level;

            $suggestions[] = [
                'product_id' => $this->product_id,
                'source_store_id' => $storeId,
                'suggested_quantity' => $excessQuantity,
                'reason' => 'Overstocked - redistribute to balance inventory',
                'priority' => 'medium',
            ];
        }

        return $suggestions;
    }

    public static function getInventoryHealthReport()
    {
        $totalProducts = static::count();
        $outOfStock = static::outOfStock()->count();
        $lowStock = static::lowStock()->count();
        $overstocked = static::overstocked()->count();
        $normal = static::normalStock()->count();

        $healthScore = 0;
        if ($totalProducts > 0) {
            $healthyProducts = $normal;
            $healthScore = round(($healthyProducts / $totalProducts) * 100, 2);
        }

        return [
            'total_products' => $totalProducts,
            'out_of_stock' => $outOfStock,
            'low_stock' => $lowStock,
            'normal_stock' => $normal,
            'overstocked' => $overstocked,
            'health_score' => $healthScore,
            'total_value' => static::sum('total_value'),
            'issues' => [
                'critical' => $outOfStock,
                'warning' => $lowStock,
                'attention' => $overstocked,
            ],
        ];
    }

    public static function getTopSellingProducts($limit = 10)
    {
        // This would need to be integrated with sales data
        // For now, return products with highest total value
        return static::with('product')
                    ->orderBy('total_value', 'desc')
                    ->limit($limit)
                    ->get();
    }

    public static function getSlowMovingInventory($days = 30)
    {
        // This would need movement tracking integration
        // For now, return products with no recent movements
        return static::with('product')
                    ->where('last_updated_at', '<', now()->subDays($days))
                    ->orderBy('last_updated_at')
                    ->get();
    }

    public static function optimizeStockLevels()
    {
        $optimizations = [];

        // Analyze each product's stock levels
        $inventories = static::all();

        foreach ($inventories as $inventory) {
            $analysis = $inventory->analyzeStockOptimization();

            if (!empty($analysis)) {
                $optimizations[] = [
                    'product' => $inventory->product,
                    'current_status' => $inventory->stock_status,
                    'recommendations' => $analysis,
                ];
            }
        }

        return $optimizations;
    }

    protected function analyzeStockOptimization()
    {
        $recommendations = [];

        // Analyze stock level consistency across stores
        $storeBreakdown = $this->getStoreQuantities();

        if (count($storeBreakdown) > 1) {
            $quantities = array_values($storeBreakdown);
            $avgQuantity = array_sum($quantities) / count($quantities);
            $variance = 0;

            foreach ($quantities as $quantity) {
                $variance += pow($quantity - $avgQuantity, 2);
            }
            $variance /= count($quantities);
            $stdDev = sqrt($variance);

            // If standard deviation is high, suggest rebalancing
            if ($stdDev > $avgQuantity * 0.5) { // More than 50% variation
                $recommendations[] = 'High variation in stock levels across stores - consider rebalancing';
            }
        }

        // Check for stock level appropriateness
        if ($this->minimum_stock_level == 0 && $this->available_quantity > 0) {
            $recommendations[] = 'Set minimum stock level to prevent stockouts';
        }

        if (!$this->maximum_stock_level && $this->available_quantity > $this->minimum_stock_level * 3) {
            $recommendations[] = 'Consider setting maximum stock level to optimize storage';
        }

        return $recommendations;
    }
}