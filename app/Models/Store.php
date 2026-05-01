<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AutoLogsActivity;

class Store extends Model
{
    use HasFactory, SoftDeletes, AutoLogsActivity;

    protected $fillable = [
        'name',
        'address',
        'pathao_key',
        'pathao_store_id',  // NEW: Pathao Store ID for multi-store shipments
        'is_warehouse',
        'is_online',
        'phone',
        'email',
        'contact_person',
        'store_code',
        'description',
        'latitude',
        'longitude',
        'capacity',
        'is_active',
        'opening_hours',
    ];

    protected $casts = [
        'is_warehouse' => 'boolean',
        'is_online' => 'boolean',
        'is_active' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'capacity' => 'integer',
        'opening_hours' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeWarehouses($query)
    {
        return $query->where('is_warehouse', true);
    }

    public function scopeOnlineStores($query)
    {
        return $query->where('is_online', true);
    }

    public function scopePhysicalStores($query)
    {
        return $query->where('is_online', false)->where('is_warehouse', false);
    }

    public function getCoordinatesAttribute()
    {
        if ($this->latitude && $this->longitude) {
            return [
                'lat' => $this->latitude,
                'lng' => $this->longitude,
            ];
        }
        return null;
    }

    public function hasValidCoordinates()
    {
        return !is_null($this->latitude) && !is_null($this->longitude);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function activeEmployees()
    {
        return $this->employees()->active()->inService();
    }

    public function outgoingDispatches(): HasMany
    {
        return $this->hasMany(ProductDispatch::class, 'source_store_id');
    }

    public function incomingDispatches(): HasMany
    {
        return $this->hasMany(ProductDispatch::class, 'destination_store_id');
    }

    public function pendingOutgoingDispatches()
    {
        return $this->outgoingDispatches()->pending();
    }

    public function pendingIncomingDispatches()
    {
        return $this->incomingDispatches()->inTransit();
    }

    public function productBatches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function availableProductBatches()
    {
        return $this->productBatches()->available();
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ProductReturn::class);
    }
}
