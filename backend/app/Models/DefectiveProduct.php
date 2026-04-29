<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DefectiveProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'product_barcode_id',
        'product_batch_id',
        'store_id',
        'defect_type',
        'defect_description',
        'defect_images',
        'severity',
        'original_price',
        'suggested_selling_price',
        'minimum_selling_price',
        'status',
        'identified_by',
        'inspected_by',
        'sold_by',
        'identified_at',
        'inspected_at',
        'sold_at',
        'order_id',
        'actual_selling_price',
        'sale_notes',
        'disposal_notes',
        'disposed_at',
        'vendor_id',
        'returned_to_vendor_at',
        'vendor_notes',
        'internal_notes',
        'source_return_id',
        'metadata',
    ];

    protected $casts = [
        'defect_images' => 'array',
        'metadata' => 'array',
        'original_price' => 'decimal:2',
        'suggested_selling_price' => 'decimal:2',
        'minimum_selling_price' => 'decimal:2',
        'actual_selling_price' => 'decimal:2',
        'identified_at' => 'datetime',
        'inspected_at' => 'datetime',
        'sold_at' => 'datetime',
        'disposed_at' => 'datetime',
        'returned_to_vendor_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($defectiveProduct) {
            if (!$defectiveProduct->identified_at) {
                $defectiveProduct->identified_at = now();
            }

            // Auto-suggest selling price based on severity
            if (!$defectiveProduct->suggested_selling_price) {
                $defectiveProduct->suggested_selling_price = $defectiveProduct->calculateSuggestedPrice();
            }

            if (!$defectiveProduct->minimum_selling_price) {
                $defectiveProduct->minimum_selling_price = $defectiveProduct->calculateMinimumPrice();
            }
        });
    }

    // Relationships
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function barcode(): BelongsTo
    {
        return $this->belongsTo(ProductBarcode::class, 'product_barcode_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function identifiedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'identified_by');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'inspected_by');
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sold_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function sourceReturn(): BelongsTo
    {
        return $this->belongsTo(ProductReturn::class, 'source_return_id');
    }

    // Scopes
    public function scopeAvailableForSale($query)
    {
        return $query->where('status', 'available_for_sale');
    }

    public function scopeIdentified($query)
    {
        return $query->where('status', 'identified');
    }

    public function scopeInspected($query)
    {
        return $query->where('status', 'inspected');
    }

    public function scopeSold($query)
    {
        return $query->where('status', 'sold');
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByDefectType($query, $type)
    {
        return $query->where('defect_type', $type);
    }

    // Helper methods
    public function calculateSuggestedPrice(): float
    {
        // Suggest price based on severity
        $discountPercentage = match($this->severity) {
            'minor' => 0.10,      // 10% off
            'moderate' => 0.25,   // 25% off
            'major' => 0.50,      // 50% off
            'critical' => 0.70,   // 70% off
            default => 0.25
        };

        return $this->original_price * (1 - $discountPercentage);
    }

    public function calculateMinimumPrice(): float
    {
        // Minimum price is 30% of original for minor, 10% for critical
        $minPercentage = match($this->severity) {
            'minor' => 0.30,
            'moderate' => 0.20,
            'major' => 0.15,
            'critical' => 0.10,
            default => 0.20
        };

        return $this->original_price * $minPercentage;
    }

    public function markAsInspected(Employee $inspector, array $data = []): bool
    {
        if (!in_array($this->status, ['identified'])) {
            return false;
        }

        $updateData = [
            'status' => 'inspected',
            'inspected_by' => $inspector->id,
            'inspected_at' => now(),
        ];

        if (isset($data['severity'])) {
            $updateData['severity'] = $data['severity'];
            // Recalculate prices
            $updateData['suggested_selling_price'] = $this->calculateSuggestedPrice();
            $updateData['minimum_selling_price'] = $this->calculateMinimumPrice();
        }

        if (isset($data['internal_notes'])) {
            $updateData['internal_notes'] = $data['internal_notes'];
        }

        $this->update($updateData);

        return true;
    }

    public function makeAvailableForSale(): bool
    {
        if (!in_array($this->status, ['inspected'])) {
            return false;
        }

        $this->update(['status' => 'available_for_sale']);

        return true;
    }

    public function markAsSold(Employee $seller, Order $order, float $sellingPrice, ?string $notes = null): bool
    {
        if (!in_array($this->status, ['available_for_sale', 'inspected'])) {
            return false;
        }

        // Validate selling price
        if ($sellingPrice < $this->minimum_selling_price) {
            throw new \Exception("Selling price cannot be less than minimum price of {$this->minimum_selling_price}");
        }

        $this->update([
            'status' => 'sold',
            'sold_by' => $seller->id,
            'sold_at' => now(),
            'order_id' => $order->id,
            'actual_selling_price' => $sellingPrice,
            'sale_notes' => $notes,
        ]);

        return true;
    }

    public function markAsDisposed(?string $notes = null): bool
    {
        if (in_array($this->status, ['sold', 'returned_to_vendor'])) {
            return false;
        }

        $this->update([
            'status' => 'disposed',
            'disposed_at' => now(),
            'disposal_notes' => $notes,
        ]);

        return true;
    }

    public function returnToVendor(Vendor $vendor, ?string $notes = null): bool
    {
        if (in_array($this->status, ['sold', 'disposed'])) {
            return false;
        }

        $this->update([
            'status' => 'returned_to_vendor',
            'vendor_id' => $vendor->id,
            'returned_to_vendor_at' => now(),
            'vendor_notes' => $notes,
        ]);

        return true;
    }

    public function isAvailableForSale(): bool
    {
        return $this->status === 'available_for_sale';
    }

    public function isSold(): bool
    {
        return $this->status === 'sold';
    }

    public function canBeSold(): bool
    {
        return in_array($this->status, ['available_for_sale', 'inspected']);
    }

    public function getPotentialDiscount(): float
    {
        return $this->original_price - $this->suggested_selling_price;
    }

    public function getDiscountPercentage(): float
    {
        if ($this->original_price == 0) return 0;
        return (($this->original_price - $this->suggested_selling_price) / $this->original_price) * 100;
    }

    public function getActualDiscountPercentage(): float
    {
        if (!$this->actual_selling_price || $this->original_price == 0) return 0;
        return (($this->original_price - $this->actual_selling_price) / $this->original_price) * 100;
    }

    // Accessors
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'identified' => 'Identified',
            'inspected' => 'Inspected',
            'available_for_sale' => 'Available for Sale',
            'sold' => 'Sold',
            'disposed' => 'Disposed',
            'returned_to_vendor' => 'Returned to Vendor',
            default => 'Unknown'
        };
    }

    public function getSeverityLabelAttribute(): string
    {
        return match($this->severity) {
            'minor' => 'Minor',
            'moderate' => 'Moderate',
            'major' => 'Major',
            'critical' => 'Critical',
            default => 'Unknown'
        };
    }

    public function getSeverityColorAttribute(): string
    {
        return match($this->severity) {
            'minor' => 'warning',
            'moderate' => 'info',
            'major' => 'danger',
            'critical' => 'dark',
            default => 'secondary'
        };
    }
}
