<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashDenomination extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_split_id',
        'order_payment_id',
        'store_id',
        'recorded_by',
        'type',
        'currency',
        'denomination_value',
        'quantity',
        'total_amount',
        'cash_type',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'denomination_value' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($denomination) {
            // Auto-calculate total amount if not provided
            if (!isset($denomination->total_amount) && isset($denomination->denomination_value) && isset($denomination->quantity)) {
                $denomination->total_amount = $denomination->denomination_value * $denomination->quantity;
            }
        });
    }

    // Relationships
    public function paymentSplit(): BelongsTo
    {
        return $this->belongsTo(PaymentSplit::class);
    }

    public function orderPayment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by');
    }

    // Scopes
    public function scopeReceived($query)
    {
        return $query->where('type', 'received');
    }

    public function scopeChange($query)
    {
        return $query->where('type', 'change');
    }

    public function scopeNotes($query)
    {
        return $query->where('cash_type', 'note');
    }

    public function scopeCoins($query)
    {
        return $query->where('cash_type', 'coin');
    }

    public function scopeByCurrency($query, $currency)
    {
        return $query->where('currency', $currency);
    }

    public function scopeByDenomination($query, $value)
    {
        return $query->where('denomination_value', $value);
    }

    public function scopeForPaymentSplit($query, $splitId)
    {
        return $query->where('payment_split_id', $splitId);
    }

    public function scopeForOrderPayment($query, $paymentId)
    {
        return $query->where('order_payment_id', $paymentId);
    }

    // Helper methods
    public function isReceived(): bool
    {
        return $this->type === 'received';
    }

    public function isChange(): bool
    {
        return $this->type === 'change';
    }

    public function isNote(): bool
    {
        return $this->cash_type === 'note';
    }

    public function isCoin(): bool
    {
        return $this->cash_type === 'coin';
    }

    // Accessors
    public function getFormattedDenominationAttribute(): string
    {
        return "{$this->currency} {$this->denomination_value}";
    }

    public function getDescriptionAttribute(): string
    {
        $type = $this->isNote() ? 'note' : 'coin';
        $action = $this->isReceived() ? 'received' : 'given as change';
        
        return "{$this->quantity} x {$this->formatted_denomination} {$type}(s) {$action}";
    }

    // Static methods
    public static function recordReceived(
        $parentId,
        string $parentType,
        int $storeId,
        float $denominationValue,
        int $quantity,
        string $currency = 'USD',
        string $cashType = 'note',
        ?int $recordedBy = null
    ): self {
        $data = [
            'store_id' => $storeId,
            'type' => 'received',
            'currency' => $currency,
            'denomination_value' => $denominationValue,
            'quantity' => $quantity,
            'cash_type' => $cashType,
            'recorded_by' => $recordedBy ?? auth()->id(),
        ];

        if ($parentType === 'payment_split') {
            $data['payment_split_id'] = $parentId;
        } else {
            $data['order_payment_id'] = $parentId;
        }

        return static::create($data);
    }

    public static function recordChange(
        $parentId,
        string $parentType,
        int $storeId,
        float $denominationValue,
        int $quantity,
        string $currency = 'USD',
        string $cashType = 'note',
        ?int $recordedBy = null
    ): self {
        $data = [
            'store_id' => $storeId,
            'type' => 'change',
            'currency' => $currency,
            'denomination_value' => $denominationValue,
            'quantity' => $quantity,
            'cash_type' => $cashType,
            'recorded_by' => $recordedBy ?? auth()->id(),
        ];

        if ($parentType === 'payment_split') {
            $data['payment_split_id'] = $parentId;
        } else {
            $data['order_payment_id'] = $parentId;
        }

        return static::create($data);
    }

    /**
     * Get total amount received for a payment split or order payment
     */
    public static function getTotalReceived($parentId, string $parentType): float
    {
        $query = static::received();
        
        if ($parentType === 'payment_split') {
            $query->where('payment_split_id', $parentId);
        } else {
            $query->where('order_payment_id', $parentId);
        }

        return $query->sum('total_amount');
    }

    /**
     * Get total change given for a payment split or order payment
     */
    public static function getTotalChange($parentId, string $parentType): float
    {
        $query = static::change();
        
        if ($parentType === 'payment_split') {
            $query->where('payment_split_id', $parentId);
        } else {
            $query->where('order_payment_id', $parentId);
        }

        return $query->sum('total_amount');
    }

    /**
     * Get breakdown of denominations received
     */
    public static function getReceivedBreakdown($parentId, string $parentType): array
    {
        $query = static::received();
        
        if ($parentType === 'payment_split') {
            $query->where('payment_split_id', $parentId);
        } else {
            $query->where('order_payment_id', $parentId);
        }

        return $query->orderBy('denomination_value', 'desc')
            ->get()
            ->map(function ($denomination) {
                return [
                    'denomination' => $denomination->denomination_value,
                    'quantity' => $denomination->quantity,
                    'total' => $denomination->total_amount,
                    'type' => $denomination->cash_type,
                    'currency' => $denomination->currency,
                ];
            })
            ->toArray();
    }

    /**
     * Get breakdown of change given
     */
    public static function getChangeBreakdown($parentId, string $parentType): array
    {
        $query = static::change();
        
        if ($parentType === 'payment_split') {
            $query->where('payment_split_id', $parentId);
        } else {
            $query->where('order_payment_id', $parentId);
        }

        return $query->orderBy('denomination_value', 'desc')
            ->get()
            ->map(function ($denomination) {
                return [
                    'denomination' => $denomination->denomination_value,
                    'quantity' => $denomination->quantity,
                    'total' => $denomination->total_amount,
                    'type' => $denomination->cash_type,
                    'currency' => $denomination->currency,
                ];
            })
            ->toArray();
    }

    /**
     * Calculate optimal change for a given amount
     * Returns an array of denominations to give as change
     */
    public static function calculateOptimalChange(float $amount, string $currency = 'USD'): array
    {
        // Common denominations by currency
        $denominations = match($currency) {
            'USD' => [100, 50, 20, 10, 5, 1, 0.25, 0.10, 0.05, 0.01],
            'BDT' => [1000, 500, 100, 50, 20, 10, 5, 2, 1],
            default => [100, 50, 20, 10, 5, 1],
        };

        $change = [];
        $remaining = $amount;

        foreach ($denominations as $denomination) {
            if ($remaining >= $denomination) {
                $count = floor($remaining / $denomination);
                if ($count > 0) {
                    $change[] = [
                        'denomination' => $denomination,
                        'quantity' => (int)$count,
                        'total' => $denomination * $count,
                        'type' => $denomination >= 1 ? 'note' : 'coin',
                    ];
                    $remaining = round($remaining - ($denomination * $count), 2);
                }
            }
        }

        return $change;
    }
}
