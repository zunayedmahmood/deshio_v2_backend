<?php

namespace App\Models;

use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use App\Traits\AutoLogsActivity;

class PurchaseOrder extends Model
{
    use HasFactory, DatabaseAgnosticSearch, AutoLogsActivity;

    protected $fillable = [
        'po_number',
        'vendor_id',
        'store_id',
        'created_by',
        'approved_by',
        'received_by',
        'order_date',
        'expected_delivery_date',
        'actual_delivery_date',
        'approved_at',
        'sent_at',
        'received_at',
        'cancelled_at',
        'status',
        'payment_status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'shipping_cost',
        'other_charges',
        'total_amount',
        'paid_amount',
        'outstanding_amount',
        'payment_due_date',
        'reference_number',
        'notes',
        'terms_and_conditions',
        'cancellation_reason',
        'metadata',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
        'payment_due_date' => 'date',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'other_charges' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    /**
     * Relationships
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'received_by');
    }

    // Alias for backward compatibility
    public function employee(): BelongsTo
    {
        return $this->createdBy();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPaymentItem::class);
    }

    /**
     * Get all vendor payments through payment items
     */
    public function vendorPayments()
    {
        return $this->hasManyThrough(
            VendorPayment::class,
            VendorPaymentItem::class,
            'purchase_order_id',
            'id',
            'id',
            'vendor_payment_id'
        );
    }

    /**
     * Business Logic Methods
     */

    /**
     * Generate unique PO number: PO-YYYYMMDD-XXXXXX
     */
    public static function generatePONumber(): string
    {
        $date = now()->format('Ymd');
        $query = static::query();
        (new static)->whereLike($query, 'po_number', "PO-{$date}-", 'start');
        $lastPO = $query->orderBy('po_number', 'desc')
            ->first();

        if ($lastPO) {
            $lastNumber = (int) substr($lastPO->po_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return 'PO-' . $date . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate totals from items
     */
    public function calculateTotals(): void
    {
        $this->subtotal = $this->items->sum('total_cost');
        $this->total_amount = $this->subtotal + $this->tax_amount + $this->shipping_cost - $this->discount_amount;
        $this->outstanding_amount = $this->total_amount - $this->paid_amount;
        
        // Update payment status
        $this->updatePaymentStatus();
    }

    /**
     * Update payment status based on paid amount
     */
    public function updatePaymentStatus(): void
    {
        if ($this->paid_amount <= 0) {
            $this->payment_status = 'unpaid';
        } elseif ($this->paid_amount >= $this->total_amount) {
            $this->payment_status = 'paid';
        } else {
            $this->payment_status = 'partial';
        }
    }

    /**
     * Record a payment against this PO
     */
    public function recordPayment(float $amount, array $paymentData = []): VendorPaymentItem
    {
        DB::beginTransaction();
        try {
            // Create vendor payment if not provided
            if (!isset($paymentData['vendor_payment_id'])) {
                $vendorPayment = VendorPayment::create([
                    'payment_number' => VendorPayment::generatePaymentNumber(),
                    'vendor_id' => $this->vendor_id,
                    'payment_method_id' => $paymentData['payment_method_id'] ?? null,
                    'account_id' => $paymentData['account_id'] ?? null,
                    'employee_id' => $paymentData['employee_id'] ?? auth()->id(),
                    'amount' => $amount,
                    'allocated_amount' => $amount,
                    'payment_date' => $paymentData['payment_date'] ?? now(),
                    'payment_type' => 'purchase_order',
                    'status' => 'completed',
                    'notes' => $paymentData['notes'] ?? null,
                ]);
                $vendorPaymentId = $vendorPayment->id;
            } else {
                $vendorPaymentId = $paymentData['vendor_payment_id'];
            }

            // Determine allocation type
            $allocationType = 'partial';
            if ($amount >= $this->outstanding_amount) {
                $allocationType = 'full';
                $amount = $this->outstanding_amount; // Cap at outstanding
            }

            // Create payment item
            $paymentItem = VendorPaymentItem::create([
                'vendor_payment_id' => $vendorPaymentId,
                'purchase_order_id' => $this->id,
                'allocated_amount' => $amount,
                'po_total_at_payment' => $this->total_amount,
                'po_outstanding_before' => $this->outstanding_amount,
                'po_outstanding_after' => $this->outstanding_amount - $amount,
                'allocation_type' => $allocationType,
                'notes' => $paymentData['item_notes'] ?? null,
            ]);

            // Update PO payment amounts
            $this->paid_amount += $amount;
            $this->outstanding_amount -= $amount;
            $this->updatePaymentStatus();
            $this->save();

            DB::commit();
            return $paymentItem;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Mark PO as received and create product batches with barcodes
     */
    public function markAsReceived(array $receivedItems = []): void
    {
        DB::beginTransaction();
        try {
            foreach ($receivedItems as $itemData) {
                $item = $this->items()->find($itemData['item_id']);
                if (!$item) continue;

                $quantityReceived = $itemData['quantity_received'] ?? $item->quantity_ordered;
                
                // Create product batch
                $batch = ProductBatch::create([
                    'product_id' => $item->product_id,
                    'batch_number' => $itemData['batch_number'] ?? $this->po_number . '-' . $item->id,
                    'quantity' => $quantityReceived,
                    'cost_price' => $item->unit_cost,
                    'sell_price' => $item->unit_sell_price,
                    'store_id' => $this->store_id,
                    'manufactured_date' => $itemData['manufactured_date'] ?? null,
                    'expiry_date' => $itemData['expiry_date'] ?? null,
                ]);

                // Generate barcodes for each unit in the batch
                $store = $this->store;
                $initialStatus = $store && $store->is_warehouse ? 'in_warehouse' : 'in_shop';
                
                $generatedBarcodes = [];
                for ($i = 0; $i < $quantityReceived; $i++) {
                    $barcode = ProductBarcode::create([
                        'product_id' => $item->product_id,
                        'batch_id' => $batch->id,
                        'type' => 'CODE128',
                        'is_primary' => ($i === 0), // First barcode is primary
                        'is_active' => true,
                        'generated_at' => now(),
                        'current_store_id' => $this->store_id,
                        'current_status' => $initialStatus,
                        'location_updated_at' => now(),
                        'location_metadata' => [
                            'source' => 'purchase_order',
                            'po_number' => $this->po_number,
                            'received_date' => now()->format('Y-m-d H:i:s'),
                        ],
                    ]);
                    
                    $generatedBarcodes[] = $barcode;
                }
                
                // Set primary barcode for batch
                if (!empty($generatedBarcodes)) {
                    $batch->barcode_id = $generatedBarcodes[0]->id;
                    $batch->save();
                }

                // Update item
                $item->product_batch_id = $batch->id;
                $item->batch_number = $batch->batch_number;
                $item->quantity_received += $quantityReceived;
                $item->quantity_pending = $item->quantity_ordered - $item->quantity_received;
                $item->manufactured_date = $itemData['manufactured_date'] ?? null;
                $item->expiry_date = $itemData['expiry_date'] ?? null;
                
                // Update receive status
                if ($item->quantity_received >= $item->quantity_ordered) {
                    $item->receive_status = 'fully_received';
                } elseif ($item->quantity_received > 0) {
                    $item->receive_status = 'partially_received';
                }
                $item->save();
                
                // Log barcode generation
                \Log::info("Generated {$quantityReceived} barcodes for PO {$this->po_number}, Batch {$batch->batch_number}");
            }

            // Update PO status
            $totalOrdered = $this->items->sum('quantity_ordered');
            $totalReceived = $this->items->sum('quantity_received');
            
            if ($totalReceived >= $totalOrdered) {
                $this->status = 'received';
                $this->actual_delivery_date = now();
            } elseif ($totalReceived > 0) {
                $this->status = 'partially_received';
            }
            
            $this->save();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Failed to receive PO {$this->po_number}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cancel purchase order
     */
    public function cancel(string $reason = null): void
    {
        $this->status = 'cancelled';
        $this->notes = ($this->notes ? $this->notes . "\n\n" : '') . "Cancelled: " . ($reason ?? 'No reason provided');
        $this->save();

        // Cancel all pending items
        $this->items()->where('receive_status', 'pending')->update([
            'receive_status' => 'cancelled'
        ]);
    }

    /**
     * Check if PO is fully received
     */
    public function isFullyReceived(): bool
    {
        return $this->status === 'received';
    }

    /**
     * Check if PO is fully paid
     */
    public function isFullyPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    /**
     * Get payment history
     */
    public function getPaymentHistory()
    {
        return $this->payments()
            ->with('vendorPayment')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeReceived($query)
    {
        return $query->where('status', 'received');
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'unpaid');
    }

    public function scopePartiallyPaid($query)
    {
        return $query->where('payment_status', 'partial');
    }

    public function scopeOverdue($query)
    {
        return $query->where('expected_delivery_date', '<', now())
            ->whereNotIn('status', ['received', 'cancelled']);
    }
}
