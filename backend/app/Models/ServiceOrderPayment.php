<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AutoLogsActivity;

class ServiceOrderPayment extends Model
{
    use HasFactory, SoftDeletes, AutoLogsActivity;

    protected $fillable = [
        'payment_number',
        'service_order_id',
        'payment_method_id',
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
        'amount' => 'float',
        'fee_amount' => 'float',
        'net_amount' => 'float',
        'order_balance_before' => 'float',
        'order_balance_after' => 'float',
        'expected_installment_amount' => 'float',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'payment_due_date' => 'date',
        'payment_received_date' => 'date',
        'payment_data' => 'array',
        'metadata' => 'array',
        'status_history' => 'array',
        'refunded_amount' => 'float',
        'refund_history' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            if (empty($payment->payment_number)) {
                $payment->payment_number = static::generatePaymentNumber();
            }

            // Calculate net amount if not provided
            if (!isset($payment->net_amount) && isset($payment->amount)) {
                $fee = $payment->paymentMethod ? $payment->paymentMethod->calculateFee($payment->amount) : 0;
                $payment->fee_amount = $fee;
                $payment->net_amount = $payment->amount - $fee;
            }
        });
    }

    // Relationships
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
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

    public function scopeByServiceOrder($query, $serviceOrderId)
    {
        return $query->where('service_order_id', $serviceOrderId);
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

        // Update service order payment status
        $this->serviceOrder->updatePaymentStatus();

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
            $paymentNumber = 'SPAY-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
        } while (static::where('payment_number', $paymentNumber)->exists());

        return $paymentNumber;
    }

    public static function createPayment(ServiceOrder $serviceOrder, PaymentMethod $paymentMethod, float $amount, array $paymentData = [], Employee $processedBy = null): self
    {
        // Validate payment method is allowed for customer type
        if (!$paymentMethod->isAllowedForCustomerType($serviceOrder->customer->customer_type)) {
            throw new \Exception("Payment method {$paymentMethod->name} is not allowed for {$serviceOrder->customer->customer_type} customers");
        }

        // Validate amount limits
        if (!$paymentMethod->canProcessAmount($amount)) {
            throw new \Exception("Amount {$amount} is not within allowed limits for {$paymentMethod->name}");
        }

        $fee = $paymentMethod->calculateFee($amount);
        $netAmount = $amount - $fee;

        return static::create([
            'service_order_id' => $serviceOrder->id,
            'payment_method_id' => $paymentMethod->id,
            'customer_id' => $serviceOrder->customer_id,
            'store_id' => $serviceOrder->store_id,
            'processed_by' => $processedBy?->id,
            'amount' => $amount,
            'fee_amount' => $fee,
            'net_amount' => $netAmount,
            'payment_data' => $paymentData,
            'status' => 'pending',
        ]);
    }

    public static function getTotalPaidForServiceOrder(int $serviceOrderId): float
    {
        return static::byServiceOrder($serviceOrderId)->completed()->sum('amount');
    }

    public static function getTotalRefundedForServiceOrder(int $serviceOrderId): float
    {
        return static::byServiceOrder($serviceOrderId)->refunded()->sum('refunded_amount');
    }
}
