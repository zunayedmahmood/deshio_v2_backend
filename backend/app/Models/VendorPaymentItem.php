<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\AutoLogsActivity;

class VendorPaymentItem extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'vendor_payment_id',
        'purchase_order_id',
        'allocated_amount',
        'po_total_at_payment',
        'po_outstanding_before',
        'po_outstanding_after',
        'allocation_type',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'po_total_at_payment' => 'decimal:2',
        'po_outstanding_before' => 'decimal:2',
        'po_outstanding_after' => 'decimal:2',
        'metadata' => 'array',
    ];

    /**
     * Relationships
     */
    public function vendorPayment(): BelongsTo
    {
        return $this->belongsTo(VendorPayment::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Check if this was a full payment
     */
    public function isFullPayment(): bool
    {
        return $this->allocation_type === 'full';
    }

    /**
     * Check if this was a partial payment
     */
    public function isPartialPayment(): bool
    {
        return $this->allocation_type === 'partial';
    }

    /**
     * Get payment percentage
     */
    public function getPaymentPercentage(): float
    {
        if ($this->po_outstanding_before <= 0) {
            return 0;
        }

        return ($this->allocated_amount / $this->po_outstanding_before) * 100;
    }

    /**
     * Get formatted payment info
     */
    public function getPaymentInfo(): array
    {
        return [
            'payment_number' => $this->vendorPayment->payment_number,
            'payment_date' => $this->vendorPayment->payment_date,
            'allocated_amount' => $this->allocated_amount,
            'payment_percentage' => $this->getPaymentPercentage(),
            'allocation_type' => $this->allocation_type,
            'outstanding_before' => $this->po_outstanding_before,
            'outstanding_after' => $this->po_outstanding_after,
        ];
    }
}
