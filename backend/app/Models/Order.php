<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AutoLogsActivity;

class Order extends Model
{
    use HasFactory, SoftDeletes, AutoLogsActivity;

    protected $fillable = [
        'order_number',
        'customer_id',
        'store_id',
        'order_type',
        'is_preorder',
        'stock_available_at',
        'preorder_notes',
        'status',
        'fulfillment_status',
        'payment_status',
        'payment_method',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'shipping_amount',
        'total_amount',
        'paid_amount',
        'outstanding_amount',
        'is_installment_payment',
        'total_installments',
        'paid_installments',
        'installment_amount',
        'next_payment_due',
        'allow_partial_payments',
        'minimum_payment_amount',
        'notes',
        'shipping_address',
        'billing_address',
        'order_date',
        'confirmed_at',
        'fulfilled_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'fulfilled_by',
        'created_by',
        'processed_by',
        'shipped_by',
        'tracking_number',
        'carrier_name',
        'intended_courier',
        'metadata',
        'payment_schedule',
        'payment_history',
        'salesman_id',
    ];

    protected $casts = [
        'is_preorder' => 'boolean',
        'is_installment_payment' => 'boolean',
        'stock_available_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'minimum_payment_amount' => 'decimal:2',
        'order_date' => 'datetime',
        'confirmed_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'next_payment_due' => 'date',
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'metadata' => 'array',
        'payment_schedule' => 'array',
        'payment_history' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        self::observe(\App\Observers\OrderObserver::class);

        static::creating(function ($order) {
            if (empty($order->order_number)) {
                $order->order_number = static::generateOrderNumber();
            }
            $order->order_date = $order->order_date ?? now();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ProductReturn::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function completedPayments()
    {
        return $this->payments()->completed();
    }

    public function pendingPayments()
    {
        return $this->payments()->pending();
    }

    // Payment methods
    public function addPayment(PaymentMethod $paymentMethod, float $amount, array $paymentData = [], Employee $processedBy = null): OrderPayment
    {
        return OrderPayment::createPayment($this, $paymentMethod, $amount, $paymentData, $processedBy);
    }

    public function updatePaymentStatus(): void
    {
        $totalPaid = $this->getTotalPaidAmount();
        $totalAmount = $this->total_amount;

        $this->paid_amount = $totalPaid;
        $this->outstanding_amount = max(0, $totalAmount - $totalPaid);

        // Check for overdue payments
        if ($this->next_payment_due && now()->gt($this->next_payment_due) && $this->outstanding_amount > 0) {
            $this->payment_status = 'unpaid'; // Changed from 'overdue' to match ENUM
        } elseif ($totalPaid >= $totalAmount) {
            $this->payment_status = 'paid';
        } elseif ($totalPaid > 0) {
            $this->payment_status = 'partial'; // Changed from 'partially_paid' to match ENUM
        } else {
            $this->payment_status = 'pending';
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
        // Since 'overdue' was removed from ENUM, check if unpaid AND past due date
        return ($this->payment_status === 'unpaid' || $this->payment_status === 'partial') &&
               $this->next_payment_due && 
               now()->gt($this->next_payment_due) && 
               $this->outstanding_amount > 0;
    }

    public function getDaysOverdue(): int
    {
        if (!$this->next_payment_due || !$this->isPaymentOverdue()) {
            return 0;
        }

        return now()->diffInDays($this->next_payment_due);
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
            'next_payment_due' => $startDate,
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

    public function addInstallmentPayment(float $amount, array $paymentData = []): ?OrderPayment
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

        $payment = $this->addPayment(
            PaymentMethod::find($paymentData['payment_method_id'] ?? 1),
            $amount,
            $paymentData
        );

        // Update installment progress
        $this->updateInstallmentProgress();

        return $payment;
    }

    public function addPartialPayment(float $amount, array $paymentData = []): ?OrderPayment
    {
        if (!$this->canAcceptPartialPayment()) {
            return null;
        }

        $paymentData = array_merge($paymentData, [
            'is_partial_payment' => true,
            'payment_type' => 'partial',
        ]);

        return $this->addPayment(
            PaymentMethod::find($paymentData['payment_method_id'] ?? 1),
            $amount,
            $paymentData
        );
    }

    public function getTotalPaidAmount(): float
    {
        return $this->completedPayments()->sum('amount');
    }

    public function getTotalRefundedAmount(): float
    {
        return $this->payments()->refunded()->sum('refunded_amount');
    }

    public function getRemainingAmount(): float
    {
        return $this->total_amount - $this->getTotalPaidAmount();
    }

    public function isFullyPaid(): bool
    {
        return $this->getTotalPaidAmount() >= $this->total_amount;
    }

    public function isPartiallyPaid(): bool
    {
        $paid = $this->getTotalPaidAmount();
        return $paid > 0 && $paid < $this->total_amount;
    }

    public function canAcceptPayment(): bool
    {
        return !$this->isCancelled() && !$this->isFullyPaid();
    }

    public function getAvailablePaymentMethods(): array
    {
        return PaymentMethod::getAvailableMethodsForCustomerType($this->customer->customer_type);
    }

    public function processPayment(OrderPayment $payment, string $transactionReference = null, string $externalReference = null): bool
    {
        if (!$payment->process()) {
            return false;
        }

        return $payment->complete($transactionReference, $externalReference);
    }

    public function refundPayment(OrderPayment $payment, float $refundAmount, string $reason = null): bool
    {
        return $payment->refund($refundAmount, $reason);
    }

    // Helper methods for payment display
    public function getPaymentSummaryAttribute(): array
    {
        $payments = $this->payments;

        return [
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->getTotalPaidAmount(),
            'remaining_amount' => $this->getRemainingAmount(),
            'refunded_amount' => $this->getTotalRefundedAmount(),
            'payment_count' => $payments->count(),
            'completed_payments' => $payments->completed()->count(),
            'pending_payments' => $payments->pending()->count(),
            'failed_payments' => $payments->failed()->count(),
            'is_fully_paid' => $this->isFullyPaid(),
            'is_partially_paid' => $this->isPartiallyPaid(),
        ];
    }

    public function getPaymentMethodsUsedAttribute(): array
    {
        return $this->completedPayments()
            ->with('paymentMethod')
            ->get()
            ->pluck('paymentMethod.name')
            ->unique()
            ->values()
            ->toArray();
    }

    public function activeShipment()
    {
        return $this->shipments()->whereNotIn('status', ['delivered', 'cancelled', 'returned'])->first();
    }

    public function deliveredShipments()
    {
        return $this->shipments()->delivered();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processed_by');
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'shipped_by');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'fulfilled_by');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'salesman_id');
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

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeShipped($query)
    {
        return $query->where('status', 'shipped');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('order_type', $type);
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'pending');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('order_date', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('order_date', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('order_date', now()->month)
                    ->whereYear('order_date', now()->year);
    }

    // Status checks
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isShipped(): bool
    {
        return $this->status === 'shipped';
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isPendingFulfillment(): bool
    {
        return $this->fulfillment_status === 'pending_fulfillment' || 
               ($this->needsFulfillment() && empty($this->fulfillment_status) && !$this->isFulfilled());
    }

    public function isFulfilled(): bool
    {
        return $this->fulfillment_status === 'fulfilled';
    }

    public function needsFulfillment(): bool
    {
        return in_array($this->order_type, ['social_commerce', 'ecommerce']);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    // Workflow methods
    public function confirm()
    {
        if (!$this->isPending()) {
            throw new \Exception('Order cannot be confirmed in its current status.');
        }

        $this->status = 'confirmed';
        $this->confirmed_at = now();
        $this->save();

        return $this;
    }

    public function startProcessing(Employee $employee)
    {
        if (!$this->isConfirmed()) {
            throw new \Exception('Order must be confirmed before processing.');
        }

        $this->status = 'processing';
        $this->processed_by = $employee->id;
        $this->save();

        return $this;
    }

    public function markReadyForPickup()
    {
        if (!$this->isProcessing()) {
            throw new \Exception('Order must be processing before marking ready for pickup.');
        }

        $this->status = 'ready_for_pickup';
        $this->save();

        return $this;
    }

    public function ship($trackingNumber = null, $carrierName = null, Employee $shippedBy = null)
    {
        if (!in_array($this->status, ['processing', 'ready_for_pickup'])) {
            throw new \Exception('Order cannot be shipped in its current status.');
        }

        $this->status = 'shipped';
        $this->shipped_at = now();
        $this->tracking_number = $trackingNumber;
        $this->carrier_name = $carrierName;

        if ($shippedBy) {
            $this->shipped_by = $shippedBy->id;
        }

        $this->save();

        return $this;
    }

    public function deliver()
    {
        if (!$this->isShipped()) {
            throw new \Exception('Order must be shipped before delivery.');
        }

        $this->status = 'delivered';
        $this->delivered_at = now();
        $this->save();

        // Update customer purchase history
        $this->customer->recordPurchase($this->total_amount, $this->id);

        return $this;
    }

    /**
     * Mark order as fulfilled (barcodes scanned for social commerce/ecommerce)
     */
    public function fulfill(Employee $fulfilledBy)
    {
        if (!$this->canBeFulfilled()) {
            throw new \Exception('Order cannot be fulfilled in its current status.');
        }

        $this->fulfillment_status = 'fulfilled';
        $this->fulfilled_at = now();
        $this->fulfilled_by = $fulfilledBy->id;
        $this->save();

        return $this;
    }

    public function cancel()
    {
        if (in_array($this->status, ['delivered', 'cancelled'])) {
            throw new \Exception('Order cannot be cancelled in its current status.');
        }

        $this->status = 'cancelled';
        $this->cancelled_at = now();
        $this->save();

        return $this;
    }

    public function markAsPaid($paymentMethod = null)
    {
        $this->payment_status = 'paid';
        if ($paymentMethod) {
            $this->payment_method = $paymentMethod;
        }
        $this->save();

        return $this;
    }

    // Calculation methods
    public function calculateTotals()
    {
        $taxMode = config('app.tax_mode', 'inclusive');
        
        $subtotal = $this->items->sum('total_amount');
        $taxAmount = $this->items->sum('tax_amount');
        $discountAmount = $this->items->sum('discount_amount');

        $this->subtotal = $subtotal;
        $this->tax_amount = $taxAmount;
        $this->discount_amount = $discountAmount;

        if ($taxMode === 'inclusive') {
            // Inclusive: Tax is already included in subtotal
            // Total = subtotal - discount + shipping
            $this->attributes['total_amount'] = bcadd(bcsub($subtotal, $discountAmount, 2), $this->shipping_amount, 2);
        } else {
            // Exclusive: Tax is calculated on top of subtotal
            // Total = subtotal + tax - discount + shipping
            $totalBeforeShipping = bcadd(bcsub($subtotal, $discountAmount, 2), $taxAmount, 2);
            $this->attributes['total_amount'] = bcadd($totalBeforeShipping, $this->shipping_amount, 2);
        }

        $this->save();
        $this->updatePaymentStatus();

        return $this;
    }

    public function addItem(Product $product, $quantity, $unitPrice = null, $options = [])
    {
        $unitPrice = $unitPrice ?? $product->getCurrentPrice($this->store_id);
        $totalAmount = $quantity * $unitPrice;

        $item = $this->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_amount' => $totalAmount,
            'product_options' => $options,
        ]);

        $this->calculateTotals();

        return $item;
    }

    public function removeItem(OrderItem $item)
    {
        $item->delete();
        $this->calculateTotals();

        return $this;
    }

    // Helper methods
    public function getShippingAddressFormattedAttribute()
    {
        if (!$this->shipping_address) {
            return $this->customer->full_address;
        }

        $address = $this->shipping_address;
        return implode(', ', array_filter([
            $address['address'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['postal_code'] ?? null,
            $address['country'] ?? null,
        ]));
    }

    public function getBillingAddressFormattedAttribute()
    {
        if (!$this->billing_address) {
            return $this->customer->full_address;
        }

        $address = $this->billing_address;
        return implode(', ', array_filter([
            $address['address'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['postal_code'] ?? null,
            $address['country'] ?? null,
        ]));
    }

    public function getOrderTypeLabelAttribute()
    {
        return match($this->order_type) {
            'counter' => 'Counter Sale',
            'social_commerce' => 'Social Commerce',
            'ecommerce' => 'E-commerce',
            default => 'Unknown',
        };
    }

    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'pending' => 'gray',
            'confirmed' => 'blue',
            'processing' => 'yellow',
            'ready_for_pickup' => 'orange',
            'shipped' => 'purple',
            'delivered' => 'green',
            'cancelled' => 'red',
            'refunded' => 'red',
            default => 'gray',
        };
    }

    public function getPaymentStatusColorAttribute()
    {
        return match($this->payment_status) {
            'pending' => 'gray',
            'paid' => 'green',
            'failed' => 'red',
            'refunded' => 'orange',
            default => 'gray',
        };
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, ['pending', 'confirmed']);
    }

    public function canBeCancelled(): bool
    {
        return !in_array($this->status, ['delivered', 'cancelled', 'refunded']);
    }

    public function canBeFulfilled(): bool
    {
        return $this->isPendingFulfillment() && !$this->isCancelled();
    }

    public function canBeShipped(): bool
    {
        return in_array($this->status, ['processing', 'ready_for_pickup']);
    }

    // Static methods
    public static function generateOrderNumber(): string
    {
        do {
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
        } while (static::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    public static function getOrderStats($storeId = null)
    {
        $query = static::query();

        if ($storeId) {
            $query->byStore($storeId);
        }

        return [
            'total_orders' => $query->count(),
            'pending_orders' => (clone $query)->pending()->count(),
            'processing_orders' => (clone $query)->processing()->count(),
            'shipped_orders' => (clone $query)->shipped()->count(),
            'delivered_orders' => (clone $query)->delivered()->count(),
            'cancelled_orders' => (clone $query)->cancelled()->count(),
            'total_revenue' => $query->paid()->sum('total_amount'),
            'today_orders' => (clone $query)->today()->count(),
            'today_revenue' => (clone $query)->today()->paid()->sum('total_amount'),
        ];
    }

    public static function createCounterOrder(Customer $customer, Store $store, Employee $createdBy, array $items = [])
    {
        $order = static::create([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'order_type' => 'counter',
            'created_by' => $createdBy->id,
        ]);

        foreach ($items as $itemData) {
            $product = Product::find($itemData['product_id']);
            $order->addItem($product, $itemData['quantity'], $itemData['unit_price'] ?? null, $itemData['options'] ?? []);
        }

        return $order;
    }

    public static function createSocialCommerceOrder(Customer $customer, Store $store, Employee $createdBy, array $items = [])
    {
        $order = static::create([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'order_type' => 'social_commerce',
            'created_by' => $createdBy->id,
        ]);

        foreach ($items as $itemData) {
            $product = Product::find($itemData['product_id']);
            $order->addItem($product, $itemData['quantity'], $itemData['unit_price'] ?? null, $itemData['options'] ?? []);
        }

        return $order;
    }

    public function createShipment(array $shipmentData = [])
    {
        if ($this->isCancelled()) {
            throw new \Exception('Cannot create shipment for cancelled order');
        }

        if ($this->activeShipment()) {
            throw new \Exception('Order already has an active shipment');
        }

        return Shipment::createFromOrder($this, $shipmentData);
    }

    public function canCreateShipment(): bool
    {
        return !$this->isCancelled() && !$this->activeShipment();
    }

    public function getShipmentStatus()
    {
        $shipment = $this->activeShipment();
        return $shipment ? $shipment->status : null;
    }

    public function getTrackingNumber()
    {
        $shipment = $this->activeShipment();
        return $shipment ? ($shipment->pathao_tracking_number ?? $shipment->shipment_number) : null;
    }
}