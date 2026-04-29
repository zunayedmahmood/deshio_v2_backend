<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AutoLogsActivity;

class Refund extends Model
{
    use HasFactory, SoftDeletes, AutoLogsActivity;

    protected $fillable = [
        'refund_number',
        'return_id',
        'order_id',
        'customer_id',
        'refund_type',
        'refund_percentage',
        'original_amount',
        'refund_amount',
        'processing_fee',
        'refund_method',
        'payment_reference',
        'refund_method_details',
        'status',
        'processed_at',
        'completed_at',
        'failed_at',
        'processed_by',
        'approved_by',
        'transaction_reference',
        'bank_reference',
        'gateway_reference',
        'customer_notes',
        'internal_notes',
        'failure_reason',
        'store_credit_expires_at',
        'store_credit_code',
        'status_history',
    ];

    protected $casts = [
        'refund_percentage' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'processing_fee' => 'decimal:2',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'store_credit_expires_at' => 'datetime',
        'refund_method_details' => 'json',
        'status_history' => 'json',
    ];

    // Relationships
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ProductReturn::class, 'return_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByMethod($query, string $method)
    {
        return $query->where('refund_method', $method);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('refund_type', $type);
    }

    // Business logic methods
    public function process(Employee $processedBy): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update([
            'status' => 'processing',
            'processed_by' => $processedBy->id,
            'processed_at' => now(),
            'status_history' => $this->addStatusToHistory('processing', $processedBy->id),
        ]);

        return true;
    }

    public function complete(string $transactionReference = null): bool
    {
        if ($this->status !== 'processing') {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'transaction_reference' => $transactionReference,
            'status_history' => $this->addStatusToHistory('completed'),
        ]);

        return true;
    }

    public function fail(string $reason): bool
    {
        if (!in_array($this->status, ['pending', 'processing'])) {
            return false;
        }

        $this->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $reason,
            'status_history' => $this->addStatusToHistory('failed', null, $reason),
        ]);

        return true;
    }

    public function cancel(string $reason = null): bool
    {
        if (in_array($this->status, ['completed', 'failed'])) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
            'failure_reason' => $reason,
            'status_history' => $this->addStatusToHistory('cancelled', null, $reason),
        ]);

        return true;
    }

    public function calculateRefundAmount(): float
    {
        return match ($this->refund_type) {
            'full' => $this->original_amount - $this->processing_fee,
            'percentage' => ($this->original_amount * $this->refund_percentage / 100) - $this->processing_fee,
            'partial_amount' => $this->refund_amount,
            default => 0,
        };
    }

    public function canBeProcessed(): bool
    {
        return $this->status === 'pending';
    }

    public function canBeCompleted(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isStoreCredit(): bool
    {
        return $this->refund_method === 'store_credit';
    }

    public function isExpiredStoreCredit(): bool
    {
        return $this->isStoreCredit() &&
               $this->store_credit_expires_at &&
               $this->store_credit_expires_at->isPast();
    }

    public function generateStoreCreditCode(): string
    {
        return 'SC-' . strtoupper(substr(md5($this->id . $this->customer_id . time()), 0, 8));
    }

    private function addStatusToHistory(string $status, ?int $userId = null, ?string $notes = null): array
    {
        $history = $this->status_history ?? [];
        $history[] = [
            'status' => $status,
            'changed_at' => now()->toISOString(),
            'changed_by' => $userId,
            'notes' => $notes,
        ];
        return $history;
    }

    // Accessors
    public function getNetRefundAmountAttribute(): float
    {
        return $this->refund_amount - $this->processing_fee;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'warning',
            'processing' => 'info',
            'completed' => 'success',
            'failed' => 'danger',
            'cancelled' => 'secondary',
            default => 'secondary',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            default => 'Unknown',
        };
    }

    public function getRefundMethodLabelAttribute(): string
    {
        return match ($this->refund_method) {
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'card_refund' => 'Card Refund',
            'store_credit' => 'Store Credit',
            'gift_card' => 'Gift Card',
            'digital_wallet' => 'Digital Wallet',
            'check' => 'Check',
            'other' => 'Other',
            default => 'Unknown',
        };
    }

    public function getRefundTypeLabelAttribute(): string
    {
        return match ($this->refund_type) {
            'full' => 'Full Refund',
            'percentage' => 'Percentage Refund',
            'partial_amount' => 'Partial Amount',
            default => 'Unknown',
        };
    }
}