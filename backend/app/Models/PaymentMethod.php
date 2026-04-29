<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\AutoLogsActivity;

class PaymentMethod extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'allowed_customer_types',
        'is_active',
        'requires_reference',
        'supports_partial',
        'min_amount',
        'max_amount',
        'processor',
        'processor_config',
        'icon',
        'fixed_fee',
        'percentage_fee',
        'sort_order',
    ];

    protected $casts = [
        'allowed_customer_types' => 'array',
        'is_active' => 'boolean',
        'requires_reference' => 'boolean',
        'supports_partial' => 'boolean',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'processor_config' => 'array',
        'fixed_fee' => 'decimal:2',
        'percentage_fee' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeForCustomerType($query, string $customerType)
    {
        return $query->whereJsonContains('allowed_customer_types', $customerType);
    }

    public function scopeSupportsPartial($query)
    {
        return $query->where('supports_partial', true);
    }

    public function scopeRequiresReference($query)
    {
        return $query->where('requires_reference', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Business logic methods
    public function isAllowedForCustomerType(string $customerType): bool
    {
        return in_array($customerType, $this->allowed_customer_types ?? []);
    }

    public function canProcessAmount(float $amount): bool
    {
        if ($this->min_amount && $amount < $this->min_amount) {
            return false;
        }

        if ($this->max_amount && $amount > $this->max_amount) {
            return false;
        }

        return true;
    }

    public function calculateFee(float $amount): float
    {
        $fee = $this->fixed_fee;

        if ($this->percentage_fee > 0) {
            $fee += ($amount * $this->percentage_fee / 100);
        }

        return round($fee, 2);
    }

    public function getNetAmount(float $amount): float
    {
        return $amount - $this->calculateFee($amount);
    }

    public function isCash(): bool
    {
        return $this->type === 'cash';
    }

    public function isCard(): bool
    {
        return $this->type === 'card';
    }

    public function isBankTransfer(): bool
    {
        return $this->type === 'bank_transfer';
    }

    public function isOnlineBanking(): bool
    {
        return $this->type === 'online_banking';
    }

    public function isMobileBanking(): bool
    {
        return $this->type === 'mobile_banking';
    }

    public function isDigitalWallet(): bool
    {
        return $this->type === 'digital_wallet';
    }

    // Static methods for predefined payment methods
    public static function createCashMethod(): self
    {
        return static::create([
            'code' => 'cash',
            'name' => 'Cash',
            'description' => 'Cash payment',
            'type' => 'cash',
            'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
            'supports_partial' => true,
            'sort_order' => 1,
        ]);
    }

    public static function createCardMethod(): self
    {
        return static::create([
            'code' => 'card',
            'name' => 'Card Payment',
            'description' => 'Credit/Debit card payment',
            'type' => 'card',
            'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
            'requires_reference' => true,
            'supports_partial' => true,
            'fixed_fee' => 0,
            'percentage_fee' => 1.5, // 1.5% fee
            'sort_order' => 2,
        ]);
    }

    public static function createBankTransferMethod(): self
    {
        return static::create([
            'code' => 'bank_transfer',
            'name' => 'Bank Transfer',
            'description' => 'Direct bank transfer',
            'type' => 'bank_transfer',
            'allowed_customer_types' => ['ecommerce'],
            'requires_reference' => true,
            'supports_partial' => true,
            'sort_order' => 3,
        ]);
    }

    public static function createOnlineBankingMethod(): self
    {
        return static::create([
            'code' => 'online_banking',
            'name' => 'Online Banking',
            'description' => 'Online banking payment',
            'type' => 'online_banking',
            'allowed_customer_types' => ['social_commerce'],
            'requires_reference' => true,
            'supports_partial' => true,
            'fixed_fee' => 5.00,
            'sort_order' => 4,
        ]);
    }

    public static function createMobileBankingMethod(): self
    {
        return static::create([
            'code' => 'mobile_banking',
            'name' => 'Mobile Banking',
            'description' => 'Mobile banking payment (bKash, Nagad, etc.)',
            'type' => 'mobile_banking',
            'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
            'requires_reference' => true,
            'supports_partial' => true,
            'fixed_fee' => 2.00,
            'percentage_fee' => 1.0,
            'sort_order' => 5,
        ]);
    }

    public static function createDigitalWalletMethod(): self
    {
        return static::create([
            'code' => 'digital_wallet',
            'name' => 'Digital Wallet',
            'description' => 'Digital wallet payment',
            'type' => 'digital_wallet',
            'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
            'requires_reference' => true,
            'supports_partial' => true,
            'fixed_fee' => 1.00,
            'sort_order' => 6,
        ]);
    }

    // Helper methods
    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'cash' => 'Cash',
            'card' => 'Card',
            'bank_transfer' => 'Bank Transfer',
            'online_banking' => 'Online Banking',
            'mobile_banking' => 'Mobile Banking',
            'digital_wallet' => 'Digital Wallet',
            'other' => 'Other',
            default => 'Unknown',
        };
    }

    public function getAllowedCustomerTypesLabelAttribute(): string
    {
        if (!$this->allowed_customer_types) {
            return 'None';
        }

        $labels = [];
        foreach ($this->allowed_customer_types as $type) {
            $labels[] = match($type) {
                'counter' => 'Counter',
                'social_commerce' => 'Social Commerce',
                'ecommerce' => 'E-commerce',
                default => ucfirst($type),
            };
        }

        return implode(', ', $labels);
    }

    public static function getAvailableMethodsForCustomerType(string $customerType): array
    {
        return static::active()
            ->forCustomerType($customerType)
            ->ordered()
            ->get()
            ->toArray();
    }

    public static function seedDefaultMethods(): void
    {
        if (static::count() > 0) {
            return; // Already seeded
        }

        static::createCashMethod();
        static::createCardMethod();
        static::createBankTransferMethod();
        static::createOnlineBankingMethod();
        static::createMobileBankingMethod();
        static::createDigitalWalletMethod();
    }
}