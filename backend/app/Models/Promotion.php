<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'discount_value',
        'buy_quantity',
        'get_quantity',
        'minimum_purchase',
        'maximum_discount',
        'applicable_products',
        'applicable_categories',
        'applicable_customers',
        'usage_limit',
        'usage_per_customer',
        'usage_count',
        'start_date',
        'end_date',
        'is_active',
        'is_public',
        'is_automatic',
        'created_by',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'minimum_purchase' => 'decimal:2',
        'maximum_discount' => 'decimal:2',
        'buy_quantity' => 'integer',
        'get_quantity' => 'integer',
        'usage_limit' => 'integer',
        'usage_per_customer' => 'integer',
        'usage_count' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'is_automatic' => 'boolean',
        'applicable_products' => 'array',
        'applicable_categories' => 'array',
        'applicable_customers' => 'array',
    ];

    // Relationships
    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeValid($query)
    {
        return $query->where('is_active', true)
            ->where('start_date', '<=', now())
            ->where(function($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now());
            });
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    // Business logic methods
    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->start_date > now()) {
            return false;
        }

        if ($this->end_date && $this->end_date < now()) {
            return false;
        }

        if ($this->usage_limit && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    public function canBeUsedBy(Customer $customer, $orderTotal = null): array
    {
        $errors = [];

        if (!$this->isValid()) {
            $errors[] = 'Promotion is not valid';
        }

        // Check customer eligibility
        if ($this->applicable_customers && !empty($this->applicable_customers)) {
            $customerMatch = in_array($customer->id, $this->applicable_customers) ||
                           in_array($customer->type, $this->applicable_customers);
            
            if (!$customerMatch) {
                $errors[] = 'Promotion not applicable to this customer';
            }
        }

        // Check minimum purchase
        if ($this->minimum_purchase && $orderTotal && $orderTotal < $this->minimum_purchase) {
            $errors[] = "Minimum purchase of {$this->minimum_purchase} required";
        }

        // Check usage per customer
        if ($this->usage_per_customer) {
            $customerUsageCount = $this->usages()->where('customer_id', $customer->id)->count();
            if ($customerUsageCount >= $this->usage_per_customer) {
                $errors[] = 'Usage limit per customer exceeded';
            }
        }

        return [
            'can_use' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function calculateDiscount($orderTotal, $items = []): float
    {
        if (!$this->isValid()) {
            return 0;
        }

        $discount = 0;

        switch ($this->type) {
            case 'percentage':
                $discount = ($orderTotal * $this->discount_value) / 100;
                break;

            case 'fixed':
                $discount = $this->discount_value;
                break;

            case 'buy_x_get_y':
                // Calculate based on item quantities
                $discount = $this->calculateBuyXGetYDiscount($items);
                break;

            case 'free_shipping':
                // Handled separately in shipping calculation
                $discount = 0;
                break;
        }

        // Apply maximum discount cap if set
        if ($this->maximum_discount && $discount > $this->maximum_discount) {
            $discount = $this->maximum_discount;
        }

        // Discount cannot exceed order total
        if ($discount > $orderTotal) {
            $discount = $orderTotal;
        }

        return round($discount, 2);
    }

    private function calculateBuyXGetYDiscount($items): float
    {
        if (!$this->buy_quantity || !$this->get_quantity) {
            return 0;
        }

        $discount = 0;
        $applicableProducts = $this->applicable_products ?? [];

        foreach ($items as $item) {
            // Check if item is applicable
            if (!empty($applicableProducts) && !in_array($item['product_id'], $applicableProducts)) {
                continue;
            }

            $quantity = $item['quantity'];
            $unitPrice = $item['unit_price'];

            // Calculate how many free items customer gets
            $sets = floor($quantity / $this->buy_quantity);
            $freeItems = $sets * $this->get_quantity;
            
            $discount += $freeItems * $unitPrice;
        }

        return $discount;
    }

    public function recordUsage(Order $order, Customer $customer, $discountAmount): PromotionUsage
    {
        $usage = $this->usages()->create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'discount_amount' => $discountAmount,
            'used_at' => now(),
        ]);

        $this->increment('usage_count');

        return $usage;
    }

    public function getRemainingUsage(): ?int
    {
        if (!$this->usage_limit) {
            return null; // Unlimited
        }

        return max(0, $this->usage_limit - $this->usage_count);
    }

    public function getCustomerRemainingUsage(Customer $customer): ?int
    {
        if (!$this->usage_per_customer) {
            return null; // Unlimited
        }

        $used = $this->usages()->where('customer_id', $customer->id)->count();
        return max(0, $this->usage_per_customer - $used);
    }

    // Accessors
    public function getStatusAttribute(): string
    {
        if (!$this->is_active) {
            return 'inactive';
        }

        if ($this->start_date > now()) {
            return 'scheduled';
        }

        if ($this->end_date && $this->end_date < now()) {
            return 'expired';
        }

        if ($this->usage_limit && $this->usage_count >= $this->usage_limit) {
            return 'exhausted';
        }

        return 'active';
    }

    public function getTypeLabeLAttribute(): string
    {
        return match($this->type) {
            'percentage' => 'Percentage Discount',
            'fixed' => 'Fixed Amount Discount',
            'buy_x_get_y' => 'Buy X Get Y',
            'free_shipping' => 'Free Shipping',
            default => 'Unknown',
        };
    }

    public function getDiscountDescriptionAttribute(): string
    {
        return match($this->type) {
            'percentage' => "{$this->discount_value}% off",
            'fixed' => "৳{$this->discount_value} off",
            'buy_x_get_y' => "Buy {$this->buy_quantity} Get {$this->get_quantity} Free",
            'free_shipping' => "Free Shipping",
            default => '',
        };
    }
}

