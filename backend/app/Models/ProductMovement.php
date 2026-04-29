<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\AutoLogsActivity;

class ProductMovement extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'product_batch_id',
        'product_barcode_id',
        'from_store_id',
        'to_store_id',
        'product_dispatch_id',
        'movement_type',
        'quantity',
        'unit_cost',
        'unit_price',
        'total_cost',
        'total_value',
        'movement_date',
        'reference_number',
        'reference_type',       // NEW: Type of reference (order, dispatch, return, etc.)
        'reference_id',         // NEW: ID of referenced record
        'status_before',        // NEW: Status before movement
        'status_after',         // NEW: Status after movement
        'notes',
        'performed_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
        'movement_date' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($movement) {
            $movement->movement_date = $movement->movement_date ?? now();

            // Ensure we have at least 0 for cost and price to satisfy DB constraints
            $movement->unit_cost = $movement->unit_cost ?? 0;
            $movement->unit_price = $movement->unit_price ?? 0;

            if (empty($movement->total_cost)) {
                $movement->total_cost = $movement->quantity * $movement->unit_cost;
            }

            if (empty($movement->total_value)) {
                $movement->total_value = $movement->quantity * $movement->unit_price;
            }
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function barcode(): BelongsTo
    {
        return $this->belongsTo(ProductBarcode::class, 'product_barcode_id');
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id');
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id');
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(ProductDispatch::class, 'product_dispatch_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'performed_by');
    }

    public function product()
    {
        return $this->batch->product();
    }

    public function scopeByBatch($query, $batchId)
    {
        return $query->where('product_batch_id', $batchId);
    }

    public function scopeByBarcode($query, $barcodeId)
    {
        return $query->where('product_barcode_id', $barcodeId);
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where(function ($q) use ($storeId) {
            $q->where('from_store_id', $storeId)
              ->orWhere('to_store_id', $storeId);
        });
    }

    public function scopeByMovementType($query, $type)
    {
        return $query->where('movement_type', $type);
    }

    public function scopeDispatches($query)
    {
        return $query->where('movement_type', 'dispatch');
    }

    public function scopeTransfers($query)
    {
        return $query->where('movement_type', 'transfer');
    }

    public function scopeReturns($query)
    {
        return $query->where('movement_type', 'return');
    }

    public function scopeAdjustments($query)
    {
        return $query->where('movement_type', 'adjustment');
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('movement_date', [$startDate, $endDate]);
    }

    public function getMovementDescriptionAttribute()
    {
        $fromStore = $this->fromStore ? $this->fromStore->name : 'External';
        $toStore = $this->toStore->name;

        return match($this->movement_type) {
            'dispatch' => "Dispatched from {$fromStore} to {$toStore}",
            'transfer' => "Transferred from {$fromStore} to {$toStore}",
            'return' => "Returned from {$fromStore} to {$toStore}",
            'adjustment' => "Inventory adjusted at {$toStore}",
            default => "Moved from {$fromStore} to {$toStore}",
        };
    }

    public function getFormattedTotalCostAttribute()
    {
        return $this->total_cost ? number_format((float) $this->total_cost, 2) : '0.00';
    }

    public function getFormattedTotalValueAttribute()
    {
        return $this->total_value ? number_format((float) $this->total_value, 2) : '0.00';
    }

    public static function recordMovement(array $data)
    {
        return static::create($data);
    }

    public static function getProductLocationHistory($barcodeId)
    {
        return static::byBarcode($barcodeId)
                    ->with(['fromStore', 'toStore', 'batch.product', 'performedBy'])
                    ->orderBy('movement_date', 'desc')
                    ->get();
    }

    public static function getCurrentLocation($barcodeId)
    {
        return static::byBarcode($barcodeId)
                    ->with('toStore')
                    ->orderBy('movement_date', 'desc')
                    ->first()
                    ?->toStore;
    }

    public static function getStoreInventoryMovements($storeId, $startDate = null, $endDate = null)
    {
        $query = static::byStore($storeId)->with(['batch.product', 'barcode', 'performedBy']);

        if ($startDate && $endDate) {
            $query->dateRange($startDate, $endDate);
        }

        return $query->orderBy('movement_date', 'desc')->get();
    }
}