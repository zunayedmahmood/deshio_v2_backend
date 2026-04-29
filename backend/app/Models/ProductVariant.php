<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id', 'sku', 'barcode', 'attributes', 'price_adjustment',
        'cost_price', 'stock_quantity', 'reserved_quantity', 'reorder_point',
        'image_url', 'is_active', 'is_default',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price_adjustment' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'reorder_point' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock($query)
    {
        return $query->whereRaw('stock_quantity <= reorder_point');
    }

    public function getAvailableStockAttribute(): int
    {
        return max(0, $this->stock_quantity - $this->reserved_quantity);
    }

    public function getFinalPriceAttribute(): float
    {
        return $this->product->selling_price + $this->price_adjustment;
    }

    public function getVariantNameAttribute(): string
    {
        $parts = [];
        foreach ($this->attributes as $key => $value) {
            $parts[] = "{$key}: {$value}";
        }
        return implode(', ', $parts);
    }
}
