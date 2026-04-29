<?php

namespace App\Models;

use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use App\Traits\AutoLogsActivity;

class VendorPayment extends Model
{
    use HasFactory, SoftDeletes, DatabaseAgnosticSearch, AutoLogsActivity;

    protected $fillable = [
        'payment_number',
        'reference_number',
        'vendor_id',
        'payment_method_id',
        'account_id',
        'employee_id',
        'amount',
        'allocated_amount',
        'unallocated_amount',
        'status',
        'payment_type',
        'transaction_id',
        'cheque_number',
        'cheque_date',
        'bank_name',
        'payment_date',
        'processed_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'unallocated_amount' => 'decimal:2',
        'payment_date' => 'date',
        'cheque_date' => 'date',
        'processed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Relationships
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function paymentItems(): HasMany
    {
        return $this->hasMany(VendorPaymentItem::class);
    }

    /**
     * Get purchase orders through payment items
     */
    public function purchaseOrders()
    {
        return $this->hasManyThrough(
            PurchaseOrder::class,
            VendorPaymentItem::class,
            'vendor_payment_id',
            'id',
            'id',
            'purchase_order_id'
        );
    }

    /**
     * Business Logic Methods
     */

    /**
     * Generate unique payment number: VP-YYYYMMDD-XXXXXX
     */
    public static function generatePaymentNumber(): string
    {
        $date = now()->format('Ymd');
        $query = static::query();
        (new static)->whereLike($query, 'payment_number', "VP-{$date}-", 'start');
        $lastPayment = $query->orderBy('payment_number', 'desc')
            ->first();

        if ($lastPayment) {
            $lastNumber = (int) substr($lastPayment->payment_number, -6);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return 'VP-' . $date . '-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Allocate payment to purchase orders
     * Example: $10,000 can be split as $7,000 to PO1 and $3,000 to PO2
     */
    public function allocateToPurchaseOrders(array $allocations): void
    {
        DB::beginTransaction();
        try {
            $totalAllocated = 0;

            foreach ($allocations as $allocation) {
                $purchaseOrder = PurchaseOrder::findOrFail($allocation['purchase_order_id']);
                $allocatedAmount = min($allocation['amount'], $purchaseOrder->outstanding_amount);

                // Determine allocation type
                $allocationType = 'partial';
                if ($allocatedAmount >= $purchaseOrder->outstanding_amount) {
                    $allocationType = 'full';
                } elseif ($allocatedAmount > $purchaseOrder->outstanding_amount) {
                    $allocationType = 'over';
                }

                // Create payment item
                VendorPaymentItem::create([
                    'vendor_payment_id' => $this->id,
                    'purchase_order_id' => $purchaseOrder->id,
                    'allocated_amount' => $allocatedAmount,
                    'po_total_at_payment' => $purchaseOrder->total_amount,
                    'po_outstanding_before' => $purchaseOrder->outstanding_amount,
                    'po_outstanding_after' => $purchaseOrder->outstanding_amount - $allocatedAmount,
                    'allocation_type' => $allocationType,
                    'notes' => $allocation['notes'] ?? null,
                ]);

                // Update purchase order
                $purchaseOrder->paid_amount += $allocatedAmount;
                $purchaseOrder->outstanding_amount -= $allocatedAmount;
                $purchaseOrder->updatePaymentStatus();
                $purchaseOrder->save();

                $totalAllocated += $allocatedAmount;
            }

            // Update payment allocation amounts
            $this->allocated_amount = $totalAllocated;
            $this->unallocated_amount = $this->amount - $totalAllocated;
            $this->save();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process the payment
     */
    public function process(): void
    {
        $this->status = 'processing';
        $this->save();
    }

    /**
     * Complete the payment
     */
    public function complete(): void
    {
        $this->status = 'completed';
        $this->processed_at = now();
        $this->save();
    }

    /**
     * Fail the payment
     */
    public function fail(): void
    {
        $this->status = 'failed';
        $this->save();
    }

    /**
     * Cancel the payment and reverse allocations
     */
    public function cancel(): void
    {
        DB::beginTransaction();
        try {
            // Reverse all allocations
            foreach ($this->paymentItems as $item) {
                $purchaseOrder = $item->purchaseOrder;
                $purchaseOrder->paid_amount -= $item->allocated_amount;
                $purchaseOrder->outstanding_amount += $item->allocated_amount;
                $purchaseOrder->updatePaymentStatus();
                $purchaseOrder->save();
            }

            $this->status = 'cancelled';
            $this->save();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Refund the payment
     */
    public function refund(): void
    {
        DB::beginTransaction();
        try {
            // Create a refund payment entry
            $refundPayment = static::create([
                'payment_number' => static::generatePaymentNumber(),
                'reference_number' => $this->payment_number,
                'vendor_id' => $this->vendor_id,
                'payment_method_id' => $this->payment_method_id,
                'account_id' => $this->account_id,
                'employee_id' => auth()->id(),
                'amount' => -$this->amount,
                'allocated_amount' => -$this->allocated_amount,
                'unallocated_amount' => -$this->unallocated_amount,
                'status' => 'completed',
                'payment_type' => 'refund',
                'payment_date' => now(),
                'processed_at' => now(),
                'notes' => "Refund for payment {$this->payment_number}",
            ]);

            // Reverse allocations
            foreach ($this->paymentItems as $item) {
                $purchaseOrder = $item->purchaseOrder;
                $purchaseOrder->paid_amount -= $item->allocated_amount;
                $purchaseOrder->outstanding_amount += $item->allocated_amount;
                $purchaseOrder->updatePaymentStatus();
                $purchaseOrder->save();

                // Create refund item
                VendorPaymentItem::create([
                    'vendor_payment_id' => $refundPayment->id,
                    'purchase_order_id' => $purchaseOrder->id,
                    'allocated_amount' => -$item->allocated_amount,
                    'po_total_at_payment' => $purchaseOrder->total_amount,
                    'po_outstanding_before' => $item->po_outstanding_after,
                    'po_outstanding_after' => $purchaseOrder->outstanding_amount,
                    'allocation_type' => 'partial',
                    'notes' => "Refund for {$this->payment_number}",
                ]);
            }

            $this->status = 'refunded';
            $this->save();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Check if payment is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if payment has unallocated amount (advance payment)
     */
    public function hasUnallocatedAmount(): bool
    {
        return $this->unallocated_amount > 0;
    }

    /**
     * Scopes
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('payment_date', [$startDate, $endDate]);
    }

    public function scopeAdvancePayments($query)
    {
        return $query->where('payment_type', 'advance')
            ->where('unallocated_amount', '>', 0);
    }
}
