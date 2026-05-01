<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Wishlist extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'product_id',
        'notes',
        'wishlist_name',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

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
    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByWishlistName($query, $name)
    {
        return $query->where('wishlist_name', $name);
    }

    // Business logic methods
    public function moveToCart($quantity = 1)
    {
        $customer = $this->customer;
        $product = $this->product;

        // Check if already in cart
        $cartItem = Cart::where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->where('status', 'active')
            ->first();

        if ($cartItem) {
            $cartItem->increment('quantity', $quantity);
        } else {
            Cart::create([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $product->selling_price,
                'status' => 'active',
            ]);
        }

        return $this;
    }

    // Static methods
    public static function getWishlistStats($customerId)
    {
        $wishlists = static::forCustomer($customerId)->get();
        
        return [
            'total_items' => $wishlists->count(),
            'wishlist_names' => $wishlists->pluck('wishlist_name')->unique()->values(),
            'latest_added' => $wishlists->latest()->first(),
        ];
    }
}