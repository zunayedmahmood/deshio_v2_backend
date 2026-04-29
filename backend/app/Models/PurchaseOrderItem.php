<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\AutoLogsActivity;

class PurchaseOrderItem extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'product_batch_id',
        'product_name',
        'product_sku',
        'quantity_ordered',
        'quantity_received',
        'quantity_pending',
        'unit_cost',
        'unit_sell_price',
        'tax_amount',
        'discount_amount',
        'total_cost',
        'batch_number',
        'manufactured_date',
        'expiry_date',
        'receive_status',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_received' => 'integer',
        'quantity_pending' => 'integer',
        'unit_cost' => 'decimal:2',
        'unit_sell_price' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'manufactured_date' => 'date',
        'expiry_date' => 'date',
        'metadata' => 'array',
    ];

    /**
     * Relationships
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    /**
     * Boot method to handle automatic calculations
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($item) {
            // Calculate total cost
            $item->total_cost = ($item->unit_cost * $item->quantity_ordered) 
                - $item->discount_amount 
                + $item->tax_amount;

            // Calculate pending quantity
            $item->quantity_pending = $item->quantity_ordered - $item->quantity_received;
        });
    }

    /**
     * Receive quantity for this item
     */
    public function receive(int $quantity, array $batchData = []): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than 0');
        }

        if ($this->quantity_received + $quantity > $this->quantity_ordered) {
            throw new \InvalidArgumentException('Cannot receive more than ordered quantity');
        }

        $this->quantity_received += $quantity;
        $this->quantity_pending = $this->quantity_ordered - $this->quantity_received;

        // Update batch information if provided
        if (!empty($batchData)) {
            $this->batch_number = $batchData['batch_number'] ?? $this->batch_number;
            $this->manufactured_date = $batchData['manufactured_date'] ?? $this->manufactured_date;
            $this->expiry_date = $batchData['expiry_date'] ?? $this->expiry_date;
        }

        // Update receive status
        if ($this->quantity_received >= $this->quantity_ordered) {
            $this->receive_status = 'fully_received';
        } elseif ($this->quantity_received > 0) {
            $this->receive_status = 'partially_received';
        }

        $this->save();
    }

    /**
     * Cancel this item
     */
    public function cancel(): void
    {
        if ($this->quantity_received > 0) {
            throw new \Exception('Cannot cancel item that has already been received');
        }

        $this->receive_status = 'cancelled';
        $this->save();
    }

    /**
     * Check if item is fully received
     */
    public function isFullyReceived(): bool
    {
        return $this->receive_status === 'fully_received';
    }

    /**
     * Check if item is pending
     */
    public function isPending(): bool
    {
        return $this->receive_status === 'pending';
    }

    /**
     * Get remaining quantity to receive
     */
    public function getRemainingQuantity(): int
    {
        return $this->quantity_pending;
    }
}
