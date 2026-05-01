<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\AutoLogsActivity;

class ProductBatch extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'product_id',
        'batch_number',
        'quantity',
        'cost_price',
        'sell_price',
        'tax_percentage',
        'base_price',
        'tax_amount',
        'availability',
        'manufactured_date',
        'expiry_date',
        'store_id',
        'barcode_id',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'cost_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'base_price' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'availability' => 'boolean',
        'manufactured_date' => 'date',
        'expiry_date' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($batch) {
            if (empty($batch->batch_number)) {
                $batch->batch_number = static::generateBatchNumber();
            }
            
            // Calculate tax based on TAX_MODE
            static::calculateTaxFields($batch);
        });

        static::updating(function ($batch) {
            // Recalculate base_price and tax_amount if sell_price or tax_percentage changed
            if ($batch->isDirty(['sell_price', 'tax_percentage'])) {
                static::calculateTaxFields($batch);
            }
        });
    }

    /**
     * Calculate tax fields based on TAX_MODE configuration
     */
    protected static function calculateTaxFields($batch): void
    {
        if ($batch->sell_price <= 0) {
            $batch->base_price = 0;
            $batch->tax_amount = 0;
            return;
        }

        $taxMode = config('app.tax_mode', 'inclusive');

        if ($taxMode === 'inclusive') {
            // Inclusive: sell_price includes tax (price = base + tax)
            // Extract base and tax from sell_price
            if ($batch->tax_percentage > 0) {
                $batch->base_price = round($batch->sell_price / (1 + ($batch->tax_percentage / 100)), 2);
                $batch->tax_amount = round($batch->sell_price - $batch->base_price, 2);
            } else {
                $batch->base_price = $batch->sell_price;
                $batch->tax_amount = 0;
            }
        } else {
            // Exclusive: sell_price is the base, tax is added on top (total = price + tax)
            $batch->base_price = $batch->sell_price;
            if ($batch->tax_percentage > 0) {
                $batch->tax_amount = round($batch->sell_price * ($batch->tax_percentage / 100), 2);
            } else {
                $batch->tax_amount = 0;
            }
        }
    }

    /**
     * Get the total price (base + tax) for this batch
     * In inclusive mode, this equals sell_price
     * In exclusive mode, this is sell_price + tax_amount
     */
    public function getTotalPriceAttribute(): float
    {
        $taxMode = config('app.tax_mode', 'inclusive');
        
        if ($taxMode === 'inclusive') {
            return (float) $this->sell_price;
        } else {
            return (float) ($this->sell_price + $this->tax_amount);
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function barcode(): BelongsTo
    {
        return $this->belongsTo(ProductBarcode::class, 'barcode_id');
    }

    public function barcodes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'batch_id');
    }

    public function primaryBarcode(): BelongsTo
    {
        return $this->barcode();
    }

    public function allBarcodes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->barcodes();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable($query)
    {
        return $query->where('availability', true)->where('quantity', '>', 0);
    }

    public function scopeExpired($query)
    {
        return $query->where('expiry_date', '<=', now());
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->where('expiry_date', '<=', now()->addDays($days))
                    ->where('expiry_date', '>', now());
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeByBatchNumber($query, $batchNumber)
    {
        return $query->where('batch_number', $batchNumber);
    }

    public function isExpired(): bool
    {
        return !is_null($this->expiry_date) && $this->expiry_date <= now();
    }

    public function isAvailable(): bool
    {
        return $this->availability && $this->quantity > 0 && !$this->isExpired();
    }

    public function isLowStock($threshold = 10): bool
    {
        return $this->quantity <= $threshold && $this->quantity > 0;
    }

    public function calculateProfitMargin()
    {
        if ($this->cost_price == 0) {
            return 0;
        }

        // Use base_price (excluding tax) for profit calculation
        $priceForProfit = $this->base_price ?? $this->sell_price;
        return round((($priceForProfit - $this->cost_price) / $this->cost_price) * 100, 2);
    }

    public function getTotalValue()
    {
        return $this->quantity * $this->cost_price;
    }

    public function getSellValue()
    {
        return $this->quantity * $this->sell_price;
    }

    public static function generateBatchNumber(): string
    {
        do {
            $batchNumber = 'BATCH-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
        } while (static::where('batch_number', $batchNumber)->exists());

        return $batchNumber;
    }

    public static function findByBarcode($barcode)
    {
        return static::whereHas('barcode', function ($query) use ($barcode) {
            $query->where('barcode', $barcode);
        })->first();
    }

    public function updateQuantity($newQuantity)
    {
        $this->update(['quantity' => $newQuantity]);

        // Auto-update availability based on quantity
        if ($newQuantity <= 0) {
            $this->update(['availability' => false]);
        } elseif ($newQuantity > 0 && !$this->availability) {
            $this->update(['availability' => true]);
        }

        return $this;
    }

    public function addStock($amount)
    {
        return $this->updateQuantity($this->quantity + $amount);
    }

    public function removeStock($amount)
    {
        return $this->updateQuantity(max(0, $this->quantity - $amount));
    }

    public function getDaysUntilExpiry()
    {
        if (!$this->expiry_date) {
            return null;
        }

        return now()->diffInDays($this->expiry_date, false);
    }

    public function getStatusAttribute()
    {
        if (!$this->is_active) {
            return 'inactive';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if (!$this->availability) {
            return 'unavailable';
        }

        if ($this->quantity <= 0) {
            return 'out_of_stock';
        }

        if ($this->isLowStock()) {
            return 'low_stock';
        }

        return 'available';
    }

    public function getLocationHistory()
    {
        return ProductMovement::byBatch($this->id)
                             ->with(['fromStore', 'toStore', 'performedBy'])
                             ->orderBy('movement_date', 'desc')
                             ->get();
    }

    public function getCurrentLocation()
    {
        return $this->store;
    }

    public function getMovementCount()
    {
        return ProductMovement::byBatch($this->id)->count();
    }

    public function getLastMovement()
    {
        return ProductMovement::byBatch($this->id)
                             ->orderBy('movement_date', 'desc')
                             ->first();
    }
}