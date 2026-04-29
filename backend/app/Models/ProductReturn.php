<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AutoLogsActivity;

class ProductReturn extends Model
{
    use HasFactory, SoftDeletes, AutoLogsActivity;

    protected $table = 'product_returns';

    protected $fillable = [
        'return_number',
        'order_id',
        'customer_id',
        'store_id',
        'received_at_store_id',
        'return_reason',
        'return_type',
        'status',
        'return_date',
        'received_date',
        'processed_date',
        'approved_date',
        'rejected_date',
        'total_return_value',
        'total_refund_amount',
        'processing_fee',
        'customer_notes',
        'internal_notes',
        'rejection_reason',
        'return_items',
        'attachments',
        'quality_check_passed',
        'quality_check_notes',
        'processed_by',
        'approved_by',
        'rejected_by',
        'status_history',
    ];

    protected $casts = [
        'return_date' => 'datetime',
        'received_date' => 'datetime',
        'processed_date' => 'datetime',
        'approved_date' => 'datetime',
        'rejected_date' => 'datetime',
        'total_return_value' => 'decimal:2',
        'total_refund_amount' => 'decimal:2',
        'processing_fee' => 'decimal:2',
        'return_items' => 'json',
        'attachments' => 'json',
        'quality_check_passed' => 'boolean',
        'status_history' => 'json',
    ];

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function receivedAtStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'received_at_store_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'return_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['completed', 'refunded']);
    }

    // Business logic methods
    public function approve(Employee $approvedBy): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update([
            'status' => 'approved',
            'approved_by' => $approvedBy->id,
            'approved_date' => now(),
            'status_history' => $this->addStatusToHistory('approved', $approvedBy->id),
        ]);

        return true;
    }

    public function reject(Employee $rejectedBy, string $reason): bool
    {
        if (!in_array($this->status, ['pending', 'approved'])) {
            return false;
        }

        $this->update([
            'status' => 'rejected',
            'rejected_by' => $rejectedBy->id,
            'rejected_date' => now(),
            'rejection_reason' => $reason,
            'status_history' => $this->addStatusToHistory('rejected', $rejectedBy->id, $reason),
        ]);

        return true;
    }

    public function process(Employee $processedBy): bool
    {
        if ($this->status !== 'approved') {
            return false;
        }

        $this->update([
            'status' => 'processing',
            'processed_by' => $processedBy->id,
            'processed_date' => now(),
            'status_history' => $this->addStatusToHistory('processing', $processedBy->id),
        ]);

        return true;
    }

    public function complete(): bool
    {
        if ($this->status !== 'processing') {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'status_history' => $this->addStatusToHistory('completed'),
        ]);

        return true;
    }

    public function markAsRefunded(): bool
    {
        if ($this->status !== 'completed') {
            return false;
        }

        $this->update([
            'status' => 'refunded',
            'status_history' => $this->addStatusToHistory('refunded'),
        ]);

        return true;
    }

    public function calculateTotalValue(): float
    {
        $total = 0;
        if ($this->return_items) {
            foreach ($this->return_items as $item) {
                $total += ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
            }
        }
        return $total;
    }

    public function calculateRefundableAmount(): float
    {
        $totalValue = $this->calculateTotalValue();
        return $totalValue - $this->processing_fee;
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'pending' && $this->quality_check_passed;
    }

    public function canBeProcessed(): bool
    {
        return $this->status === 'approved';
    }

    public function canBeRefunded(): bool
    {
        return $this->status === 'completed' && $this->total_refund_amount > 0;
    }

    public function hasRefunds(): bool
    {
        return $this->refunds()->exists();
    }

    public function getTotalRefundedAmount(): float
    {
        return $this->refunds()->where('status', 'completed')->sum('refund_amount');
    }

    public function isFullyRefunded(): bool
    {
        return abs($this->getTotalRefundedAmount() - $this->total_refund_amount) < 0.01;
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
    public function getReturnItemsCountAttribute(): int
    {
        return count($this->return_items ?? []);
    }

    public function getTotalItemsQuantityAttribute(): int
    {
        $total = 0;
        if ($this->return_items) {
            foreach ($this->return_items as $item) {
                $total += $item['quantity'] ?? 0;
            }
        }
        return $total;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'warning',
            'approved' => 'info',
            'rejected' => 'danger',
            'processed' => 'primary',
            'completed' => 'success',
            'refunded' => 'success',
            default => 'secondary',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pending Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'processed' => 'Processed',
            'completed' => 'Completed',
            'refunded' => 'Refunded',
            default => 'Unknown',
        };
    }
    
    /**
     * Mutator for return_items - automatically recalculate totals when items are updated
     */
    public function setReturnItemsAttribute($value): void
    {
        $this->attributes['return_items'] = json_encode($value);
        
        // Recalculate and update total values
        if ($value) {
            $totalValue = 0.0;
            $totalRefundableAmount = 0.0;
            
            foreach ($value as $item) {
                $totalValue += (float) ($item['total_price'] ?? 0);
                $totalRefundableAmount += (float) ($item['refundable_amount'] ?? $item['total_price'] ?? 0);
            }
            
            $this->attributes['total_return_value'] = $totalValue;
            $this->attributes['total_refund_amount'] = $totalRefundableAmount;
        }
    }
    
    /**
     * Override save method to recalculate totals if return_items was modified
     */
    public function save(array $options = []): bool
    {
        // If return_items was changed, ensure totals are recalculated
        if ($this->isDirty('return_items') && $this->return_items) {
            $totalValue = 0.0;
            $totalRefundableAmount = 0.0;
            
            foreach ($this->return_items as $item) {
                $totalValue += (float) ($item['total_price'] ?? 0);
                $totalRefundableAmount += (float) ($item['refundable_amount'] ?? $item['total_price'] ?? 0);
            }
            
            $this->attributes['total_return_value'] = $totalValue;
            $this->attributes['total_refund_amount'] = $totalRefundableAmount;
        }
        
        return parent::save($options);
    }
}