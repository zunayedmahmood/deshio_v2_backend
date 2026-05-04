<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\AutoLogsActivity;

class ProductDispatch extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'source_store_id',
        'destination_store_id',
        'dispatch_number',
        'status',
        'dispatch_date',
        'expected_delivery_date',
        'actual_delivery_date',
        'carrier_name',
        'tracking_number',
        'total_cost',
        'total_value',
        'total_items',
        'notes',
        'metadata',
        'created_by',
        'approved_by',
        'approved_at',
        // For Pathao delivery tracking
        'for_pathao_delivery',
        'customer_id',
        'order_id',
        'customer_delivery_info',
        'shipment_id',
    ];

    protected $casts = [
        'dispatch_date' => 'datetime',
        'expected_delivery_date' => 'datetime',
        'actual_delivery_date' => 'datetime',
        'approved_at' => 'datetime',
        'total_cost' => 'decimal:2',
        'total_value' => 'decimal:2',
        'total_items' => 'integer',
        'metadata' => 'array',
        'for_pathao_delivery' => 'boolean',
        'customer_delivery_info' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($dispatch) {
            if (empty($dispatch->dispatch_number)) {
                $dispatch->dispatch_number = static::generateDispatchNumber();
            }
            $dispatch->dispatch_date = $dispatch->dispatch_date ?? now();
        });
    }

    public function sourceStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'source_store_id');
    }

    public function destinationStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'destination_store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductDispatchItem::class, 'product_dispatch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInTransit($query)
    {
        return $query->where('status', 'in_transit');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeBySourceStore($query, $storeId)
    {
        return $query->where('source_store_id', $storeId);
    }

    public function scopeByDestinationStore($query, $storeId)
    {
        return $query->where('destination_store_id', $storeId);
    }

    public function scopeByDispatchNumber($query, $dispatchNumber)
    {
        return $query->where('dispatch_number', $dispatchNumber);
    }

    public function scopeOverdue($query)
    {
        return $query->where('expected_delivery_date', '<', now())
                    ->whereIn('status', ['pending', 'in_transit']);
    }

    public function scopeExpectedToday($query)
    {
        return $query->whereDate('expected_delivery_date', today())
                    ->whereIn('status', ['pending', 'in_transit']);
    }

    public function scopeForPathaoDelivery($query)
    {
        return $query->where('for_pathao_delivery', true);
    }

    public function scopePendingPathaoShipment($query)
    {
        return $query->where('for_pathao_delivery', true)
                    ->where('status', 'delivered')  // Delivered to warehouse
                    ->whereNull('shipment_id');     // But no shipment created yet
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isInTransit(): bool
    {
        return $this->status === 'in_transit';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isOverdue(): bool
    {
        return $this->expected_delivery_date && $this->expected_delivery_date->isPast()
               && in_array($this->status, ['pending', 'in_transit']);
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'pending' && is_null($this->approved_by);
    }

    public function canBeDispatched(): bool
    {
        return $this->status === 'pending' && !is_null($this->approved_by);
    }

    public function canBeDelivered(): bool
    {
        return $this->status === 'in_transit';
    }

    public function approve(Employee $employee)
    {
        if (!$this->canBeApproved()) {
            throw new \Exception('Dispatch cannot be approved in its current state.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($employee) {
            $this->update([
                'approved_by' => $employee->id,
                'approved_at' => now(),
            ]);

            return $this;
        });
    }

    public function dispatch()
    {
        if (!$this->canBeDispatched()) {
            throw new \Exception('Dispatch cannot be sent in its current state.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () {
            $this->update(['status' => 'in_transit']);

            // Update item statuses and barcode statuses
            foreach ($this->items as $item) {
                $item->update(['status' => 'dispatched']);
                
                // Load scanned barcodes
                $item->load('scannedBarcodes');
                
                if ($item->scannedBarcodes->count() > 0) {
                    // Barcodes were scanned - update their status from 'reserved' to 'in_transit'
                    foreach ($item->scannedBarcodes as $barcode) {
                        $barcode->update([
                            'current_status' => 'in_transit',
                            'location_updated_at' => now(),
                            'location_metadata' => array_merge($barcode->location_metadata ?? [], [
                                'dispatched_at' => now()->toISOString(),
                                'status_changed_to_in_transit' => now()->toISOString(),
                            ])
                        ]);
                    }
                } else {
                    // ERROR: Cannot dispatch without scanning barcodes!
                    throw new \Exception(
                        "Cannot dispatch item without scanning barcodes. " .
                        "Please scan {$item->quantity} barcode(s) for this item before sending."
                    );
                }
            }

            return $this;
        });
    }

    public function deliver()
    {
        if (!$this->canBeDelivered()) {
            throw new \Exception('Dispatch cannot be delivered in its current state.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () {
            $this->update([
                'status' => 'delivered',
                'actual_delivery_date' => now(),
            ]);

            // Process inventory movement for each item
            foreach ($this->items as $item) {
                $this->processInventoryMovement($item);
            }

            return $this;
        });
    }

    public function cancel()
    {
        if (in_array($this->status, ['delivered', 'cancelled'])) {
            throw new \Exception('Cannot cancel a delivered or already cancelled dispatch.');
        }

        $this->update(['status' => 'cancelled']);

        return $this;
    }

    public function addItem(ProductBatch $batch, int $quantity)
    {
        $barcodeAtSourceStore = $batch->barcodes()
    ->where('current_store_id', (int)$this->source_store_id)
    ->where('is_active', true)
    ->exists();

if (!$barcodeAtSourceStore) {
    throw new \Exception('Batch does not belong to the source store.');
}

        if ($batch->quantity < $quantity) {
            throw new \Exception('Insufficient quantity in batch.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($batch, $quantity) {
            $item = $this->items()->create([
                'product_batch_id' => $batch->id,
                'quantity' => $quantity,
            ]);

            $this->updateTotals();

            return $item;
        });
    }

    public function removeItem(ProductDispatchItem $item)
    {
        if ($item->dispatch_id !== $this->id) {
            throw new \Exception('Item does not belong to this dispatch.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($item) {
            $item->delete();
            $this->updateTotals();

            return $this;
        });
    }

    public function updateTotals()
    {
        $totals = $this->items()->selectRaw('
            COUNT(*) as total_items,
            SUM(total_cost) as total_cost,
            SUM(total_value) as total_value
        ')->first();

        $this->update([
            'total_items' => $totals->total_items ?? 0,
            'total_cost' => $totals->total_cost ?? 0,
            'total_value' => $totals->total_value ?? 0,
        ]);

        return $this;
    }

    public function getTotalWeightAttribute()
    {
        // Assuming products have weight, this would need to be implemented
        // based on your product model structure
        return $this->items->sum(function ($item) {
            // return $item->batch->product->weight * $item->quantity;
            return 0; // Placeholder
        });
    }

    public function getDeliveryStatusAttribute()
    {
        if ($this->isOverdue()) {
            return 'overdue';
        }

        if ($this->expected_delivery_date && $this->expected_delivery_date->isToday()) {
            return 'due_today';
        }

        return $this->status;
    }

    public function getFormattedTotalCostAttribute()
    {
        return $this->total_cost ? number_format((float) $this->total_cost, 2) : '0.00';
    }

    public function getFormattedTotalValueAttribute()
    {
        return $this->total_value ? number_format((float) $this->total_value, 2) : '0.00';
    }

    public function getItemsSummaryAttribute()
    {
        return $this->items->groupBy('batch.product.name')->map(function ($items, $productName) {
            return [
                'product_name' => $productName,
                'total_quantity' => $items->sum('quantity'),
                'total_cost' => $items->sum('total_cost'),
                'total_value' => $items->sum('total_value'),
            ];
        });
    }

    public static function generateDispatchNumber(): string
    {
        do {
            $dispatchNumber = 'DSP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
        } while (static::where('dispatch_number', $dispatchNumber)->exists());

        return $dispatchNumber;
    }

    public static function getPendingCount()
    {
        return static::pending()->count();
    }

    public static function getInTransitCount()
    {
        return static::inTransit()->count();
    }

    public static function getOverdueCount()
    {
        return static::overdue()->count();
    }

    protected function processInventoryMovement(ProductDispatchItem $item)
    {
        $sourceBatch = $item->batch;
        $receivedQuantity = $item->received_quantity ?? $item->quantity;

        // Reduce quantity in source batch
        $sourceBatch->removeStock($item->quantity);

        // Create new batch at destination store
        $destinationBatch = ProductBatch::create([
            'product_id' => $sourceBatch->product_id,
            'batch_number' => $sourceBatch->batch_number . '-DST-' . $this->dispatch_number,
            'quantity' => $receivedQuantity,
            'cost_price' => $sourceBatch->cost_price,
            'sell_price' => $sourceBatch->sell_price,
            'availability' => true,
            'manufactured_date' => $sourceBatch->manufactured_date,
            'expiry_date' => $sourceBatch->expiry_date,
            'store_id' => $this->destination_store_id,
            'barcode_id' => $sourceBatch->barcode_id,
            'notes' => 'Received via dispatch ' . $this->dispatch_number,
            'is_active' => true,
        ]);

        // Load scanned barcodes relationship and transfer individual barcodes if they are tracked
        $item->load('scannedBarcodes');
        if ($item->scannedBarcodes && $item->scannedBarcodes->count() > 0) {
            foreach ($item->scannedBarcodes as $scannedBarcode) {
                // Get the actual barcode record, not the pivot
                $barcode = $scannedBarcode;
                
                if ($barcode) {
                    // Update barcode location to destination store and new batch
                    $barcode->update([
                        'batch_id' => $destinationBatch->id,           // ✅ Update to new destination batch
                        'current_store_id' => $this->destination_store_id,  // ✅ Update current location
                        'current_status' => 'in_warehouse',           // ✅ Back in stock at destination
                        'location_updated_at' => now(),               // ✅ Track when updated
                        'location_metadata' => [                      // ✅ Add metadata about the transfer
                            'transferred_via' => 'dispatch',
                            'dispatch_number' => $this->dispatch_number,
                            'source_store_id' => $this->source_store_id,
                            'source_batch_id' => $sourceBatch->id,
                            'destination_batch_id' => $destinationBatch->id,
                            'transfer_date' => now()->toISOString(),
                            'delivered_at' => now()->toISOString(),
                        ]
                    ]);
                    
                    // Record individual barcode movement
                    ProductMovement::recordMovement([
                        'product_batch_id' => $destinationBatch->id,
                        'product_barcode_id' => $barcode->id,
                        'from_store_id' => $this->source_store_id,
                        'to_store_id' => $this->destination_store_id,
                        'product_dispatch_id' => $this->id,
                        'movement_type' => 'transfer',
                        'quantity' => 1,
                        'unit_cost' => $sourceBatch->cost_price,
                        'unit_price' => $sourceBatch->sell_price,
                        'reference_number' => $this->dispatch_number,
                        'notes' => 'Individual barcode transfer delivered: ' . $barcode->barcode,
                        'performed_by' => $this->approved_by ?? $this->created_by,
                        'movement_date' => now(),
                    ]);
                }
            }
        } else {
            // If no individual barcodes scanned, update all barcodes of the source batch that are at the source store
            $barcodesToUpdate = ProductBarcode::where('batch_id', $sourceBatch->id)
                ->where('current_store_id', $this->source_store_id)
                ->where('current_status', 'in_transit')
                ->limit($item->quantity)
                ->get();

            foreach ($barcodesToUpdate as $barcode) {
                $barcode->update([
                    'batch_id' => $destinationBatch->id,           // ✅ Update to new destination batch
                    'current_store_id' => $this->destination_store_id,  // ✅ Update current location
                    'current_status' => 'in_warehouse',           // ✅ Back in stock at destination
                    'location_updated_at' => now(),               // ✅ Track when updated
                    'location_metadata' => [                      // ✅ Add metadata about the transfer
                        'transferred_via' => 'dispatch',
                        'dispatch_number' => $this->dispatch_number,
                        'source_store_id' => $this->source_store_id,
                        'source_batch_id' => $sourceBatch->id,
                        'destination_batch_id' => $destinationBatch->id,
                        'transfer_date' => now()->toISOString(),
                        'delivered_at' => now()->toISOString(),
                    ]
                ]);
                
                // Record individual barcode movement
                ProductMovement::recordMovement([
                    'product_batch_id' => $destinationBatch->id,
                    'product_barcode_id' => $barcode->id,
                    'from_store_id' => $this->source_store_id,
                    'to_store_id' => $this->destination_store_id,
                    'product_dispatch_id' => $this->id,
                    'movement_type' => 'transfer',
                    'quantity' => 1,
                    'unit_cost' => $sourceBatch->cost_price,
                    'unit_price' => $sourceBatch->sell_price,
                    'reference_number' => $this->dispatch_number,
                    'notes' => 'Batch barcode transfer delivered: ' . $barcode->barcode,
                    'performed_by' => $this->approved_by ?? $this->created_by,
                    'movement_date' => now(),
                ]);
            }
            
            // Record batch-level movement
            ProductMovement::recordMovement([
                'product_batch_id' => $destinationBatch->id,
                'product_barcode_id' => $sourceBatch->barcode_id,
                'from_store_id' => $this->source_store_id,
                'to_store_id' => $this->destination_store_id,
                'product_dispatch_id' => $this->id,
                'movement_type' => 'dispatch',
                'quantity' => $receivedQuantity,
                'unit_cost' => $sourceBatch->cost_price,
                'unit_price' => $sourceBatch->sell_price,
                'reference_number' => $this->dispatch_number,
                'notes' => 'Product dispatch delivery completed (batch level)',
                'performed_by' => $this->approved_by ?? $this->created_by,
                'movement_date' => now(),
            ]);
        }

        // Update dispatch item to reference the new destination batch
        $item->update([
            'status' => 'received',
            'product_batch_id' => $destinationBatch->id,
        ]);

        return $destinationBatch;
    }

    /**
     * Check if this dispatch is for Pathao delivery
     */
    public function isForPathaoDelivery(): bool
    {
        return $this->for_pathao_delivery === true;
    }

    /**
     * Check if shipment has been created for this dispatch
     */
    public function hasShipment(): bool
    {
        return $this->shipment_id !== null;
    }

    /**
     * Check if this dispatch is ready for shipment creation
     * (delivered to warehouse but no shipment created yet)
     */
    public function isReadyForShipment(): bool
    {
        return $this->isForPathaoDelivery() 
            && $this->status === 'delivered' 
            && !$this->hasShipment();
    }

    /**
     * Get customer delivery information
     */
    public function getCustomerDeliveryInfo(): array
    {
        return $this->customer_delivery_info ?? [];
    }

    /**
     * Create shipment from this dispatch
     */
    public function createShipmentForDelivery(): Shipment
    {
        if (!$this->isReadyForShipment()) {
            throw new \Exception('Dispatch is not ready for shipment creation');
        }

        $deliveryInfo = $this->getCustomerDeliveryInfo();

        $shipment = Shipment::create([
            'order_id' => $this->order_id,
            'customer_id' => $this->customer_id,
            'store_id' => $this->destination_store_id,  // Warehouse
            'delivery_type' => $deliveryInfo['delivery_type'] ?? 'home_delivery',
            'package_weight' => $deliveryInfo['package_weight'] ?? 1.0,
            'special_instructions' => $deliveryInfo['special_instructions'] ?? $this->notes,
            'pickup_address' => [
                'name' => $this->destinationStore->name,
                'phone' => $this->destinationStore->phone,
                'street' => $this->destinationStore->address,
                'area' => $this->destinationStore->area,
                'city' => $this->destinationStore->city,
                'postal_code' => $this->destinationStore->postal_code,
            ],
            'delivery_address' => $deliveryInfo['delivery_address'] ?? [],
            'recipient_name' => $deliveryInfo['recipient_name'] ?? $this->customer->name,
            'recipient_phone' => $deliveryInfo['recipient_phone'] ?? $this->customer->phone,
            'cod_amount' => $deliveryInfo['cod_amount'] ?? 0,
            'created_by' => auth()->id(),
        ]);

        // Link shipment to dispatch
        $this->update(['shipment_id' => $shipment->id]);

        return $shipment;
    }
}
