<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerAddress extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'type',
        'name',
        'phone',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'pathao_city_id',
        'pathao_zone_id',
        'pathao_area_id',
        'landmark',
        'is_default_shipping',
        'is_default_billing',
        'delivery_instructions',
    ];

    protected $casts = [
        'is_default_shipping' => 'boolean',
        'is_default_billing' => 'boolean',
    ];

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // Scopes
    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeShippingAddresses($query)
    {
        return $query->where('type', 'shipping')->orWhere('type', 'both');
    }

    public function scopeBillingAddresses($query)
    {
        return $query->where('type', 'billing')->orWhere('type', 'both');
    }

    public function scopeDefaultShipping($query)
    {
        return $query->where('is_default_shipping', true);
    }

    public function scopeDefaultBilling($query)
    {
        return $query->where('is_default_billing', true);
    }

    // Accessors
    public function getFullAddressAttribute()
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->landmark,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    public function getFormattedAddressAttribute()
    {
        return [
            'name' => $this->name,
            'phone' => $this->phone,
            'street' => trim($this->address_line_1 . ' ' . $this->address_line_2),
            'landmark' => $this->landmark,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'country' => $this->country ?? 'Bangladesh',
            'delivery_instructions' => $this->delivery_instructions,
        ];
    }

    // Business logic methods
    public function makeDefaultShipping()
    {
        // Remove default from other shipping addresses
        static::forCustomer($this->customer_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default_shipping' => false]);

        $this->is_default_shipping = true;
        $this->save();
        return $this;
    }

    public function makeDefaultBilling()
    {
        // Remove default from other billing addresses
        static::forCustomer($this->customer_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default_billing' => false]);

        $this->is_default_billing = true;
        $this->save();
        return $this;
    }

    public function isShippingAddress(): bool
    {
        return in_array($this->type, ['shipping', 'both']);
    }

    public function isBillingAddress(): bool
    {
        return in_array($this->type, ['billing', 'both']);
    }

    public function canDelete(): bool
    {
        // Don't allow deletion if it's the only address
        $addressCount = static::forCustomer($this->customer_id)->count();
        return $addressCount > 1;
    }

    // Static methods
    public static function getDefaultShippingForCustomer($customerId)
    {
        return static::forCustomer($customerId)
            ->shippingAddresses()
            ->defaultShipping()
            ->first();
    }

    public static function getDefaultBillingForCustomer($customerId)
    {
        return static::forCustomer($customerId)
            ->billingAddresses()
            ->defaultBilling()
            ->first();
    }

    public static function createAddress(array $data, $customerId)
    {
        $data['customer_id'] = $customerId;
        
        $address = static::create($data);

        // If this is the first address, make it default for both
        $addressCount = static::forCustomer($customerId)->count();
        if ($addressCount === 1) {
            $address->update([
                'is_default_shipping' => true,
                'is_default_billing' => true,
            ]);
        }

        return $address;
    }
}