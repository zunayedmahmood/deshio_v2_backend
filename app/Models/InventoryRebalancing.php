<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\AutoLogsActivity;

class InventoryRebalancing extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'product_id',
        'source_batch_id',
        'source_store_id',
        'destination_store_id',
        'quantity',
        'status',
        'priority',
        'reason',
        'estimated_cost',
        'actual_cost',
        'requested_at',
        'approved_at',
        'completed_at',
        'requested_by',
        'approved_by',
        'completed_by',
        'dispatch_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($rebalancing) {
            $rebalancing->requested_at = $rebalancing->requested_at ?? now();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'source_batch_id');
    }

    public function sourceStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'source_store_id');
    }

    public function destinationStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'destination_store_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'completed_by');
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(ProductDispatch::class, 'dispatch_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeInTransit($query)
    {
        return $query->where('status', 'in_transit');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['high', 'urgent']);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isInTransit(): bool
    {
        return $this->status === 'in_transit';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'pending';
    }

    public function canBeProcessed(): bool
    {
        return $this->status === 'approved';
    }

    public function canBeCompleted(): bool
    {
        return $this->status === 'in_transit';
    }

    public function approve(Employee $employee)
    {
        if (!$this->canBeApproved()) {
            throw new \Exception('Rebalancing request cannot be approved in its current state.');
        }

        $this->update([
            'status' => 'approved',
            'approved_by' => $employee->id,
            'approved_at' => now(),
        ]);

        return $this;
    }

    public function startTransit()
    {
        if (!$this->canBeProcessed()) {
            throw new \Exception('Rebalancing cannot be started in its current state.');
        }

        // Check if source batch has enough quantity
        if ($this->sourceBatch->quantity < $this->quantity) {
            throw new \Exception('Insufficient quantity in source batch.');
        }

        $this->update(['status' => 'in_transit']);

        return $this;
    }

    public function complete(Employee $employee, $actualCost = null)
    {
        if (!$this->canBeCompleted()) {
            throw new \Exception('Rebalancing cannot be completed in its current state.');
        }

        // Move inventory from source to destination
        $this->processInventoryMovement();

        $this->update([
            'status' => 'completed',
            'completed_by' => $employee->id,
            'completed_at' => now(),
            'actual_cost' => $actualCost,
        ]);

        // Record the movement
        ProductMovement::recordMovement([
            'product_batch_id' => $this->source_batch_id,
            'product_barcode_id' => $this->sourceBatch->barcode_id,
            'from_store_id' => $this->source_store_id,
            'to_store_id' => $this->destination_store_id,
            'movement_type' => 'transfer',
            'quantity' => $this->quantity,
            'unit_cost' => $this->sourceBatch->cost_price,
            'unit_price' => $this->sourceBatch->sell_price,
            'reference_number' => 'REBAL-' . $this->id,
            'notes' => 'Inventory rebalancing: ' . $this->reason,
            'performed_by' => $employee->id,
        ]);

        // Sync master inventory
        MasterInventory::syncProductInventory($this->product_id);

        return $this;
    }

    protected function processInventoryMovement()
    {
        $sourceBatch = $this->sourceBatch;

        // Reduce quantity in source batch
        $sourceBatch->removeStock($this->quantity);

        // Create new batch at destination store
        $destinationBatch = ProductBatch::create([
            'product_id' => $sourceBatch->product_id,
            'batch_number' => $sourceBatch->batch_number . '-REBAL-' . $this->id,
            'quantity' => $this->quantity,
            'cost_price' => $sourceBatch->cost_price,
            'sell_price' => $sourceBatch->sell_price,
            'availability' => true,
            'manufactured_date' => $sourceBatch->manufactured_date,
            'expiry_date' => $sourceBatch->expiry_date,
            'store_id' => $this->destination_store_id,
            'barcode_id' => $sourceBatch->barcode_id,
            'notes' => 'Rebalanced from store ' . $this->source_store_id,
            'is_active' => true,
        ]);

        return $destinationBatch;
    }

    public function cancel()
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            throw new \Exception('Cannot cancel a completed or already cancelled rebalancing.');
        }

        $this->update(['status' => 'cancelled']);

        return $this;
    }

    public static function createRebalancing(array $data)
    {
        // Validate that source has enough stock
        $sourceBatch = ProductBatch::find($data['source_batch_id']);
        if (!$sourceBatch || $sourceBatch->quantity < $data['quantity']) {
            throw new \Exception('Insufficient stock in source batch.');
        }

        return static::create($data);
    }

    public static function getPendingRebalancings()
    {
        return static::pending()
                    ->with(['product', 'sourceStore', 'destinationStore', 'requestedBy'])
                    ->orderBy('priority', 'desc')
                    ->orderBy('requested_at')
                    ->get();
    }

    public static function getRebalancingSuggestions()
    {
        $suggestions = [];

        // Find overstocked items
        $overstockedItems = MasterInventory::overstocked()
                                          ->with('product')
                                          ->get();

        // Find understocked stores for those products
        foreach ($overstockedItems as $overstocked) {
            $storeBreakdown = $overstocked->getStoreQuantities();

            // Find stores with low stock of this product
            $lowStockStores = [];
            foreach ($storeBreakdown as $storeId => $quantity) {
                if ($quantity <= $overstocked->minimum_stock_level) {
                    $lowStockStores[] = $storeId;
                }
            }

            if (!empty($lowStockStores)) {
                $suggestions[] = [
                    'product' => $overstocked->product,
                    'overstocked_stores' => array_keys(array_filter($storeBreakdown, function($qty) use ($overstocked) {
                        return $qty > $overstocked->maximum_stock_level;
                    })),
                    'understocked_stores' => $lowStockStores,
                    'suggested_quantity' => min(
                        $overstocked->available_quantity - $overstocked->maximum_stock_level,
                        $overstocked->minimum_stock_level
                    ),
                ];
            }
        }

        return $suggestions;
    }

    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'pending' => 'gray',
            'approved' => 'blue',
            'in_transit' => 'yellow',
            'completed' => 'green',
            'cancelled' => 'red',
            default => 'gray',
        };
    }

    public function getPriorityColorAttribute()
    {
        return match($this->priority) {
            'low' => 'gray',
            'medium' => 'blue',
            'high' => 'orange',
            'urgent' => 'red',
            default => 'gray',
        };
    }
}