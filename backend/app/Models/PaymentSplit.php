<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_payment_id',
        'payment_method_id',
        'store_id',
        'amount',
        'fee_amount',
        'net_amount',
        'split_sequence',
        'transaction_reference',
        'external_reference',
        'payment_data',
        'status',
        'processed_at',
        'completed_at',
        'failed_at',
        'refunded_amount',
        'refund_history',
        'notes',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'payment_data' => 'array',
        'refund_history' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($split) {
            // Calculate net amount if not provided
            if (!isset($split->net_amount) && isset($split->amount)) {
                $fee = $split->paymentMethod ? $split->paymentMethod->calculateFee($split->amount) : 0;
                $split->fee_amount = $fee;
                $split->net_amount = $split->amount - $fee;
            }
        });
    }

    // Relationships
    public function orderPayment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashDenominations(): HasMany
    {
        return $this->hasMany(CashDenomination::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeByPayment($query, $paymentId)
    {
        return $query->where('order_payment_id', $paymentId);
    }

    public function scopeByMethod($query, $methodId)
    {
        return $query->where('payment_method_id', $methodId);
    }

    public function scopeCash($query)
    {
        return $query->whereHas('paymentMethod', function ($q) {
            $q->where('type', 'cash');
        });
    }

    // Business logic
    public function complete(string $transactionReference = null, string $externalReference = null): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'transaction_reference' => $transactionReference,
            'external_reference' => $externalReference,
            'completed_at' => now(),
        ]);

        // Check if all splits are completed
        $this->orderPayment->updateSplitStatus();

        return true;
    }

    public function fail(string $reason): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);

        return true;
    }

    public function refund(float $refundAmount, string $reason = null): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }

        $newRefundedAmount = $this->refunded_amount + $refundAmount;

        if ($newRefundedAmount > $this->amount) {
            return false;
        }

        $status = $newRefundedAmount >= $this->amount ? 'refunded' : 'partially_refunded';

        $refundHistory = $this->refund_history ?? [];
        $refundHistory[] = [
            'amount' => $refundAmount,
            'reason' => $reason,
            'refunded_at' => now()->toISOString(),
            'refunded_by' => auth()->id(),
        ];

        $this->update([
            'status' => $status,
            'refunded_amount' => $newRefundedAmount,
            'refund_history' => $refundHistory,
        ]);

        return true;
    }

    public function getRefundableAmount(): float
    {
        return $this->amount - $this->refunded_amount;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCash(): bool
    {
        return $this->paymentMethod && $this->paymentMethod->type === 'cash';
    }

    public function hasCashDenominations(): bool
    {
        return $this->cashDenominations()->exists();
    }

    // Accessors
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'warning',
            'processing' => 'info',
            'completed' => 'success',
            'failed' => 'danger',
            'cancelled' => 'secondary',
            'refunded' => 'primary',
            'partially_refunded' => 'info',
            default => 'secondary',
        };
    }

    // Static methods
    public static function createSplit(
        OrderPayment $payment,
        PaymentMethod $method,
        float $amount,
        int $sequence,
        array $paymentData = []
    ): self {
        $fee = $method->calculateFee($amount);
        $netAmount = $amount - $fee;

        return static::create([
            'order_payment_id' => $payment->id,
            'payment_method_id' => $method->id,
            'store_id' => $payment->store_id,
            'amount' => $amount,
            'fee_amount' => $fee,
            'net_amount' => $netAmount,
            'split_sequence' => $sequence,
            'payment_data' => $paymentData,
            'status' => 'pending',
        ]);
    }
}
