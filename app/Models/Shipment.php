<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\PathaoService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Traits\AutoLogsActivity;

class Shipment extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'shipment_number',
        'order_id',
        'customer_id',
        'store_id', // Multi-store: Each shipment belongs to one store
        'pathao_consignment_id',
        'pathao_tracking_number',
        'pathao_status',
        'pathao_response',
        'status',
        'delivery_type',
        'delivery_fee',
        'cod_amount',
        'carrier_name', // NEW: Pathao, etc.
        'item_quantity', // NEW: Number of items in this shipment
        'item_weight', // NEW: Total weight for this shipment
        'amount_to_collect', // NEW: COD amount for this shipment
        'recipient_address', // NEW: Recipient full address
        'metadata', // NEW: Store Pathao store_id, items list, etc.
        'package_weight',
        'package_dimensions',
        'special_instructions',
        'pickup_address',
        'delivery_address',
        'package_barcodes',
        'pickup_requested_at',
        'picked_up_at',
        'shipped_at',
        'delivered_at',
        'returned_at',
        'cancelled_at',
        'estimated_delivery_date',
        'created_by',
        'processed_by',
        'delivered_by',
        'delivery_notes',
        'recipient_name',
        'recipient_phone',
        'recipient_signature',
        'status_history',
    ];

    protected $casts = [
        'pathao_response' => 'array',
        'delivery_fee' => 'decimal:2',
        'cod_amount' => 'decimal:2',
        'amount_to_collect' => 'decimal:2', // NEW
        'item_weight' => 'decimal:2', // NEW
        'package_weight' => 'decimal:2',
        'package_dimensions' => 'array',
        'pickup_address' => 'array',
        'delivery_address' => 'array',
        'package_barcodes' => 'array',
        'metadata' => 'array', // NEW: Store metadata
        'pickup_requested_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'estimated_delivery_date' => 'datetime',
        'status_history' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($shipment) {
            if (empty($shipment->shipment_number)) {
                $shipment->shipment_number = static::generateShipmentNumber();
            }

            // Initialize status history
            $shipment->status_history = [
                [
                    'status' => 'pending',
                    'timestamp' => now()->toISOString(),
                    'note' => 'Shipment created'
                ]
            ];
        });
    }

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Multi-store: Each shipment belongs to one store
     */
    // public function store(): BelongsTo
    // {
    //     return $this->belongsTo(Store::class);
    // }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processed_by');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'delivered_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePickupRequested($query)
    {
        return $query->where('status', 'pickup_requested');
    }

    public function scopePickedUp($query)
    {
        return $query->where('status', 'picked_up');
    }

    public function scopeInTransit($query)
    {
        return $query->where('status', 'in_transit');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeReturned($query)
    {
        return $query->where('status', 'returned');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByOrder($query, $orderId)
    {
        return $query->where('order_id', $orderId);
    }

    public function scopeHomeDelivery($query)
    {
        return $query->where('delivery_type', 'home_delivery');
    }

    public function scopeStorePickup($query)
    {
        return $query->where('delivery_type', 'store_pickup');
    }

    public function scopeExpress($query)
    {
        return $query->where('delivery_type', 'express');
    }

    // Status checks
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPickupRequested(): bool
    {
        return $this->status === 'pickup_requested';
    }

    public function isPickedUp(): bool
    {
        return $this->status === 'picked_up';
    }

    public function isInTransit(): bool
    {
        return $this->status === 'in_transit';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isReturned(): bool
    {
        return $this->status === 'returned';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    // Pathao Integration Methods
    public function requestPickup()
    {
        if (!$this->isPending()) {
            throw new \Exception('Shipment must be in pending status to request pickup');
        }

        try {
            $response = $this->callPathaoAPI('POST', 'orders', $this->preparePathaoOrderData());

            if ($response->successful()) {
                $data = $response->json();

                $this->pathao_consignment_id = $data['data']['consignment_id'] ?? null;
                $this->pathao_tracking_number = $data['data']['tracking_number'] ?? null;
                $this->pathao_status = 'pickup_requested';
                $this->pathao_response = $data;
                $this->status = 'pickup_requested';
                $this->pickup_requested_at = now();
                $this->delivery_fee = $data['data']['delivery_fee'] ?? $this->delivery_fee;

                $this->addStatusHistory('pickup_requested', 'Pickup requested from Pathao');

                $this->save();

                return $this;
            }

            Log::error('Pathao API Error - Request Pickup', [
                'shipment_id' => $this->id,
                'response' => $response->body()
            ]);

            throw new \Exception('Failed to request pickup from Pathao');

        } catch (\Exception $e) {
            Log::error('Pathao API Exception - Request Pickup', [
                'shipment_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function updatePathaoStatus()
    {
        if (!$this->pathao_consignment_id) {
            return $this;
        }

        $pathaoService = new PathaoService();

        if (!$pathaoService->isConfigured()) {
            return $this;
        }

        try {
            $result = $pathaoService->getOrder($this->pathao_consignment_id);

            if ($result['success']) {
                $oldStatus = $this->pathao_status;
                $newStatus = $result['data']['status'] ?? $this->pathao_status;

                if ($oldStatus !== $newStatus) {
                    $this->pathao_status = $newStatus;
                    $this->pathao_response = $result['response'];

                    // Update local status based on Pathao status
                    $this->updateLocalStatusFromPathao($newStatus);

                    $this->addStatusHistory($newStatus, "Status updated from Pathao: {$newStatus}");

                    $this->save();
                }

                return $this;
            }

        } catch (\Exception $e) {
            Log::error('Pathao API Exception - Update Status', [
                'shipment_id' => $this->id,
                'error' => $e->getMessage()
            ]);
        }

        return $this;
    }

    protected function updateLocalStatusFromPathao($pathaoStatus)
    {
        $statusMap = [
            'pending' => 'pending',
            'pickup_requested' => 'pickup_requested',
            'picked_up' => 'picked_up',
            'at_warehouse' => 'picked_up',
            'in_transit' => 'in_transit',
            'delivered' => 'delivered',
            'returned' => 'returned',
            'cancelled' => 'cancelled',
        ];

        $newStatus = $statusMap[$pathaoStatus] ?? $this->status;

        if ($newStatus !== $this->status) {
            $this->status = $newStatus;

            // Update timestamps based on status
            switch ($newStatus) {
                case 'picked_up':
                    $this->picked_up_at = $this->picked_up_at ?? now();
                    break;
                case 'in_transit':
                    $this->shipped_at = $this->shipped_at ?? now();
                    break;
                case 'delivered':
                    $this->delivered_at = $this->delivered_at ?? now();
                    $this->order->deliver(); // Update order status
                    break;
                case 'returned':
                    $this->returned_at = $this->returned_at ?? now();
                    break;
                case 'cancelled':
                    $this->cancelled_at = $this->cancelled_at ?? now();
                    break;
            }
        }
    }

    // Barcode Integration Methods
    public function addPackageBarcode($barcode)
    {
        $barcodes = $this->package_barcodes ?? [];
        if (!in_array($barcode, $barcodes)) {
            $barcodes[] = $barcode;
            $this->package_barcodes = $barcodes;
            $this->save();
        }
        return $this;
    }

    public function removePackageBarcode($barcode)
    {
        $barcodes = $this->package_barcodes ?? [];
        $barcodes = array_diff($barcodes, [$barcode]);
        $this->package_barcodes = array_values($barcodes);
        $this->save();
        return $this;
    }

    public function getPackageProducts()
    {
        if (!$this->package_barcodes) {
            return collect();
        }

        return ProductBarcode::whereIn('barcode', $this->package_barcodes)
                            ->with('product')
                            ->get()
                            ->pluck('product')
                            ->unique('id');
    }

    public function scanPackageBarcode($barcode)
    {
        $productBarcode = ProductBarcode::where('barcode', $barcode)->first();

        if (!$productBarcode) {
            return [
                'found' => false,
                'message' => 'Barcode not found in system'
            ];
        }

        // Check if barcode belongs to this shipment
        if (!in_array($barcode, $this->package_barcodes ?? [])) {
            return [
                'found' => true,
                'in_shipment' => false,
                'message' => 'Barcode found but not in this shipment',
                'product' => $productBarcode->product,
                'current_location' => $productBarcode->getCurrentLocation()
            ];
        }

        return [
            'found' => true,
            'in_shipment' => true,
            'product' => $productBarcode->product,
            'current_location' => $productBarcode->getCurrentLocation(),
            'shipment_status' => $this->status
        ];
    }

    // Workflow Methods
    public function markAsReadyForPickup()
    {
        if (!$this->isPending()) {
            throw new \Exception('Shipment must be pending to mark as ready for pickup');
        }

        $this->status = 'pickup_requested';
        $this->pickup_requested_at = now();
        $this->addStatusHistory('pickup_requested', 'Marked as ready for pickup');

        $this->save();

        return $this;
    }

    public function markAsPickedUp(Employee $employee = null)
    {
        if (!$this->isPickupRequested()) {
            throw new \Exception('Shipment must be pickup requested to mark as picked up');
        }

        $this->status = 'picked_up';
        $this->picked_up_at = now();
        $this->processed_by = $employee?->id;

        $this->addStatusHistory('picked_up', 'Package picked up' . ($employee ? " by {$employee->name}" : ''));

        $this->save();

        return $this;
    }

    public function markAsInTransit()
    {
        if (!$this->isPickedUp()) {
            throw new \Exception('Shipment must be picked up to mark as in transit');
        }

        $this->status = 'in_transit';
        $this->shipped_at = now();

        $this->addStatusHistory('in_transit', 'Package in transit');

        $this->save();

        return $this;
    }

    public function markAsDelivered(Employee $employee = null, $recipientSignature = null)
    {
        if (!$this->isInTransit()) {
            throw new \Exception('Shipment must be in transit to mark as delivered');
        }

        $this->status = 'delivered';
        $this->delivered_at = now();
        $this->delivered_by = $employee?->id;
        $this->recipient_signature = $recipientSignature;

        $this->addStatusHistory('delivered', 'Package delivered' . ($employee ? " by {$employee->name}" : ''));

        $this->save();

        // Update order status
        $this->order->deliver();

        return $this;
    }

    public function markAsReturned($reason = null)
    {
        $this->status = 'returned';
        $this->returned_at = now();

        $this->addStatusHistory('returned', 'Package returned' . ($reason ? ": {$reason}" : ''));

        $this->save();

        return $this;
    }

    public function cancel($reason = null)
    {
        if (in_array($this->status, ['delivered', 'cancelled'])) {
            throw new \Exception('Cannot cancel delivered or already cancelled shipment');
        }

        $this->status = 'cancelled';
        $this->cancelled_at = now();

        $this->addStatusHistory('cancelled', 'Shipment cancelled' . ($reason ? ": {$reason}" : ''));

        $this->save();

        return $this;
    }

    public function addStatusHistory($status, $note = null)
    {
        $history = $this->status_history ?? [];
        $history[] = [
            'status' => $status,
            'timestamp' => now()->toISOString(),
            'note' => $note
        ];
        $this->status_history = $history;
    }

    // Helper Methods
    public function getPickupAddressFormatted()
    {
        $address = $this->pickup_address;
        if (!$address) return '';

        return implode(', ', array_filter([
            $address['street'] ?? null,
            $address['area'] ?? null,
            $address['city'] ?? null,
            $address['postal_code'] ?? null,
        ]));
    }

    public function getDeliveryAddressFormatted()
    {
        $address = $this->delivery_address;
        if (!$address) return '';

        return implode(', ', array_filter([
            $address['street'] ?? null,
            $address['area'] ?? null,
            $address['city'] ?? null,
            $address['postal_code'] ?? null,
        ]));
    }

    public function getPackageDescription()
{
    $order = $this->order;
    if (!$order || $order->items->isEmpty()) {
        return 'Package containing ordered items';
    }

    $itemsDescription = $order->items->map(function ($item) {
        $name = $item->product->name ?? $item->name ?? 'Unknown Item';
        return "{$name} qty:{$item->quantity}";
    })->join(', ');

    if (mb_strlen($itemsDescription) > 250) {
        return mb_substr($itemsDescription, 0, 247) . '...';
    }

    return $itemsDescription;
}

    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'pending' => 'gray',
            'pickup_requested' => 'blue',
            'picked_up' => 'yellow',
            'in_transit' => 'orange',
            'delivered' => 'green',
            'returned' => 'red',
            'cancelled' => 'red',
            default => 'gray',
        };
    }

    public function getDeliveryTypeLabelAttribute()
    {
        return match($this->delivery_type) {
            'home_delivery' => 'Home Delivery',
            'store_pickup' => 'Store Pickup',
            'express' => 'Express Delivery',
            default => 'Unknown',
        };
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, ['pending']);
    }

    public function canBeCancelled(): bool
    {
        return !in_array($this->status, ['delivered', 'cancelled']);
    }

    // Static Methods
    public static function generateShipmentNumber(): string
    {
        do {
            $shipmentNumber = 'SHP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
        } while (static::where('shipment_number', $shipmentNumber)->exists());

        return $shipmentNumber;
    }

    public static function createFromOrder(Order $order, array $shipmentData = [])
    {
        $customer = $order->customer;
        $store = $order->store;

        // Prepare addresses
        $pickupAddress = [
            'name' => $store->name,
            'phone' => $store->phone ?? 'N/A',
            'street' => $store->address,
            'area' => $store->area,
            'city' => $store->city,
            'postal_code' => $store->postal_code,
            'country' => 'Bangladesh',
        ];

        $deliveryAddress = $order->shipping_address ?? [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'street' => $customer->address,
            'area' => $customer->city,
            'city' => $customer->city,
            'postal_code' => $customer->postal_code,
            'country' => $customer->country ?? 'Bangladesh',
        ];

        // Collect package barcodes from order items
        $packageBarcodes = [];
        foreach ($order->items as $item) {
            if ($item->batch && $item->batch->barcode) {
                $packageBarcodes[] = $item->batch->barcode->barcode;
            }
        }

        return static::create(array_merge([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'delivery_type' => $shipmentData['delivery_type'] ?? 'home_delivery',
            'cod_amount' => $order->outstanding_amount,
            'pickup_address' => $pickupAddress,
            'delivery_address' => $deliveryAddress,
            'package_barcodes' => $packageBarcodes,
            'recipient_name' => $customer->name,
            'recipient_phone' => $customer->phone,
            'created_by' => $shipmentData['created_by'] ?? auth()->id(),
        ], $shipmentData));
    }

    public static function getShipmentStats($storeId = null)
    {
        $query = static::query();

        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        return [
            'total_shipments' => $query->count(),
            'pending_shipments' => (clone $query)->pending()->count(),
            'in_transit_shipments' => (clone $query)->inTransit()->count(),
            'delivered_shipments' => (clone $query)->delivered()->count(),
            'returned_shipments' => (clone $query)->returned()->count(),
            'cancelled_shipments' => (clone $query)->cancelled()->count(),
            'total_delivery_fee' => $query->sum('delivery_fee'),
            'average_delivery_fee' => $query->avg('delivery_fee'),
        ];
    }
}
