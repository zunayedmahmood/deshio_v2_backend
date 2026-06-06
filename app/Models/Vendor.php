<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AutoLogsActivity;

class Vendor extends Model
{
    use HasFactory, SoftDeletes, AutoLogsActivity;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'type',
        'email',
        'contact_person',
        'website',
        'credit_limit',
        'payment_terms',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Backward-compatible alias used by older lookup/product payloads.
     *
     * The vendors table stores the display label in `name`. Some legacy code
     * still exposes `company_name`, but that column is not present in the
     * current Deshio schema. Keep the response shape without querying a
     * non-existent SQL column.
     */
    public function getCompanyNameAttribute()
    {
        return $this->attributes['company_name'] ?? $this->attributes['name'] ?? null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function activeProducts()
    {
        return $this->products()->active();
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function payments()
    {
        return $this->hasMany(VendorPayment::class);
    }

    /**
     * Get total outstanding amount across all purchase orders
     */
    public function getTotalOutstanding()
    {
        return $this->purchaseOrders()
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->sum('outstanding_amount');
    }

    /**
     * Get total paid amount
     */
    public function getTotalPaid()
    {
        return $this->payments()
            ->where('status', 'completed')
            ->sum('amount');
    }

    /**
     * Check if vendor has exceeded credit limit
     */
    public function hasExceededCreditLimit(): bool
    {
        if (!$this->credit_limit) {
            return false;
        }

        return $this->getTotalOutstanding() > $this->credit_limit;
    }
}
