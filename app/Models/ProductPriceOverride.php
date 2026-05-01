<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\AutoLogsActivity;

class ProductPriceOverride extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'product_id',
        'price',
        'original_price',
        'reason',
        'description',
        'store_id',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                    ->where('starts_at', '<=', now())
                    ->where(function ($q) {
                        $q->whereNull('ends_at')
                          ->orWhere('ends_at', '>', now());
                    });
    }

    public function scopeExpired($query)
    {
        return $query->where('ends_at', '<=', now());
    }

    public function scopePending($query)
    {
        return $query->where('starts_at', '>', now());
    }

    public function scopeByReason($query, $reason)
    {
        return $query->where('reason', $reason);
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_at');
    }

    public function scopeUnapproved($query)
    {
        return $query->whereNull('approved_at');
    }

    public function isCurrentlyActive(): bool
    {
        return $this->is_active &&
               $this->starts_at->isPast() &&
               (is_null($this->ends_at) || $this->ends_at->isFuture());
    }

    public function isExpired(): bool
    {
        return !is_null($this->ends_at) && $this->ends_at->isPast();
    }

    public function isApproved(): bool
    {
        return !is_null($this->approved_at);
    }

    public function approve(Employee $approver)
    {
        $this->update([
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
        return $this;
    }

    public function calculateDiscountPercentage()
    {
        if (!$this->original_price || $this->original_price == 0) {
            return 0;
        }

        return round((($this->original_price - $this->price) / $this->original_price) * 100, 2);
    }

    public function getDiscountAmount()
    {
        if (!$this->original_price) {
            return 0;
        }

        return $this->original_price - $this->price;
    }

    public static function createOverride(array $data)
    {
        // Set original price if not provided
        if (!isset($data['original_price']) && isset($data['product_id'])) {
            $product = Product::find($data['product_id']);
            // Assuming product has a base_price field, or we can set it to current price
            // For now, we'll leave it as is
        }

        return static::create($data);
    }

    public function getStatusAttribute()
    {
        if (!$this->is_active) {
            return 'inactive';
        }

        if ($this->starts_at->isFuture()) {
            return 'pending';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return 'active';
    }

    public function getDurationInDays()
    {
        if (!$this->ends_at) {
            return null;
        }

        return $this->starts_at->diffInDays($this->ends_at);
    }
}