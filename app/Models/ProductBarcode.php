<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\AutoLogsActivity;

class ProductBarcode extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'product_id',
        'batch_id',
        'barcode',
        'type',
        'is_primary',
        'is_active',
        'generated_at',
        'is_defective',
        'current_store_id',       // NEW: Current physical location
        'current_status',          // NEW: Current state (in_warehouse, in_shop, etc.)
        'location_updated_at',     // NEW: When location/status last changed
        'location_metadata',       // NEW: Additional location details (shelf, bin, etc.)
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'is_defective' => 'boolean',
        'generated_at' => 'datetime',
        'location_updated_at' => 'datetime',   // NEW
        'location_metadata' => 'array',         // NEW
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($barcode) {
            if (empty($barcode->barcode)) {
                $barcode->barcode = static::generateUniqueBarcode();
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class, 'barcode_id');
    }

    public function defectiveRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DefectiveProduct::class, 'product_barcode_id');
    }

    /**
     * NEW: Current physical location relationship
     */
    public function currentStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'current_store_id');
    }

    /**
     * Alias for currentStore to prevent RelationNotFoundException if 'store' is used.
     */
    /**
     * Alias for currentStore to maintain backward compatibility with some modules
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'current_store_id');
    }

    // ============================================
    // LOCATION & STATUS TRACKING SCOPES
    // ============================================

    public function scopeAtStore($query, $storeId)
    {
        return $query->where('current_store_id', $storeId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('current_status', $status);
    }

    public function scopeInWarehouse($query)
    {
        return $query->where('current_status', 'in_warehouse');
    }

    public function scopeInShop($query)
    {
        return $query->where('current_status', 'in_shop');
    }

    public function scopeOnDisplay($query)
    {
        return $query->where('current_status', 'on_display');
    }

    public function scopeInTransit($query)
    {
        return $query->where('current_status', 'in_transit');
    }

    public function scopeInShipment($query)
    {
        return $query->where('current_status', 'in_shipment');
    }

    public function scopeWithCustomer($query)
    {
        return $query->where('current_status', 'with_customer');
    }

    public function scopeAvailableForSale($query)
    {
        return $query->where('is_active', true)
                    ->where('is_defective', false)
                    ->whereIn('current_status', ['in_shop', 'on_display', 'in_warehouse']);
    }

    // ============================================
    // LOCATION & STATUS TRACKING METHODS
    // ============================================

    /**
     * Update physical location and status of this barcode
     */
    public function updateLocation($storeId, $status, array $metadata = [], $createMovement = true)
    {
        $oldStore = $this->current_store_id;
        $oldStatus = $this->current_status;

        $this->update([
            'current_store_id' => $storeId,
            'current_status' => $status,
            'location_updated_at' => now(),
            'location_metadata' => array_merge($this->location_metadata ?? [], $metadata),
        ]);

        // Create movement record for audit trail
        if ($createMovement && ($oldStore != $storeId || $oldStatus != $status)) {
            ProductMovement::create([
                'product_batch_id' => $this->batch_id,
                'product_barcode_id' => $this->id,
                'from_store_id' => $oldStore,
                'to_store_id' => $storeId,
                'movement_type' => $this->determineMovementType($oldStatus, $status),
                'quantity' => 1,
                'status_before' => $oldStatus,
                'status_after' => $status,
                'notes' => "Location updated: {$oldStatus} → {$status}",
            ]);
        }

        return $this;
    }

    /**
     * Move barcode to warehouse
     */
    public function moveToWarehouse($storeId, array $metadata = [])
    {
        return $this->updateLocation($storeId, 'in_warehouse', $metadata);
    }

    /**
     * Move barcode to shop floor
     */
    public function moveToShop($storeId, array $metadata = [])
    {
        return $this->updateLocation($storeId, 'in_shop', $metadata);
    }

    /**
     * Place barcode on display
     */
    public function placeOnDisplay($storeId, array $metadata = [])
    {
        return $this->updateLocation($storeId, 'on_display', array_merge($metadata, [
            'display_started_at' => now()->toDateTimeString(),
        ]));
    }

    /**
     * Mark as in transit (during dispatch/transfer)
     */
    public function markInTransit($toStoreId, $dispatchId = null)
    {
        return $this->updateLocation($toStoreId, 'in_transit', [
            'transit_started_at' => now()->toDateTimeString(),
            'dispatch_id' => $dispatchId,
        ]);
    }

    /**
     * Mark as in shipment (for customer delivery)
     */
    public function markInShipment($shipmentId, $trackingNumber = null)
    {
        $metadata = [
            'shipment_id' => $shipmentId,
            'shipment_started_at' => now()->toDateTimeString(),
        ];
        
        if ($trackingNumber) {
            $metadata['tracking_number'] = $trackingNumber;
        }

        return $this->updateLocation($this->current_store_id, 'in_shipment', $metadata);
    }

    /**
     * Mark as sold and with customer
     */
    public function markSold($orderId, $customerId)
    {
        $this->update([
            'is_active' => false,  // Mark as sold
            'current_status' => 'with_customer',
            'location_updated_at' => now(),
            'location_metadata' => array_merge($this->location_metadata ?? [], [
                'sold_at' => now()->toDateTimeString(),
                'order_id' => $orderId,
                'customer_id' => $customerId,
            ]),
        ]);

        return $this;
    }

    /**
     * Mark as returned by customer
     */
    public function markReturned($returnId, $reason = null)
    {
        $this->update([
            'is_active' => true,  // Reactivate for resale
            'current_status' => 'in_warehouse',
            'location_updated_at' => now(),
            'location_metadata' => array_merge($this->location_metadata ?? [], [
                'returned_at' => now()->toDateTimeString(),
                'return_id' => $returnId,
                'return_reason' => $reason,
            ]),
        ]);

        return $this;
    }

    /**
     * Get complete location history with details
     */
    public function getDetailedLocationHistory()
    {
        return ProductMovement::where('product_barcode_id', $this->id)
            ->with(['fromStore', 'toStore', 'batch.product', 'performedBy', 'dispatch'])
            ->orderBy('movement_date', 'desc')
            ->get()
            ->map(function ($movement) {
                return [
                    'id' => $movement->id,
                    'date' => $movement->movement_date,
                    'from_store' => $movement->fromStore?->name,
                    'to_store' => $movement->toStore?->name,
                    'movement_type' => $movement->movement_type,
                    'status_before' => $movement->status_before,
                    'status_after' => $movement->status_after,
                    'reference_type' => $movement->reference_type,
                    'reference_id' => $movement->reference_id,
                    'performed_by' => $movement->performedBy?->name,
                    'notes' => $movement->notes,
                ];
            });
    }

    /**
     * Get current location details
     */
    public function getCurrentLocationDetails()
    {
        return [
            'barcode' => $this->barcode,
            'product' => [
                'id' => $this->product_id,
                'name' => $this->product->name ?? 'Unknown',
                'sku' => $this->product->sku ?? null,
            ],
            'current_store' => $this->currentStore ? [
                'id' => $this->currentStore->id,
                'name' => $this->currentStore->name,
                'type' => $this->currentStore->is_warehouse ? 'warehouse' : ($this->currentStore->is_online ? 'online' : 'retail'),
                'address' => $this->currentStore->address,
            ] : null,
            'current_status' => $this->current_status,
            'status_label' => $this->getStatusLabel(),
            'is_active' => $this->is_active,
            'is_defective' => $this->is_defective,
            'is_available_for_sale' => $this->isAvailableForSale(),
            'location_updated_at' => $this->location_updated_at,
            'location_metadata' => $this->location_metadata,
            'batch' => $this->batch ? [
                'id' => $this->batch->id,
                'batch_number' => $this->batch->batch_number,
                'quantity' => $this->batch->quantity,
            ] : null,
        ];
    }

    /**
     * Check if barcode is available for sale
     */
    public function isAvailableForSale(): bool
    {
        return $this->is_active 
            && !$this->is_defective 
            && in_array($this->current_status, ['in_shop', 'on_display', 'in_warehouse']);
    }

    /**
     * Check if barcode is currently in transit
     */
    public function isCurrentlyInTransit(): bool
    {
        return $this->current_status === 'in_transit';
    }

    /**
     * Check if barcode is with customer
     */
    public function isWithCustomer(): bool
    {
        return $this->current_status === 'with_customer';
    }

    /**
     * Get human-readable status label
     */
    public function getStatusLabel(): string
    {
        return match($this->current_status) {
            'in_warehouse' => 'In Warehouse',
            'in_shop' => 'In Shop Inventory',
            'on_display' => 'On Display Floor',
            'in_transit' => 'In Transit',
            'in_shipment' => 'In Customer Shipment',
            'with_customer' => 'Sold - With Customer',
            'in_return' => 'Customer Return Processing',
            'defective' => 'Marked as Defective',
            'repair' => 'Sent for Repair',
            'vendor_return' => 'Returned to Vendor',
            'disposed' => 'Disposed/Written Off',
            default => 'Unknown Status',
        };
    }

    /**
     * Determine movement type based on status change
     */
    protected function determineMovementType($oldStatus, $newStatus): string
    {
        if ($newStatus === 'with_customer') return 'sale';
        if ($newStatus === 'in_return') return 'return';
        if ($newStatus === 'in_transit') return 'dispatch';
        if ($newStatus === 'defective') return 'defective';
        if ($oldStatus === 'in_warehouse' && $newStatus === 'in_shop') return 'transfer';
        
        return 'adjustment';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeDefective($query)
    {
        return $query->where('is_defective', true);
    }

    public function scopeNonDefective($query)
    {
        return $query->where('is_defective', false);
    }

    public function makePrimary()
    {
        // Remove primary status from other barcodes of this product
        static::where('product_id', $this->product_id)
              ->where('id', '!=', $this->id)
              ->update(['is_primary' => false]);

        $this->update(['is_primary' => true]);

        return $this;
    }

    public static function generateUniqueBarcode($length = 12): string
    {
        do {
            $barcode = static::generateBarcode($length);
        } while (static::where('barcode', $barcode)->exists());

        return $barcode;
    }

    public static function generateBarcode($length = 12): string
    {
        // Generate a random numeric barcode
        $barcode = '';
        for ($i = 0; $i < $length; $i++) {
            $barcode .= mt_rand(0, 9);
        }

        return $barcode;
    }

    public static function generateEAN13(): string
    {
        // Generate a valid EAN-13 barcode
        $prefix = '123'; // Example prefix
        $random = str_pad(mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        $partial = $prefix . $random;

        // Calculate check digit
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += $partial[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        $checkDigit = (10 - ($sum % 10)) % 10;

        return $partial . $checkDigit;
    }

    public static function createForProduct(Product $product, $type = 'CODE128', $makePrimary = false)
    {
        $barcode = static::create([
            'product_id' => $product->id,
            'type' => $type,
            'is_primary' => $makePrimary,
        ]);

        if ($makePrimary) {
            $barcode->makePrimary();
        }

        return $barcode;
    }

    public static function getPrimaryBarcodeForProduct($productId)
    {
        return static::byProduct($productId)->primary()->active()->first();
    }

    public static function getBarcodesForProduct($productId, $onlyActive = true)
    {
        $query = static::byProduct($productId);

        if ($onlyActive) {
            $query->active();
        }

        return $query->get();
    }

    public function getFormattedBarcodeAttribute()
    {
        // Format barcode based on type
        switch ($this->type) {
            case 'EAN13':
                return substr($this->barcode, 0, 1) . '-' .
                       substr($this->barcode, 1, 6) . '-' .
                       substr($this->barcode, 7, 6);
            case 'CODE128':
            default:
                return $this->barcode;
        }
    }

    public function getCurrentLocation()
    {
        return ProductMovement::getCurrentLocation($this->id);
    }

    public function getLocationHistory()
    {
        return ProductMovement::getProductLocationHistory($this->id);
    }

    public function getCurrentStore()
    {
        $currentMovement = ProductMovement::byBarcode($this->id)
                                         ->orderBy('movement_date', 'desc')
                                         ->first();

        return $currentMovement ? $currentMovement->toStore : null;
    }

    public function getCurrentBatch(): ?ProductBatch
    {
        $currentMovement = ProductMovement::byBarcode($this->id)
                                         ->with('batch')
                                         ->orderBy('movement_date', 'desc')
                                         ->first();

        return $currentMovement ? $currentMovement->batch : null;
    }

    public function isCurrentlyAtStore($storeId)
    {
        $currentStore = $this->getCurrentStore();
        return $currentStore && $currentStore->id === $storeId;
    }

    public function getMovementCount()
    {
        return ProductMovement::byBarcode($this->id)->count();
    }

    public function getLastMovementDate()
    {
        $lastMovement = ProductMovement::byBarcode($this->id)
                                      ->orderBy('movement_date', 'desc')
                                      ->first();

        return $lastMovement ? $lastMovement->movement_date : null;
    }

    public static function scanBarcode($barcode)
    {
        $barcodeRecord = static::where('barcode', $barcode)
            ->with(['product.category', 'product.vendor', 'batch', 'currentStore'])
            ->first();

        if (!$barcodeRecord) {
            return [
                'found' => false,
                'message' => 'Barcode not found in system',
            ];
        }

        // Use direct relationships instead of searching through movements
        $currentLocation = $barcodeRecord->currentStore;
        $currentBatch = $barcodeRecord->batch;
        
        // Get last movement for history tracking
        $lastMovement = ProductMovement::byBarcode($barcodeRecord->id)
                                      ->orderBy('movement_date', 'desc')
                                      ->first();

        return [
            'found' => true,
            'barcode' => $barcodeRecord,
            'product' => $barcodeRecord->product,
            'current_location' => $currentLocation,
            'current_batch' => $currentBatch,
            'last_movement' => $lastMovement,
            'location_history' => $barcodeRecord->getLocationHistory(),
            'is_available' => $barcodeRecord->isAvailableForSale(),
            'quantity_available' => $currentBatch ? $currentBatch->quantity : 0,
        ];
    }

    public function getCurrentShipment()
    {
        // Check if this barcode is currently in a shipment
        $currentShipment = Shipment::whereJsonContains('package_barcodes', $this->barcode)
                                  ->whereNotIn('status', ['delivered', 'cancelled', 'returned'])
                                  ->first();

        return $currentShipment;
    }

    public function getShipmentHistory()
    {
        return Shipment::whereJsonContains('package_barcodes', $this->barcode)
                      ->with(['order', 'customer'])
                      ->orderBy('created_at', 'desc')
                      ->get();
    }

    public function isInShipment(): bool
    {
        return $this->getCurrentShipment() !== null;
    }

    public function getShipmentStatus()
    {
        $shipment = $this->getCurrentShipment();
        return $shipment ? $shipment->status : null;
    }

    public function getShipmentTrackingNumber()
    {
        $shipment = $this->getCurrentShipment();
        return $shipment ? ($shipment->pathao_tracking_number ?? $shipment->shipment_number) : null;
    }

    // Defective product methods
    public function markAsDefective(array $defectData): DefectiveProduct
    {
        // Mark barcode as defective
        $this->update(['is_defective' => true, 'is_active' => false]);

        // Create defective product record
        $defectiveProduct = DefectiveProduct::create([
            'product_id' => $this->product_id,
            'product_barcode_id' => $this->id,
            'product_batch_id' => $defectData['product_batch_id'] ?? null,
            'store_id' => $defectData['store_id'],
            'defect_type' => $defectData['defect_type'],
            'defect_description' => $defectData['defect_description'],
            'defect_images' => $defectData['defect_images'] ?? null,
            'severity' => $defectData['severity'] ?? 'moderate',
            'original_price' => $defectData['original_price'],
            'identified_by' => $defectData['identified_by'] ?? null,
            'internal_notes' => $defectData['internal_notes'] ?? null,
            'source_return_id' => $defectData['source_return_id'] ?? null,
        ]);

        // Remove from regular inventory if batch is provided
        if (isset($defectData['product_batch_id'])) {
            $batch = ProductBatch::find($defectData['product_batch_id']);
            if ($batch && $batch->quantity > 0) {
                $batch->decrement('quantity', 1);

                // Log the removal
                ProductMovement::create([
                    'product_id' => $this->product_id,
                    'product_batch_id' => $batch->id,
                    'product_barcode_id' => $this->id,
                    'to_store_id' => $defectData['store_id'],
                    'movement_type' => 'defective',
                    'quantity' => -1,
                    'unit_cost' => $defectData['original_price'],
                    'total_cost' => $defectData['original_price'],
                    'movement_date' => now(),
                    'reference_type' => 'defective_product',
                    'reference_id' => $defectiveProduct->id,
                    'notes' => "Marked as defective: {$defectData['defect_type']}",
                    'performed_by' => $defectData['identified_by'] ?? null,
                ]);
            }
        }

        return $defectiveProduct;
    }

    public function isDefective(): bool
    {
        return $this->is_defective ?? false;
    }

    public function getDefectiveRecord(): ?DefectiveProduct
    {
        return $this->defectiveRecord;
    }

    public function canBeMarkedAsDefective(): bool
    {
        return !$this->is_defective && $this->is_active;
    }
}
