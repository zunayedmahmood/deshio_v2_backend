<?php

namespace App\Models;

use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use App\Traits\AutoLogsActivity;
use App\Models\OrderPayment;
use App\Models\Refund;
use App\Models\ProductReturn;
use App\Models\Order;
use App\Models\Store;
use App\Models\Employee;
use App\Models\Account;

class Transaction extends Model
{
    use HasFactory, DatabaseAgnosticSearch, AutoLogsActivity;

    protected $fillable = [
        'transaction_number',
        'transaction_date',
        'amount',
        'type',
        'account_id',
        'reference_type',
        'reference_id',
        'description',
        'store_id',
        'created_by',
        'metadata',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'metadata' => 'array',
    ];

    protected $appends = ['reference_label', 'display_id'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            if (empty($transaction->transaction_number)) {
                $transaction->transaction_number = static::generateTransactionNumber();
            }

            if (empty($transaction->transaction_date)) {
                $transaction->transaction_date = now()->toDateString();
            }
        });
    }

    // Relationships
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    // Accessors for metadata-driven features
    public function getGroupIdAttribute(): ?string
    {
        return $this->metadata['group_id'] ?? null;
    }

    public function getAttachmentsAttribute(): array
    {
        return $this->metadata['attachments'] ?? [];
    }

    public function getAdditionalReferencesAttribute(): array
    {
        return $this->metadata['additional_references'] ?? [];
    }

    /**
     * Get all transactions belonging to the same business event group.
     * Groups by group_id (if exists) or by reference_type/reference_id.
     */
    public function getRelatedTransactions()
    {
        $groupId = $this->group_id;
        
        if ($groupId) {
            return static::where('metadata->group_id', $groupId)
                ->with(['account', 'store', 'createdBy'])
                ->orderBy('id', 'asc')
                ->get();
        }

        return static::where('reference_type', $this->reference_type)
            ->where('reference_id', $this->reference_id)
            ->with(['account', 'store', 'createdBy'])
            ->orderBy('id', 'asc')
            ->get();
    }

    // Human-readable labels for reference types
    public function getReferenceLabelAttribute(): string
    {
        // Check for manual reference type set in metadata or directly
        if ($this->reference_type === 'manual') return 'Manual Entry';
        
        $type = $this->reference_type;
        
        // Handle full class names if present
        if (str_contains($type, '\\')) {
            $type = class_basename($type);
        }

        return match ($type) {
            'OrderPayment', 'ServiceOrderPayment' => 'Order Payment',
            'Expense' => 'Expense',
            'ExpensePayment' => 'Expense Payment',
            'Refund' => 'Refund',
            'ProductReturn' => 'Product Return',
            'VendorPayment' => 'Vendor Payment',
            'Order' => 'Store Order',
            'PurchaseOrder' => 'Purchase Order',
            'manual' => 'Manual Entry',
            default => $type ?: 'N/A'
        };
    }

    // Human-readable display ID
    public function getDisplayIdAttribute(): string
    {
        $year = $this->transaction_date ? $this->transaction_date->format('Y') : date('Y');
        return "TXN-{$year}-" . str_pad($this->id, 4, '0', STR_PAD_LEFT);
    }

    // Scopes
    public function scopeDebit($query)
    {
        return $query->where('type', 'debit');
    }

    public function scopeCredit($query)
    {
        return $query->where('type', 'credit');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeByAccount($query, $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeByStore($query, $storeId)
    {
        if ($storeId === 'all' || $storeId === '' || $storeId === null) {
            return $query;
        }
        
        if ($storeId === 'global' || $storeId === 'Deshio' || $storeId === 'NULL') {
            return $query->whereNull('store_id');
        }

        return $query->where('store_id', $storeId);
    }

    public function scopeByReference($query, $referenceType, $referenceId)
    {
        return $query->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('transaction_date', now()->month)
                    ->whereYear('transaction_date', now()->year);
    }

    public function scopeThisYear($query)
    {
        return $query->whereYear('transaction_date', now()->year);
    }

    // Business logic methods
    public function complete(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->status = 'completed';
        return $this->save();
    }

    public function fail(string $reason = null): bool
    {
        if ($this->status === 'completed') {
            return false;
        }

        $this->status = 'failed';
        $this->metadata = array_merge($this->metadata ?? [], ['failure_reason' => $reason]);
        return $this->save();
    }

    public function cancel(string $reason = null): bool
    {
        if ($this->status === 'completed') {
            return false;
        }

        $this->status = 'cancelled';
        $this->metadata = array_merge($this->metadata ?? [], ['cancellation_reason' => $reason]);
        return $this->save();
    }

    public function isDebit(): bool
    {
        return $this->type === 'debit';
    }

    public function isCredit(): bool
    {
        return $this->type === 'credit';
    }

    // Static methods for creating transactions
    public static function createFromOrderPayment(OrderPayment $payment): self
    {
        $status = $payment->status === 'completed' ? 'completed' : 'pending';
        $transactionDate = $payment->completed_at ?? $payment->processed_at ?? now();
        $cashAccountId = static::getCashAccountId($payment->store_id);
        $salesRevenueAccountId = static::getSalesRevenueAccountId();
        $taxLiabilityAccountId = static::getTaxLiabilityAccountId();
        $groupId = (string) Str::uuid();

        $metadata = [
            'payment_method' => $payment->paymentMethod->name ?? 'Unknown',
            'order_number' => $payment->order->order_number ?? null,
            'customer_name' => $payment->customer->name ?? null,
            'group_id' => $groupId,
        ];

        // Calculate proportional tax for this payment (inclusive tax system)
        $order = $payment->order;
        $paymentAmount = (float)$payment->amount;
        
        // Calculate tax proportion based on order total
        if ($order && $order->total_amount > 0) {
            // Tax ratio = order tax / order total
            $taxRatio = (float)$order->tax_amount / (float)$order->total_amount;
            // Apply ratio to payment amount to get proportional tax
            $taxAmount = round($paymentAmount * $taxRatio, 2);
        } else {
            $taxAmount = 0;
        }
        
        $revenueAmount = $paymentAmount - $taxAmount;

        // DOUBLE-ENTRY BOOKKEEPING WITH INCLUSIVE TAX:
        // 1. Debit Cash Account (Asset increases - full amount including tax)
        $debitTransaction = static::create([
            'transaction_date' => $transactionDate,
            'amount' => $paymentAmount,
            'type' => 'debit',
            'account_id' => $cashAccountId,
            'reference_type' => OrderPayment::class,
            'reference_id' => $payment->id,
            'description' => "Order Payment - {$payment->payment_number}",
            'store_id' => $payment->store_id,
            'created_by' => $payment->processed_by,
            'metadata' => array_merge($metadata, [
                'includes_tax' => $taxAmount > 0,
                'tax_amount' => $taxAmount,
            ]),
            'status' => $status,
        ]);

        // 2. Credit Sales Revenue Account (Income - excluding tax)
        static::create([
            'transaction_date' => $transactionDate,
            'amount' => $revenueAmount,
            'type' => 'credit',
            'account_id' => $salesRevenueAccountId,
            'reference_type' => OrderPayment::class,
            'reference_id' => $payment->id,
            'description' => "Order Revenue (excl. tax) - {$payment->payment_number}",
            'store_id' => $payment->store_id,
            'created_by' => $payment->processed_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        // 3. Credit Tax Liability Account (Liability - tax collected)
        if ($taxAmount > 0) {
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $taxAmount,
                'type' => 'credit',
                'account_id' => $taxLiabilityAccountId,
                'reference_type' => OrderPayment::class,
                'reference_id' => $payment->id,
                'description' => "Sales Tax Collected - {$payment->payment_number}",
                'store_id' => $payment->store_id,
                'created_by' => $payment->processed_by,
                'metadata' => array_merge($metadata, [
                    'tax_type' => 'sales_tax',
                    'order_subtotal' => $order->subtotal ?? 0,
                ]),
                'status' => $status,
            ]);
        }

        return $debitTransaction;
    }

    public static function createFromServiceOrderPayment(ServiceOrderPayment $payment): self
    {
        $status = $payment->status === 'completed' ? 'completed' : 'pending';
        $transactionDate = $payment->completed_at ?? $payment->processed_at ?? now();
        $cashAccountId = static::getCashAccountId($payment->store_id);
        $serviceRevenueAccountId = static::getServiceRevenueAccountId();
        $groupId = (string) Str::uuid();

        $metadata = [
            'payment_method' => $payment->paymentMethod->name ?? 'Unknown',
            'service_order_number' => $payment->serviceOrder->order_number ?? null,
            'customer_name' => $payment->customer->name ?? null,
            'group_id' => $groupId,
        ];

        // DOUBLE-ENTRY BOOKKEEPING:
        // 1. Debit Cash Account (Asset increases)
        $debitTransaction = static::create([
            'transaction_date' => $transactionDate,
            'amount' => $payment->amount,
            'type' => 'debit',
            'account_id' => $cashAccountId,
            'reference_type' => ServiceOrderPayment::class,
            'reference_id' => $payment->id,
            'description' => "Service Order Payment - {$payment->payment_number}",
            'store_id' => $payment->store_id,
            'created_by' => $payment->processed_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        // 2. Credit Service Revenue Account (Income increases)
        static::create([
            'transaction_date' => $transactionDate,
            'amount' => $payment->amount,
            'type' => 'credit',
            'account_id' => $serviceRevenueAccountId,
            'reference_type' => ServiceOrderPayment::class,
            'reference_id' => $payment->id,
            'description' => "Service Order Payment - {$payment->payment_number}",
            'store_id' => $payment->store_id,
            'created_by' => $payment->processed_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        return $debitTransaction;
    }

    public static function createFromRefund(Refund $refund): self
    {
        $status = $refund->status === 'completed' ? 'completed' : 'pending';
        $transactionDate = $refund->completed_at ?? now();
        $cashAccountId = static::getCashAccountId($refund->order->store_id ?? null);
        $salesRevenueAccountId = static::getSalesRevenueAccountId();
        $taxLiabilityAccountId = static::getTaxLiabilityAccountId();

        $refundAmount = (float)$refund->refund_amount;
        $groupId = (string) Str::uuid();

        // Calculate proportional tax reversal (inclusive tax system)
        $order = $refund->order;
        if ($order && $order->total_amount > 0 && $order->tax_amount > 0) {
            $taxRatio = (float)$order->tax_amount / (float)$order->total_amount;
            $taxAmount = round($refundAmount * $taxRatio, 2);
        } else {
            $taxAmount = 0;
        }
        $revenueAmount = $refundAmount - $taxAmount;

        $metadata = [
            'refund_method' => $refund->refund_method,
            'order_number' => $order->order_number ?? null,
            'customer_name' => $refund->customer->name ?? null,
            'refund_type' => $refund->refund_type,
            'includes_tax_reversal' => $taxAmount > 0,
            'tax_amount_reversed' => $taxAmount,
            'group_id' => $groupId,
        ];

        // DOUBLE-ENTRY BOOKKEEPING (Reversal of sale):
        // 1. Credit Cash Account (Asset decreases - money going out as refund)
        $creditTransaction = static::create([
            'transaction_date' => $transactionDate,
            'amount' => $refundAmount,
            'type' => 'credit',
            'account_id' => $cashAccountId,
            'reference_type' => Refund::class,
            'reference_id' => $refund->id,
            'description' => "Cash Refund - {$refund->refund_number}",
            'store_id' => $order->store_id ?? null,
            'created_by' => $refund->processed_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        // 2. Debit Sales Revenue Account (Revenue decreases - reverse net revenue)
        static::create([
            'transaction_date' => $transactionDate,
            'amount' => $revenueAmount,
            'type' => 'debit',
            'account_id' => $salesRevenueAccountId,
            'reference_type' => Refund::class,
            'reference_id' => $refund->id,
            'description' => "Revenue Reversal (excl. tax) - {$refund->refund_number}",
            'store_id' => $order->store_id ?? null,
            'created_by' => $refund->processed_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        // 3. Debit Tax Liability Account (Liability decreases - tax being refunded)
        if ($taxAmount > 0) {
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $taxAmount,
                'type' => 'debit',
                'account_id' => $taxLiabilityAccountId,
                'reference_type' => Refund::class,
                'reference_id' => $refund->id,
                'description' => "Tax Reversal - {$refund->refund_number}",
                'store_id' => $order->store_id ?? null,
                'created_by' => $refund->processed_by,
                'metadata' => $metadata,
                'status' => $status,
            ]);
        }

        return $creditTransaction;
    }

    /**
     * Create COGS/Inventory reversal entries when a return is accepted (items going back to stock).
     * Call this after createFromRefund when the return items are confirmed as restocked.
     */
    public static function createFromRefundCOGS(\App\Models\ProductReturn $productReturn): void
    {
        $status = 'completed';
        $transactionDate = $productReturn->updated_at ?? now();
        $inventoryAccountId = static::getInventoryAccountId();
        $cogsAccountId = static::getCOGSAccountId();
        $storeId = $productReturn->order->store_id ?? null;

        // Calculate total return value (cost basis of returned items)
        $returnCostValue = (float)$productReturn->total_return_value;
        $groupId = (string) Str::uuid();

        if ($returnCostValue <= 0) {
            return; // Nothing to reverse
        }

        $metadata = [
            'return_number' => $productReturn->return_number,
            'order_number' => $productReturn->order->order_number ?? null,
            'customer_name' => $productReturn->customer->name ?? null,
            'return_reason' => $productReturn->reason,
            'group_id' => $groupId,
        ];

        // DOUBLE-ENTRY BOOKKEEPING (Reverse of COGS recognized at sale):
        // 1. Debit Inventory (Asset increases - items back in stock)
        static::create([
            'transaction_date' => $transactionDate,
            'amount' => $returnCostValue,
            'type' => 'debit',
            'account_id' => $inventoryAccountId,
            'reference_type' => \App\Models\ProductReturn::class,
            'reference_id' => $productReturn->id,
            'description' => "Return - Inventory Restored - {$productReturn->return_number}",
            'store_id' => $storeId,
            'created_by' => $productReturn->processed_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        // 2. Credit COGS (Expense decreases - reversing cost of sold goods)
        static::create([
            'transaction_date' => $transactionDate,
            'amount' => $returnCostValue,
            'type' => 'credit',
            'account_id' => $cogsAccountId,
            'reference_type' => \App\Models\ProductReturn::class,
            'reference_id' => $productReturn->id,
            'description' => "Return - COGS Reversal - {$productReturn->return_number}",
            'store_id' => $storeId,
            'created_by' => $productReturn->processed_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);
    }

    /**
     * Create double-entry journal for a product exchange (return old item + give new item).
     * Handles three scenarios:
     *   A) Same price: Only COGS/Inventory swap, no cash/revenue impact.
     *   B) New item more expensive: Customer pays the difference (cash in, revenue credit).
     *   C) New item less expensive: Store refunds the difference (cash out, revenue debit).
     */
    public static function createFromExchange(\App\Models\ProductReturn $productReturn, Order $newOrder): void
    {
        $status = 'completed';
        $transactionDate = now();
        $inventoryAccountId = static::getInventoryAccountId();
        $cogsAccountId = static::getCOGSAccountId();
        $cashAccountId = static::getCashAccountId($newOrder->store_id);
        $salesRevenueAccountId = static::getSalesRevenueAccountId();
        $storeId = $newOrder->store_id;

        $oldItemValue = (float)$productReturn->total_return_value;
        $newOrderTotal = (float)$newOrder->total_amount;
        // Use actual cost (COGS) of the new item, not its selling price
        $newItemCOGS = $newOrder->relationLoaded('items')
            ? (float)$newOrder->items->sum('cogs')
            : (float)$newOrder->load('items')->items->sum('cogs');
        $netDifference = round($newOrderTotal - $oldItemValue, 2); // price diff for cash settlement
        $groupId = (string) Str::uuid();

        $metadata = [
            'exchange_type' => $netDifference > 0 ? 'upgrade' : ($netDifference < 0 ? 'downgrade' : 'even'),
            'return_number' => $productReturn->return_number,
            'old_item_value' => $oldItemValue,
            'new_order_id' => $newOrder->id,          // Required by createFromOrderCOGS guard
            'new_order_number' => $newOrder->order_number,
            'new_order_total' => $newOrderTotal,
            'net_difference' => $netDifference,
            'group_id' => $groupId,
        ];

        // === ENTRY 1: Reverse old item inventory (back to stock) ===
        // Debit Inventory (old item returned to shelf)
        if ($oldItemValue > 0) {
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $oldItemValue,
                'type' => 'debit',
                'account_id' => $inventoryAccountId,
                'reference_type' => \App\Models\ProductReturn::class,
                'reference_id' => $productReturn->id,
                'description' => "Exchange - Old Item Returned to Stock - {$productReturn->return_number}",
                'store_id' => $storeId,
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => $status,
            ]);

            // Credit COGS (reversing cost of old item)
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $oldItemValue,
                'type' => 'credit',
                'account_id' => $cogsAccountId,
                'reference_type' => \App\Models\ProductReturn::class,
                'reference_id' => $productReturn->id,
                'description' => "Exchange - Old Item COGS Reversal - {$productReturn->return_number}",
                'store_id' => $storeId,
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => $status,
            ]);
        }

        // === ENTRY 2: Record new item COGS ===
        // Debit COGS (cost of new item given out — use actual cost, not selling price)
        if ($newItemCOGS > 0) {
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $newItemCOGS,
                'type' => 'debit',
                'account_id' => $cogsAccountId,
                'reference_type' => Order::class,
                'reference_id' => $newOrder->id,
                'description' => "Exchange - New Item COGS - {$newOrder->order_number}",
                'store_id' => $storeId,
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => $status,
            ]);

            // Credit Inventory (new item removed from stock)
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $newItemCOGS,
                'type' => 'credit',
                'account_id' => $inventoryAccountId,
                'reference_type' => Order::class,
                'reference_id' => $newOrder->id,
                'description' => "Exchange - New Item Out of Stock - {$newOrder->order_number}",
                'store_id' => $storeId,
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => $status,
            ]);
        }

        // === ENTRY 3: Handle net cash/revenue difference ===
        if ($netDifference > 0) {
            // Scenario B: New item more expensive, customer pays the difference
            // Debit Cash (money in)
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $netDifference,
                'type' => 'debit',
                'account_id' => $cashAccountId,
                'reference_type' => Order::class,
                'reference_id' => $newOrder->id,
                'description' => "Exchange Upcharge (Cash In) - {$newOrder->order_number}",
                'store_id' => $storeId,
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => $status,
            ]);

            // Credit Sales Revenue (additional revenue)
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $netDifference,
                'type' => 'credit',
                'account_id' => $salesRevenueAccountId,
                'reference_type' => Order::class,
                'reference_id' => $newOrder->id,
                'description' => "Exchange Revenue (Price Upcharge) - {$newOrder->order_number}",
                'store_id' => $storeId,
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => $status,
            ]);
        } elseif ($netDifference < 0) {
            // Scenario C: New item less expensive, store refunds the difference
            $refundDiff = abs($netDifference);

            // Debit Sales Revenue (revenue reduced)
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $refundDiff,
                'type' => 'debit',
                'account_id' => $salesRevenueAccountId,
                'reference_type' => \App\Models\ProductReturn::class,
                'reference_id' => $productReturn->id,
                'description' => "Exchange Revenue Reduction (Price Downgrade) - {$productReturn->return_number}",
                'store_id' => $storeId,
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => $status,
            ]);

            // Credit Cash (money out - store owes customer the difference)
            static::create([
                'transaction_date' => $transactionDate,
                'amount' => $refundDiff,
                'type' => 'credit',
                'account_id' => $cashAccountId,
                'reference_type' => \App\Models\ProductReturn::class,
                'reference_id' => $productReturn->id,
                'description' => "Exchange Refund (Cash Out) - {$productReturn->return_number}",
                'store_id' => $storeId,
                'created_by' => auth()->id(),
                'metadata' => $metadata,
                'status' => $status,
            ]);
        }
        // Scenario A (even): No cash/revenue entries needed
    }

    public static function createFromExpense(Expense $expense): self
    {
        $status = $expense->payment_status === 'paid' ? 'completed' : 'pending';
        $groupId = (string) Str::uuid();
        $expenseAccountId = static::getExpenseAccountId($expense->category_id);
        $cashAccountId = static::getCashAccountId($expense->store_id);

        $metadata = [
            'expense_category' => $expense->category->name ?? null,
            'vendor_name' => $expense->vendor->name ?? null,
            'expense_type' => $expense->expense_type,
            'group_id' => $groupId,
        ];

        // DOUBLE-ENTRY BOOKKEEPING:
        // 1. Debit Expense Account (Expense increases - cost recognised)
        $debitTransaction = static::create([
            'transaction_date' => $expense->expense_date,
            'amount' => $expense->total_amount,
            'type' => 'debit',
            'account_id' => $expenseAccountId,
            'reference_type' => Expense::class,
            'reference_id' => $expense->id,
            'description' => "Expense - {$expense->expense_number}: " . ($expense->description ?? 'No description'),
            'store_id' => $expense->store_id,
            'created_by' => $expense->created_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        // 2. Credit Cash Account (Asset decreases - money going out)
        static::create([
            'transaction_date' => $expense->expense_date,
            'amount' => $expense->total_amount,
            'type' => 'credit',
            'account_id' => $cashAccountId,
            'reference_type' => Expense::class,
            'reference_id' => $expense->id,
            'description' => "Expense Payment (Cash) - {$expense->expense_number}",
            'store_id' => $expense->store_id,
            'created_by' => $expense->created_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        return $debitTransaction;
    }

    public static function createFromExpensePayment(ExpensePayment $payment): self
    {
        $status = $payment->status === 'completed' ? 'completed' : 'pending';
        $groupId = (string) Str::uuid();

        return static::create([
            'transaction_date' => $payment->completed_at ?? $payment->processed_at ?? now(),
            'amount' => $payment->amount,
            'type' => 'credit', // Money going out for expense payment
            'account_id' => static::getCashAccountId($payment->expense->store_id),
            'reference_type' => ExpensePayment::class,
            'reference_id' => $payment->id,
            'description' => "Expense Payment - {$payment->payment_number}: " . ($payment->expense->description ?? 'No description'),
            'store_id' => $payment->expense->store_id,
            'created_by' => $payment->processed_by,
            'metadata' => [
                'payment_method' => $payment->paymentMethod->name ?? 'Unknown',
                'expense_number' => $payment->expense->expense_number ?? null,
                'expense_description' => $payment->expense->description ?? null,
                'group_id' => $groupId,
            ],
            'status' => $status,
        ]);
    }

    public static function createFromVendorPayment(VendorPayment $payment): self
    {
        $status = $payment->status === 'completed' ? 'completed' : 'pending';
        $transactionDate = $payment->processed_at ?? $payment->payment_date ?? now();
        $inventoryAccountId = static::getInventoryAccountId();
        $cashAccountId = static::getCashAccountId();
        $groupId = (string) Str::uuid();

        $metadata = [
            'payment_method' => $payment->paymentMethod->name ?? 'Unknown',
            'vendor_name' => $payment->vendor->name ?? null,
            'payment_type' => $payment->payment_type,
            'allocated_amount' => $payment->allocated_amount,
            'unallocated_amount' => $payment->unallocated_amount,
            'group_id' => $groupId,
        ];

        // DOUBLE-ENTRY BOOKKEEPING:
        // 1. Debit Inventory Account (Asset increases - stock received)
        $debitTransaction = static::create([
            'transaction_date' => $transactionDate,
            'amount' => $payment->amount,
            'type' => 'debit',
            'account_id' => $inventoryAccountId,
            'reference_type' => VendorPayment::class,
            'reference_id' => $payment->id,
            'description' => "Inventory Purchase - {$payment->payment_number}",
            'created_by' => $payment->employee_id,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        // 2. Credit Cash Account (Asset decreases - money going out to vendor)
        static::create([
            'transaction_date' => $transactionDate,
            'amount' => $payment->amount,
            'type' => 'credit',
            'account_id' => $cashAccountId,
            'reference_type' => VendorPayment::class,
            'reference_id' => $payment->id,
            'description' => "Vendor Payment - {$payment->payment_number}",
            'created_by' => $payment->employee_id,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        return $debitTransaction;
    }

    public static function createFromOrderCOGS(Order $order): self
    {
        $cogsAccountId = static::getCOGSAccountId();

        // [EXCHANGE DOUBLE-COUNT GUARD]
        // If this order was the "new item" in an exchange (ProductReturn::class reference exists
        // for this order_id in the COGS account), skip to avoid booking the cost twice.
        $exchangeCOGSExists = static::where('account_id', $cogsAccountId)
            ->where('reference_type', \App\Models\ProductReturn::class)
            ->whereJsonContains('metadata->new_order_id', $order->id)
            ->exists();

        if ($exchangeCOGSExists) {
            // Return an empty/dummy instance — caller can discard it; no DB entry is needed.
            return new static([
                'amount' => 0,
                'type' => 'debit',
                'description' => 'COGS skipped — already recorded via exchange',
            ]);
        }

        $status = $order->status === 'completed' ? 'completed' : 'pending';
        $transactionDate = $order->completed_at ?? now();
        $inventoryAccountId = static::getInventoryAccountId();
        $groupId = (string) Str::uuid();
        
        // Calculate total COGS from all order items
        $totalCOGS = $order->items->sum('cogs');

        $metadata = [
            'order_number' => $order->order_number,
            'customer_name' => $order->customer->name ?? null,
            'order_type' => $order->order_type,
            'items_count' => $order->items->count(),
            'group_id' => $groupId,
        ];

        // DOUBLE-ENTRY BOOKKEEPING:
        // 1. Debit COGS Account (Expense increases - cost of goods sold)
        $debitTransaction = static::create([
            'transaction_date' => $transactionDate,
            'amount' => $totalCOGS,
            'type' => 'debit',
            'account_id' => $cogsAccountId,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'description' => "COGS - Order {$order->order_number}",
            'store_id' => $order->store_id,
            'created_by' => $order->created_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        // 2. Credit Inventory Account (Asset decreases - inventory reduced)
        static::create([
            'transaction_date' => $transactionDate,
            'amount' => $totalCOGS,
            'type' => 'credit',
            'account_id' => $inventoryAccountId,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'description' => "COGS - Order {$order->order_number}",
            'store_id' => $order->store_id,
            'created_by' => $order->created_by,
            'metadata' => $metadata,
            'status' => $status,
        ]);

        return $debitTransaction;
    }

    // Helper methods for account IDs
    public static function getCashAccountId($storeId = null): ?int
    {
        // Primary: find by name LIKE 'Cash' within current assets
        $query = Account::query()->where('type', 'asset')
            ->where('sub_type', 'current_asset')
            ->where('is_active', true);
        (new static)->whereLike($query, 'name', 'Cash');
        $account = $query->first();

        // Secondary: find by standard account code
        if (!$account) {
            $account = Account::where('account_code', '1001')
                ->where('is_active', true)
                ->first();
        }

        // Tertiary: any current asset account
        if (!$account) {
            $account = Account::where('type', 'asset')
                ->where('sub_type', 'current_asset')
                ->where('is_active', true)
                ->first();
        }

        if (!$account) {
            throw new \RuntimeException(
                'No cash/current-asset account found in chart of accounts. ' .
                'Ensure account code 1001 (Cash and Cash Equivalents) exists.'
            );
        }

        return $account->id;
    }

    public static function getSalesRevenueAccountId(): ?int
    {
        // Primary: find by sub_type
        $account = Account::where('type', 'income')
            ->where('sub_type', 'sales_revenue')
            ->where('is_active', true)
            ->first();

        // Secondary: find by standard account code
        if (!$account) {
            $account = Account::where('account_code', '4001')
                ->where('is_active', true)
                ->first();
        }

        // Tertiary: any income account with Sales in name
        if (!$account) {
            $query = Account::query()->where('type', 'income')
                ->where('is_active', true);
            (new static)->whereLike($query, 'name', 'Sales');
            $account = $query->first();
        }

        if (!$account) {
            throw new \RuntimeException(
                'No sales revenue account found in chart of accounts. ' .
                'Ensure account code 4001 (Sales Revenue) exists.'
            );
        }

        return $account->id;
    }

    public static function getServiceRevenueAccountId(): ?int
    {
        // Get service revenue account from database
        $query = Account::query()->where('type', 'income')
            ->where('is_active', true);
        (new static)->whereLike($query, 'name', 'Service');
        $account = $query->first();
        
        // If no specific service revenue account, use sales revenue
        if (!$account) {
            return static::getSalesRevenueAccountId();
        }
        
        return $account->id;
    }

    public static function getCOGSAccountId(): ?int
    {
        // Primary: find by sub_type or name LIKE COGS/Cost of Goods Sold
        $account = Account::where('type', 'expense')
            ->where(function ($q) {
                $instance = new static;
                $instance->whereLike($q, 'name', 'COGS');
                $instance->orWhereLike($q, 'name', 'Cost of Goods Sold');
                $q->orWhere('sub_type', 'cogs');
                $q->orWhere('sub_type', 'cost_of_goods_sold');
            })
            ->where('is_active', true)
            ->first();

        // Secondary: find by standard account code
        if (!$account) {
            $account = Account::where('account_code', '5002')
                ->where('is_active', true)
                ->first();
        }

        if (!$account) {
            throw new \RuntimeException(
                'No COGS account found in chart of accounts. ' .
                'Ensure account code 5002 (Cost of Goods Sold) exists.'
            );
        }

        return $account->id;
    }

    public static function getInventoryAccountId(): ?int
    {
        $likeOp = (new static)->getLikeOperator();

        // Primary: find by name or inventory sub_type (never fall through to current_asset generically)
        $account = Account::where('type', 'asset')
            ->where(function ($q) {
                (new static)->whereLike($q, 'name', 'Inventory');
                $q->orWhere('sub_type', 'inventory');
            })
            ->where('is_active', true)
            ->orderByRaw("CASE
                WHEN name {$likeOp} '%Inventory%' THEN 1
                WHEN sub_type = 'inventory' THEN 2
                ELSE 3
            END")
            ->first();

        // Secondary: standard account code
        if (!$account) {
            $account = Account::where('account_code', '1003')
                ->where('is_active', true)
                ->first();
        }

        if (!$account) {
            throw new \RuntimeException(
                'No inventory account found in chart of accounts. ' .
                'Ensure account code 1003 (Inventory) exists. ' .
                'Falling back to Cash would silently corrupt your ledger.'
            );
        }

        return $account->id;
    }

    public static function getTaxLiabilityAccountId(): ?int
    {
        // Get tax liability account from database
        $account = Account::where('type', 'liability')
            ->where(function ($q) {
                $instance = new static;
                $instance->whereLike($q, 'name', 'Tax');
                $instance->orWhereLike($q, 'name', 'VAT');
                $instance->orWhereLike($q, 'name', 'Sales Tax');
            })
            ->where('is_active', true)
            ->first();
        
        // If not found, create a default tax liability account
        if (!$account) {
            $account = Account::create([
                'account_code' => '2002',
                'name' => 'Tax Payable',
                'type' => 'liability',
                'sub_type' => 'current_liability',
                'description' => 'Sales tax collected from customers',
                'is_active' => true,
            ]);
        }
        
        return $account->id;
    }

    private static function getExpenseAccountId($categoryId): ?int
    {
        // Try to find the specific expense category's mapped account code
        if ($categoryId) {
            $category = \App\Models\ExpenseCategory::find($categoryId);
            if ($category) {
                // Map category types to standard account codes
                $accountCode = match ($category->type) {
                    'personnel'      => '5003', // Personnel / Salary expenses
                    'marketing'      => '5004', // Marketing expenses
                    'logistics'      => '5005', // Logistics expenses
                    'utilities'      => '5006', // Utilities expenses
                    'maintenance'    => '5007', // Maintenance expenses
                    'taxes'          => '5008', // Tax expenses
                    'capital'        => '1101', // Capital goes to Fixed Assets
                    default          => '5001', // Default: Operating Expenses
                };
                $account = Account::where('account_code', $accountCode)
                    ->where('is_active', true)
                    ->first();
                if ($account) {
                    return $account->id;
                }
            }
        }

        // Fallback: any active operating expense account by code
        $account = Account::where('account_code', '5001')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            // Last resort: any expense account
            $account = Account::where('type', 'expense')
                ->where('is_active', true)
                ->first();
        }

        if (!$account) {
            throw new \RuntimeException(
                'No expense account found in chart of accounts. ' .
                'Ensure account code 5001 (Operating Expenses) exists.'
            );
        }

        return $account->id;
    }

    // Accessors
    public function getTypeColorAttribute(): string
    {
        return match ($this->type) {
            'debit' => 'success',
            'credit' => 'danger',
            default => 'secondary',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'debit' => 'Debit',
            'credit' => 'Credit',
            default => 'Unknown',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'completed' => 'success',
            'pending' => 'warning',
            'failed' => 'danger',
            'cancelled' => 'secondary',
            default => 'secondary',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'completed' => 'Completed',
            'pending' => 'Pending',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            default => 'Unknown',
        };
    }

    public function getReferenceModelAttribute()
    {
        return $this->reference_type::find($this->reference_id);
    }

    // Static methods
    public static function generateTransactionNumber(): string
    {
        do {
            $transactionNumber = 'TXN-' . date('Ymd') . '-' . strtoupper(Str::random(8));
        } while (static::where('transaction_number', $transactionNumber)->exists());

        return $transactionNumber;
    }

    public static function getAccountBalance(int $accountId, $storeId = null, $endDate = null): float
    {
        $query = static::byAccount($accountId)->completed();

        if ($storeId) {
            $query->byStore($storeId);
        }

        if ($endDate) {
            $query->where('transaction_date', '<=', $endDate);
        }

        $debits = (clone $query)->debit()->sum('amount');
        $credits = (clone $query)->credit()->sum('amount');

        return $debits - $credits;
    }

    public static function getStoreBalance($storeId, $endDate = null): float
    {
        $query = static::byStore($storeId)->completed();

        if ($endDate) {
            $query->where('transaction_date', '<=', $endDate);
        }

        $debits = (clone $query)->debit()->sum('amount');
        $credits = (clone $query)->credit()->sum('amount');

        return $debits - $credits;
    }

    public static function getTrialBalance($storeId = null, $startDate = null, $endDate = null): array
    {
        $query = static::completed();

        if ($storeId) {
            $query->byStore($storeId);
        }

        if ($startDate && $endDate) {
            $query->byDateRange($startDate, $endDate);
        }

        $debits = (clone $query)->debit()->sum('amount');
        $credits = (clone $query)->credit()->sum('amount');

        return [
            'total_debits' => $debits,
            'total_credits' => $credits,
            'balance' => $debits - $credits,
            'in_balance' => abs($debits - $credits) < 0.01, // Allow for small floating point differences
        ];
    }
}
