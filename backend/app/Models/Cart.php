<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cart extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'product_id',
        'variant_options',
        'variant_hash',
        'quantity',
        'unit_price',
        'notes',
        'status',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'variant_options' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        // Automatically compute variant_hash when creating/updating
        static::saving(function ($cart) {
            $cart->variant_hash = $cart->computeVariantHash();
        });
    }

    /**
     * Compute MD5 hash of variant_options for database-agnostic unique indexing
     */
    public function computeVariantHash(): ?string
    {
        if (empty($this->variant_options)) {
            return null;
        }
        return md5(json_encode($this->variant_options));
    }

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSaved($query)
    {
        return $query->where('status', 'saved');
    }

    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    // Accessors
    public function getTotalPriceAttribute()
    {
        return $this->quantity * $this->unit_price;
    }

    // Business logic methods
    public function moveToSaved()
    {
        $this->status = 'saved';
        $this->save();
        return $this;
    }

    public function moveToActive()
    {
        $this->status = 'active';
        $this->save();
        return $this;
    }

    public function updateQuantity($quantity)
    {
        $this->quantity = $quantity;
        $this->save();
        return $this;
    }

    public function updatePrice()
    {
        $this->unit_price = $this->product->selling_price;
        $this->save();
        return $this;
    }

    // Static methods
    public static function getCartSummary($customerId)
    {
        $cartItems = static::forCustomer($customerId)->active()->get();
        
        return [
            'total_items' => $cartItems->sum('quantity'),
            'total_amount' => $cartItems->sum('total_price'),
            'unique_products' => $cartItems->count(),
        ];
    }

    public static function clearCustomerCart($customerId)
    {
        return static::forCustomer($customerId)->active()->delete();
    }

    public static function moveCustomerCartToSaved($customerId)
    {
        return static::forCustomer($customerId)->active()->update(['status' => 'saved']);
    }
}