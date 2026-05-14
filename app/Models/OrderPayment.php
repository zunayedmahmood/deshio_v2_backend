<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AutoLogsActivity;

class OrderPayment extends Model
{
    use HasFactory, SoftDeletes, AutoLogsActivity;

    protected $fillable = [
        'payment_number',
        'order_id',
        'payment_method_id', // Nullable for split payments (when payment uses multiple methods)
        'customer_id',
        'store_id',
        'processed_by',
        'amount',
        'fee_amount',
        'net_amount',
        'is_partial_payment',
        'installment_number',
        'payment_type',
        'payment_due_date',
        'payment_received_date',
        'order_balance_before',
        'order_balance_after',
        'expected_installment_amount',
        'installment_notes',
        'is_late_payment',
        'days_late',
        'status',
        'transaction_reference',
        'external_reference',
        'processed_at',
        'completed_at',
        'failed_at',
        'payment_data',
        'metadata',
        'notes',
        'failure_reason',
        'status_history',
        'refunded_amount',
        'refund_history',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'order_balance_before' => 'decimal:2',
        'order_balance_after' => 'decimal:2',
        'expected_installment_amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'payment_due_date' => 'date',
        'payment_received_date' => 'date',
        'payment_data' => 'array',
        'metadata' => 'array',
        'status_history' => 'array',
        'refunded_amount' => 'decimal:2',
        'refund_history' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            if (empty($payment->payment_number)) {
                $payment->payment_number = static::generatePaymentNumber();
            }

            // Ensure required foreign keys exist for legacy call-sites.
            // order_payments.customer_id is NOT nullable. Some older controllers
            // created OrderPayment without setting customer_id, so backfill it
            // from the related order when possible.
            if (empty($payment->customer_id) && !empty($payment->order_id)) {
                try {
                    $order = $payment->order()->first();
                    if ($order && !empty($order->customer_id)) {
                        $payment->customer_id = $order->customer_id;
                    }
                } catch (\Throwable $e) {
                    // Let DB constraints surface an error if we cannot resolve.
                }
            }

            // Calculate net amount if not provided
            // Skip calculation for split payments (payment_method_id is null)
            if (!isset($payment->net_amount) && isset($payment->amount)) {
                if ($payment->payment_method_id && $payment->paymentMethod) {
                    $fee = $payment->paymentMethod->calculateFee($payment->amount);
                    $payment->fee_amount = $fee;
                    $payment->net_amount = $payment->amount - $fee;
                } else {
                    // For split payments, fees will be calculated from splits
                    $payment->fee_amount = 0;
                    $payment->net_amount = $payment->amount;
                }
            }
        });
    }

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processed_by');
    }

    public function paymentSplits(): HasMany
    {
        return $this->hasMany(PaymentSplit::class);
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

    public function scopeRefunded($query)
    {
        return $query->whereIn('status', ['refunded', 'partially_refunded']);
    }

    public function scopeByOrder($query, $orderId)
    {
        return $query->where('order_id', $orderId);
    }

    public function scopeByMethod($query, $methodId)
    {
        return $query->where('payment_method_id', $methodId);
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    // Business logic methods
    public function process(Employee $processedBy = null): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update([
            'status' => 'processing',
            'processed_by' => $processedBy?->id,
            'processed_at' => now(),
            'status_history' => $this->addStatusToHistory('processing', $processedBy?->id),
        ]);

        return true;
    }

    public function complete(string $transactionReference = null, string $externalReference = null): bool
    {
        if ($this->status !== 'processing') {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'transaction_reference' => $transactionReference,
            'external_reference' => $externalReference,
            'completed_at' => now(),
            'status_history' => $this->addStatusToHistory('completed'),
        ]);

        // Update order payment status
        $this->order->updatePaymentStatus();

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

    public function refund(float $refundAmount, string $reason = null): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }

        $newRefundedAmount = $this->refunded_amount + $refundAmount;

        if ($newRefundedAmount > $this->amount) {
            return false; // Cannot refund more than paid
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
            'status_history' => $this->addStatusToHistory($status, auth()->id(), "Refund: {$refundAmount}"),
        ]);

        return true;
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

    public function isRefunded(): bool
    {
        return in_array($this->status, ['refunded', 'partially_refunded']);
    }

    public function getRefundableAmount(): float
    {
        return $this->amount - $this->refunded_amount;
    }

    public function requiresReference(): bool
    {
        return $this->paymentMethod && $this->paymentMethod->requires_reference;
    }

    // Payment split methods
    public function hasSplits(): bool
    {
        return $this->paymentSplits()->exists();
    }

    public function isSplitPayment(): bool
    {
        return $this->payment_method_id === null && $this->paymentSplits()->count() > 1;
    }

    public function isSimplePayment(): bool
    {
        return $this->payment_method_id !== null && !$this->hasSplits();
    }

    public function updateSplitStatus(): void
    {
        if (!$this->hasSplits()) {
            return;
        }

        $splits = $this->paymentSplits;
        $allCompleted = $splits->every(fn($split) => $split->status === 'completed');
        $anyFailed = $splits->contains(fn($split) => $split->status === 'failed');

        if ($allCompleted) {
            $this->complete();
        } elseif ($anyFailed) {
            $this->fail('One or more payment splits failed');
        }
    }

    public function createSplits(array $splitData): bool
    {
        if ($this->hasSplits()) {
            return false; // Already has splits
        }

        $totalSplitAmount = 0;
        $sequence = 1;

        foreach ($splitData as $split) {
            $method = PaymentMethod::find($split['payment_method_id']);
            
            PaymentSplit::createSplit(
                $this,
                $method,
                $split['amount'],
                $sequence++,
                $split['payment_data'] ?? []
            );

            $totalSplitAmount += $split['amount'];
        }

        // Validate total split amount matches payment amount
        if (abs($totalSplitAmount - $this->amount) > 0.01) {
            $this->paymentSplits()->delete();
            throw new \Exception("Total split amount ($totalSplitAmount) does not match payment amount ({$this->amount})");
        }

        return true;
    }

    public function getSplitSummary(): array
    {
        if (!$this->hasSplits()) {
            return [];
        }

        return $this->paymentSplits()
            ->with('paymentMethod')
            ->orderBy('split_sequence')
            ->get()
            ->map(function ($split) {
                return [
                    'sequence' => $split->split_sequence,
                    'method' => $split->paymentMethod->name,
                    'amount' => $split->amount,
                    'fee' => $split->fee_amount,
                    'net' => $split->net_amount,
                    'status' => $split->status,
                    'transaction_reference' => $split->transaction_reference,
                ];
            })
            ->toArray();
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

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'processing' => 'Processing',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            'refunded' => 'Fully Refunded',
            'partially_refunded' => 'Partially Refunded',
            default => 'Unknown',
        };
    }

    public function getPaymentMethodNameAttribute(): string
    {
        // For split payments, show "Split Payment" with count
        if ($this->payment_method_id === null && $this->hasSplits()) {
            $splitCount = $this->paymentSplits()->count();
            return "Split Payment ({$splitCount} methods)";
        }
        
        return $this->paymentMethod ? $this->paymentMethod->name : 'Unknown';
    }

    public function getProcessedByNameAttribute(): string
    {
        return $this->processedBy ? $this->processedBy->name : 'System';
    }

    // Static methods
    public static function generatePaymentNumber(): string
    {
        do {
            $paymentNumber = 'PAY-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
        } while (static::where('payment_number', $paymentNumber)->exists());

        return $paymentNumber;
    }

    public static function createPayment(Order $order, PaymentMethod $paymentMethod, float $amount, array $paymentData = [], Employee $processedBy = null): self
    {
        // Validate payment method is allowed for customer type
        if (!$paymentMethod->isAllowedForCustomerType($order->customer->customer_type)) {
            throw new \Exception("Payment method {$paymentMethod->name} is not allowed for {$order->customer->customer_type} customers");
        }

        // Validate amount limits
        if (!$paymentMethod->canProcessAmount($amount)) {
            throw new \Exception("Amount {$amount} is not within allowed limits for {$paymentMethod->name}");
        }

        $fee = $paymentMethod->calculateFee($amount);
        $netAmount = $amount - $fee;

        return static::create([
            'order_id' => $order->id,
            'payment_method_id' => $paymentMethod->id,
            'customer_id' => $order->customer_id,
            'store_id' => $order->store_id,
            'processed_by' => $processedBy?->id,
            'amount' => $amount,
            'fee_amount' => $fee,
            'net_amount' => $netAmount,
            'payment_data' => $paymentData,
            'status' => 'pending',
        ]);
    }

    public static function getTotalPaidForOrder(int $orderId): float
    {
        return static::byOrder($orderId)->completed()->sum('amount');
    }

    public static function getTotalRefundedForOrder(int $orderId): float
    {
        return static::byOrder($orderId)->refunded()->sum('refunded_amount');
    }
}