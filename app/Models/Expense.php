<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Traits\AutoLogsActivity;

class Expense extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'expense_number',
        'category_id',
        'vendor_id',
        'employee_id',
        'store_id',
        'created_by',
        'approved_by',
        'processed_by',
        'amount',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'paid_amount',
        'outstanding_amount',
        'status',
        'payment_status',
        'expense_date',
        'due_date',
        'approved_at',
        'processed_at',
        'completed_at',
        'reference_number',
        'vendor_invoice_number',
        'description',
        'notes',
        'expense_type',
        'is_recurring',
        'recurrence_type',
        'recurrence_interval',
        'recurrence_end_date',
        'parent_expense_id',
        'attachments',
        'metadata',
        'approval_notes',
        'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'float',
        'tax_amount' => 'float',
        'discount_amount' => 'float',
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'outstanding_amount' => 'float',
        'expense_date' => 'date',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_recurring' => 'boolean',
        'recurrence_interval' => 'integer',
        'recurrence_end_date' => 'date',
        'attachments' => 'array',
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($expense) {
            if (empty($expense->expense_number)) {
                $expense->expense_number = static::generateExpenseNumber();
            }

            // Calculate total amount
            if (empty($expense->total_amount)) {
                $expense->total_amount = $expense->amount + $expense->tax_amount - $expense->discount_amount;
            }

            // Calculate outstanding amount
            if (empty($expense->outstanding_amount)) {
                $expense->outstanding_amount = $expense->total_amount - $expense->paid_amount;
            }
        });

        static::updating(function ($expense) {
            if ($expense->isDirty(['amount', 'tax_amount', 'discount_amount'])) {
                $expense->total_amount = $expense->amount + $expense->tax_amount - $expense->discount_amount;
                $expense->outstanding_amount = $expense->total_amount - $expense->paid_amount;
            }
        });
    }

    // Relationships
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    public function completedPayments()
    {
        return $this->payments()->completed();
    }

    public function parentExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'parent_expense_id');
    }

    public function recurringExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'parent_expense_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class);
    }

    public function primaryReceipt()
    {
        return $this->hasOne(ExpenseReceipt::class)->where('is_primary', true);
    }

    // Scopes
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', 'pending_approval');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
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

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeByEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('expense_type', $type);
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring', true);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->where('payment_status', '!=', 'paid')
            ->whereNotIn('status', ['cancelled', 'rejected']);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('expense_date', now()->month)
            ->whereYear('expense_date', now()->year);
    }

    public function scopeThisYear($query)
    {
        return $query->whereYear('expense_date', now()->year);
    }

    // Business logic methods
    public function submitForApproval(): bool
    {
        if ($this->status !== 'draft') {
            return false;
        }

        $this->status = 'pending_approval';
        $this->save();

        return true;
    }

    public function approve(Employee $approvedBy, string $notes = null): bool
    {
        if ($this->status !== 'pending_approval') {
            return false;
        }

        $this->status = 'approved';
        $this->approved_by = $approvedBy->id;
        $this->approved_at = now();
        $this->approval_notes = $notes;
        $this->save();

        return true;
    }

    public function reject(Employee $rejectedBy, string $reason): bool
    {
        if ($this->status !== 'pending_approval') {
            return false;
        }

        $this->status = 'rejected';
        $this->approved_by = $rejectedBy->id;
        $this->rejection_reason = $reason;
        $this->save();

        return true;
    }

    public function process(Employee $processedBy): bool
    {
        if ($this->status !== 'approved') {
            return false;
        }

        $this->status = 'processing';
        $this->processed_by = $processedBy->id;
        $this->processed_at = now();
        $this->save();

        return true;
    }

    public function complete(): bool
    {
        if ($this->status !== 'processing') {
            return false;
        }

        $this->status = 'completed';
        $this->completed_at = now();
        $this->save();

        return true;
    }

    public function cancel(string $reason = null): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        $this->status = 'cancelled';
        $this->notes = $this->notes ? $this->notes . "\n\nCancellation reason: " . $reason : "Cancellation reason: " . $reason;
        $this->save();

        return true;
    }

    public function updatePaymentStatus(): void
    {
        $totalPaid = $this->payments()->completed()->sum('amount');

        $this->paid_amount = $totalPaid;
        $this->outstanding_amount = $this->total_amount - $totalPaid;

        if ($totalPaid >= $this->total_amount) {
            $this->payment_status = 'paid';
        } elseif ($totalPaid > 0) {
            $this->payment_status = 'partially_paid';
        } else {
            $this->payment_status = 'unpaid';
        }

        $this->save();
    }

    public function addPayment(PaymentMethod $paymentMethod, float $amount, array $paymentData = [], Employee $processedBy = null): ExpensePayment
    {
        return ExpensePayment::createPayment($this, $paymentMethod, $amount, $paymentData, $processedBy);
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'rejected']);
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function canBeProcessed(): bool
    {
        return $this->status === 'approved';
    }

    public function isOverdue(): bool
    {
        return $this->due_date && now()->gt($this->due_date) && $this->payment_status !== 'paid';
    }

    public function requiresApproval(): bool
    {
        return $this->category && $this->category->requiresApprovalForAmount($this->total_amount);
    }

    public function generateRecurringExpenses(): void
    {
        if (!$this->is_recurring || !$this->recurrence_end_date) {
            return;
        }

        $currentDate = \Carbon\Carbon::createFromFormat('Y-m-d', $this->expense_date);

        while ($currentDate->lte($this->recurrence_end_date)) {
            $currentDate = $this->getNextRecurrenceDate($currentDate);

            if ($currentDate->gt($this->recurrence_end_date)) {
                break;
            }

            // Create recurring expense
            static::create([
                'category_id' => $this->category_id,
                'vendor_id' => $this->vendor_id,
                'employee_id' => $this->employee_id,
                'store_id' => $this->store_id,
                'created_by' => $this->created_by,
                'amount' => $this->amount,
                'tax_amount' => $this->tax_amount,
                'discount_amount' => $this->discount_amount,
                'description' => $this->description,
                'expense_type' => $this->expense_type,
                'expense_date' => $currentDate,
                'due_date' => $currentDate->copy()->addDays(30), // Default 30 days payment term
                'parent_expense_id' => $this->id,
                'metadata' => $this->metadata,
            ]);
        }
    }

    private function getNextRecurrenceDate($currentDate)
    {
        return match($this->recurrence_type) {
            'daily' => $currentDate->addDays($this->recurrence_interval),
            'weekly' => $currentDate->addWeeks($this->recurrence_interval),
            'monthly' => $currentDate->addMonths($this->recurrence_interval),
            'quarterly' => $currentDate->addMonths($this->recurrence_interval * 3),
            'yearly' => $currentDate->addYears($this->recurrence_interval),
            default => $currentDate->addMonths(1),
        };
    }

    // Accessors
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'secondary',
            'pending_approval' => 'warning',
            'approved' => 'info',
            'rejected' => 'danger',
            'cancelled' => 'secondary',
            'processing' => 'primary',
            'completed' => 'success',
            default => 'secondary',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft' => 'Draft',
            'pending_approval' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            'processing' => 'Processing',
            'completed' => 'Completed',
            default => 'Unknown',
        };
    }

    public function getPaymentStatusColorAttribute(): string
    {
        return match ($this->payment_status) {
            'unpaid' => 'danger',
            'partially_paid' => 'warning',
            'paid' => 'success',
            'overpaid' => 'info',
            'refunded' => 'secondary',
            default => 'secondary',
        };
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return match ($this->payment_status) {
            'unpaid' => 'Unpaid',
            'partially_paid' => 'Partially Paid',
            'paid' => 'Paid',
            'overpaid' => 'Overpaid',
            'refunded' => 'Refunded',
            default => 'Unknown',
        };
    }

    public function getExpenseTypeLabelAttribute(): string
    {
        return match ($this->expense_type) {
            'vendor_payment' => 'Vendor Payment',
            'salary_payment' => 'Salary Payment',
            'utility_bill' => 'Utility Bill',
            'rent_lease' => 'Rent/Lease',
            'logistics' => 'Logistics',
            'maintenance' => 'Maintenance',
            'marketing' => 'Marketing',
            'insurance' => 'Insurance',
            'taxes' => 'Taxes',
            'supplies' => 'Supplies',
            'travel' => 'Travel',
            'training' => 'Training',
            'software' => 'Software',
            'bank_charges' => 'Bank Charges',
            'depreciation' => 'Depreciation',
            'miscellaneous' => 'Miscellaneous',
            default => 'Unknown',
        };
    }

    public function getVendorNameAttribute(): string
    {
        return $this->vendor ? $this->vendor->name : 'N/A';
    }

    public function getEmployeeNameAttribute(): string
    {
        return $this->employee ? $this->employee->name : 'N/A';
    }

    // Static methods
    public static function generateExpenseNumber(): string
    {
        do {
            $expenseNumber = 'EXP-' . date('Ymd') . '-' . strtoupper(Str::random(6));
        } while (static::where('expense_number', $expenseNumber)->exists());

        return $expenseNumber;
    }

    public static function createVendorPayment(Vendor $vendor, ExpenseCategory $category, float $amount, array $options = []): self
    {
        return static::create(array_merge([
            'category_id' => $category->id,
            'vendor_id' => $vendor->id,
            'store_id' => $options['store_id'] ?? 1,
            'created_by' => $options['created_by'] ?? 1,
            'amount' => $amount,
            'description' => $options['description'] ?? "Payment to vendor: {$vendor->name}",
            'expense_type' => 'vendor_payment',
            'expense_date' => $options['expense_date'] ?? now(),
            'due_date' => $options['due_date'] ?? null,
            'reference_number' => $options['reference_number'] ?? null,
            'vendor_invoice_number' => $options['vendor_invoice_number'] ?? null,
            'notes' => $options['notes'] ?? null,
            'attachments' => $options['attachments'] ?? [],
            'metadata' => $options['metadata'] ?? [],
        ], $options));
    }

    public static function createSalaryPayment(Employee $employee, float $amount, array $options = []): self
    {
        $salaryCategory = ExpenseCategory::where('code', 'SAL')->first();

        return static::create(array_merge([
            'category_id' => $salaryCategory?->id ?? 1,
            'employee_id' => $employee->id,
            'store_id' => $employee->store_id,
            'created_by' => $options['created_by'] ?? 1,
            'amount' => $amount,
            'description' => $options['description'] ?? "Salary payment to: {$employee->name}",
            'expense_type' => 'salary_payment',
            'expense_date' => $options['expense_date'] ?? now(),
            'due_date' => $options['due_date'] ?? now()->endOfMonth(),
            'reference_number' => $options['reference_number'] ?? null,
            'notes' => $options['notes'] ?? null,
            'metadata' => $options['metadata'] ?? [],
        ], $options));
    }

    public static function getExpenseStats($storeId = null, $startDate = null, $endDate = null): array
    {
        $query = static::query();

        if ($storeId) {
            $query->byStore($storeId);
        }

        if ($startDate) {
            $query->where('expense_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('expense_date', '<=', $endDate);
        }

        $approvedQuery = (clone $query)->whereNotIn('status', ['draft', 'rejected', 'cancelled']);

        return [
            'total_expenses' => $query->count(),
            'approved_expenses' => (clone $approvedQuery)->count(),
            'pending_approval' => (clone $query)->pendingApproval()->count(),
            'total_amount' => $approvedQuery->sum('total_amount'),
            'paid_amount' => $approvedQuery->sum('paid_amount'),
            'outstanding_amount' => $approvedQuery->sum('outstanding_amount'),
            'overdue_count' => (clone $approvedQuery)->overdue()->count(),
            'this_month_total' => (clone $approvedQuery)->thisMonth()->sum('total_amount'),
            'this_year_total' => (clone $approvedQuery)->thisYear()->sum('total_amount'),
        ];
    }
}
