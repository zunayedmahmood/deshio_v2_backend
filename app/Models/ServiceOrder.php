<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Traits\AutoLogsActivity;

class ServiceOrder extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'service_order_number',
        'customer_id',
        'store_id',
        'created_by',
        'assigned_to',
        'status',
        'payment_status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'paid_amount',
        'outstanding_amount',
        'refunded_amount',
        'is_installment_payment',
        'total_installments',
        'paid_installments',
        'installment_amount',
        'next_payment_due',
        'allow_partial_payments',
        'minimum_payment_amount',
        'scheduled_date',
        'scheduled_time',
        'estimated_completion',
        'actual_completion',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_address',
        'special_instructions',
        'notes',
        'metadata',
        'payment_schedule',
        'payment_history',
        'confirmed_at',
        'started_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'tax_amount' => 'float',
        'discount_amount' => 'float',
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'outstanding_amount' => 'float',
        'refunded_amount' => 'float',
        'installment_amount' => 'float',
        'minimum_payment_amount' => 'float',
        'scheduled_date' => 'datetime',
        'scheduled_time' => 'datetime',
        'estimated_completion' => 'datetime',
        'actual_completion' => 'datetime',
        'next_payment_due' => 'date',
        'confirmed_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
        'payment_schedule' => 'array',
        'payment_history' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->service_order_number)) {
                $order->service_order_number = static::generateServiceOrderNumber();
            }

            // Set customer details if not provided
            if ($order->customer && empty($order->customer_name)) {
                $order->customer_name = $order->customer->name;
                $order->customer_phone = $order->customer->phone;
                $order->customer_email = $order->customer->email;
                $order->customer_address = $order->customer->address;
            }
        });
    }

    // Relationships
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ServiceOrderPayment::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeByAssignedTo($query, $employeeId)
    {
        return $query->where('assigned_to', $employeeId);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'unpaid');
    }

    public function scopePartiallyPaid($query)
    {
        return $query->where('payment_status', 'partially_paid');
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopeScheduledToday($query)
    {
        return $query->whereDate('scheduled_date', today());
    }

    public function scopeScheduledForDate($query, $date)
    {
        return $query->whereDate('scheduled_date', $date);
    }

    // Business logic methods
    public function confirm(Employee $confirmedBy = null): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'assigned_to' => $confirmedBy?->id ?? $this->assigned_to,
        ]);

        return true;
    }

    public function start(Employee $startedBy = null): bool
    {
        if ($this->status !== 'confirmed') {
            return false;
        }

        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'assigned_to' => $startedBy?->id ?? $this->assigned_to,
        ]);

        return true;
    }

    public function complete(): bool
    {
        if ($this->status !== 'in_progress') {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'actual_completion' => now(),
        ]);

        return true;
    }

    public function cancel(string $reason = null): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'notes' => $this->notes ? $this->notes . "\n\nCancellation reason: " . $reason : "Cancellation reason: " . $reason,
        ]);

        return true;
    }

    public function updatePaymentStatus(): void
    {
        $totalPaid = $this->payments()->completed()->sum('amount');
        $totalRefunded = $this->payments()->refunded()->sum('refunded_amount');

        $this->paid_amount = $totalPaid;
        $this->refunded_amount = $totalRefunded;
        $this->outstanding_amount = max(0, $this->total_amount - $totalPaid + $totalRefunded);

        // Check for overdue payments
        if ($this->next_payment_due && now()->gt(\Carbon\Carbon::parse($this->next_payment_due)) && $this->outstanding_amount > 0) {
            $this->payment_status = 'overdue';
        } elseif ($totalRefunded > 0) {
            if ($totalRefunded >= $totalPaid) {
                $this->payment_status = 'refunded';
            } else {
                $this->payment_status = 'partially_refunded';
            }
        } elseif ($totalPaid >= $this->total_amount) {
            $this->payment_status = 'paid';
        } elseif ($totalPaid > 0) {
            $this->payment_status = 'partially_paid';
        } else {
            $this->payment_status = 'unpaid';
        }

        // Update installment tracking if applicable
        if ($this->is_installment_payment) {
            $this->updateInstallmentProgress();
        }

        $this->save();
    }

    // Fragmented payment methods
    public function updateInstallmentProgress(): void
    {
        if (!$this->is_installment_payment) {
            return;
        }

        $completedInstallments = $this->payments()
            ->where('is_partial_payment', true)
            ->where('payment_type', 'installment')
            ->count();

        $this->paid_installments = $completedInstallments;

        // Calculate next payment due date if installment amount is set
        if ($this->installment_amount && $this->paid_installments < $this->total_installments) {
            $nextInstallmentNumber = $this->paid_installments + 1;
            // This would need to be calculated based on payment schedule
            // For now, we'll assume monthly installments
            $this->update(['next_payment_due' => now()->addMonths($nextInstallmentNumber - 1)->format('Y-m-d')]);
        }

        $this->save();
    }

    public function canAcceptPartialPayment(): bool
    {
        return $this->allow_partial_payments && $this->outstanding_amount > 0 && !$this->isCancelled();
    }

    public function canAcceptInstallmentPayment(): bool
    {
        return $this->is_installment_payment &&
               $this->paid_installments < $this->total_installments &&
               $this->outstanding_amount > 0 &&
               !$this->isCancelled();
    }

    public function isPaymentOverdue(): bool
    {
        return $this->payment_status === 'overdue' ||
               ($this->next_payment_due && now()->gt(\Carbon\Carbon::parse($this->next_payment_due)) && $this->outstanding_amount > 0);
    }

    public function getDaysOverdue(): int
    {
        if (!$this->next_payment_due || !$this->isPaymentOverdue()) {
            return 0;
        }

        return now()->diffInDays(\Carbon\Carbon::parse($this->next_payment_due));
    }

    public function setupInstallmentPlan(int $totalInstallments, float $installmentAmount, ?string $startDate = null): bool
    {
        if ($this->is_installment_payment) {
            return false; // Already set up
        }

        $startDate = $startDate ? \Carbon\Carbon::parse($startDate) : now();

        $this->update([
            'is_installment_payment' => true,
            'total_installments' => $totalInstallments,
            'installment_amount' => $installmentAmount,
            'next_payment_due' => $startDate->format('Y-m-d'),
            'allow_partial_payments' => true,
            'minimum_payment_amount' => $installmentAmount,
        ]);

        // Create payment schedule
        $this->createPaymentSchedule($startDate);

        return true;
    }

    public function createPaymentSchedule(\Carbon\Carbon $startDate): void
    {
        $schedule = [];
        $currentDate = $startDate->copy();

        for ($i = 1; $i <= $this->total_installments; $i++) {
            $schedule[] = [
                'installment_number' => $i,
                'amount' => $this->installment_amount,
                'due_date' => $currentDate->format('Y-m-d'),
                'status' => $i <= $this->paid_installments ? 'paid' : 'pending',
            ];

            $currentDate->addMonth();
        }

        $this->payment_schedule = $schedule;
        $this->save();
    }

    public function addInstallmentPayment(float $amount, array $paymentData = []): ?ServiceOrderPayment
    {
        if (!$this->canAcceptInstallmentPayment()) {
            return null;
        }

        $nextInstallment = $this->paid_installments + 1;

        $paymentData = array_merge($paymentData, [
            'is_partial_payment' => true,
            'installment_number' => $nextInstallment,
            'payment_type' => 'installment',
            'expected_installment_amount' => $this->installment_amount,
            'installment_notes' => "Installment {$nextInstallment} of {$this->total_installments}",
        ]);

        return ServiceOrderPayment::createPayment($this, PaymentMethod::find($paymentData['payment_method_id'] ?? 1), $amount, $paymentData);
    }

    public function addPartialPayment(float $amount, array $paymentData = []): ?ServiceOrderPayment
    {
        if (!$this->canAcceptPartialPayment()) {
            return null;
        }

        $paymentData = array_merge($paymentData, [
            'is_partial_payment' => true,
            'payment_type' => 'partial',
        ]);

        return ServiceOrderPayment::createPayment($this, PaymentMethod::find($paymentData['payment_method_id'] ?? 1), $amount, $paymentData);
    }

    public function calculateTotal(): float
    {
        return $this->items->sum('total_price');
    }

    public function recalculateTotals(): void
    {
        $subtotal = $this->calculateTotal();
        $this->subtotal = $subtotal;
        $this->total_amount = (float) bcadd(bcsub(bcadd((string)$subtotal, (string)$this->tax_amount, 2), (string)$this->discount_amount, 2), '0', 2);
        $this->save();
    }

    public function getOutstandingAmount(): float
    {
        return max(0, $this->total_amount - $this->paid_amount + $this->refunded_amount);
    }

    public function canBeCancelled(): bool
    {
        return !in_array($this->status, ['completed', 'cancelled']);
    }

    public function canBeModified(): bool
    {
        return in_array($this->status, ['pending', 'confirmed']);
    }

    public function isOverdue(): bool
    {
        if (!$this->scheduled_date) {
            return false;
        }

        return $this->scheduled_date->isPast() && !in_array($this->status, ['completed', 'cancelled']);
    }

    // Accessors
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'warning',
            'confirmed' => 'info',
            'in_progress' => 'primary',
            'completed' => 'success',
            'cancelled' => 'secondary',
            'refunded' => 'danger',
            default => 'secondary',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            default => 'Unknown',
        };
    }

    public function getPaymentStatusColorAttribute(): string
    {
        return match ($this->payment_status) {
            'unpaid' => 'danger',
            'partially_paid' => 'warning',
            'paid' => 'success',
            'refunded' => 'secondary',
            'partially_refunded' => 'info',
            default => 'secondary',
        };
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return match ($this->payment_status) {
            'unpaid' => 'Unpaid',
            'partially_paid' => 'Partially Paid',
            'paid' => 'Paid',
            'refunded' => 'Refunded',
            'partially_refunded' => 'Partially Refunded',
            default => 'Unknown',
        };
    }

    public function getScheduledDateTimeAttribute(): ?string
    {
        if (!$this->scheduled_date) {
            return null;
        }

        $datetime = $this->scheduled_date;

        if ($this->scheduled_time) {
            $datetime = $datetime->setTimeFrom($this->scheduled_time);
        }

        return $datetime->format('Y-m-d H:i:s');
    }

    // Static methods
    public static function generateServiceOrderNumber(): string
    {
        do {
            $orderNumber = 'SO-' . date('Ymd') . '-' . strtoupper(Str::random(6));
        } while (static::where('service_order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    public static function createServiceOrder(Customer $customer, Store $store, array $items, array $options = []): self
    {
        $order = static::create([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'created_by' => $options['created_by'] ?? null,
            'assigned_to' => $options['assigned_to'] ?? null,
            'scheduled_date' => $options['scheduled_date'] ?? null,
            'scheduled_time' => $options['scheduled_time'] ?? null,
            'special_instructions' => $options['special_instructions'] ?? null,
            'notes' => $options['notes'] ?? null,
            'metadata' => $options['metadata'] ?? [],
        ]);

        // Create order items
        foreach ($items as $itemData) {
            $order->items()->create($itemData);
        }

        // Recalculate totals
        $order->recalculateTotals();

        return $order;
    }

    public static function getPendingCount(): int
    {
        return static::pending()->count();
    }

    public static function getTodayScheduledCount(): int
    {
        return static::scheduledToday()->count();
    }

    public static function getOverdueCount(): int
    {
        return static::where('scheduled_date', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();
    }
}
