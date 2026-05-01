<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\AutoLogsActivity;

class Account extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'account_code',
        'name',
        'description',
        'type',
        'sub_type',
        'parent_id',
        'is_active',
        'level',
        'path',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'level' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($account) {
            if ($account->parent_id) {
                $parent = static::find($account->parent_id);
                if ($parent) {
                    $account->level = $parent->level + 1;
                    // Path will be set in created event since we need the ID
                }
            } else {
                $account->level = 1;
                // Path will be set in created event since we need the ID
            }
        });

        static::created(function ($account) {
            // Set path after the account has an ID
            if ($account->parent_id) {
                $parent = static::find($account->parent_id);
                if ($parent) {
                    $account->path = $parent->path ? $parent->path . '/' . $account->id : (string)$account->id;
                } else {
                    $account->path = (string)$account->id;
                }
            } else {
                $account->path = (string)$account->id;
            }
            $account->saveQuietly(); // Use saveQuietly to avoid triggering events again
        });

        static::updating(function ($account) {
            if ($account->isDirty('parent_id')) {
                // Recalculate level and path when parent changes
                if ($account->parent_id) {
                    $parent = static::find($account->parent_id);
                    if ($parent) {
                        $account->level = $parent->level + 1;
                        $account->path = $parent->path ? $parent->path . '/' . $account->id : $account->id;
                    }
                } else {
                    $account->level = 1;
                    $account->path = $account->id;
                }

                // Update children paths
                $account->updateChildrenPaths();
            }
        });
    }

    // Relationships
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function allChildren(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeBySubType($query, $subType)
    {
        return $query->where('sub_type', $subType);
    }

    public function scopeAssets($query)
    {
        return $query->where('type', 'asset');
    }

    public function scopeLiabilities($query)
    {
        return $query->where('type', 'liability');
    }

    public function scopeEquity($query)
    {
        return $query->where('type', 'equity');
    }

    public function scopeIncome($query)
    {
        return $query->where('type', 'income');
    }

    public function scopeExpenses($query)
    {
        return $query->where('type', 'expense');
    }

    public function scopeCurrentAssets($query)
    {
        return $query->where('sub_type', 'current_asset');
    }

    public function scopeFixedAssets($query)
    {
        return $query->where('sub_type', 'fixed_asset');
    }

    public function scopeCurrentLiabilities($query)
    {
        return $query->where('sub_type', 'current_liability');
    }

    public function scopeLongTermLiabilities($query)
    {
        return $query->where('sub_type', 'long_term_liability');
    }

    public function scopeSalesRevenue($query)
    {
        return $query->where('sub_type', 'sales_revenue');
    }

    public function scopeOperatingExpenses($query)
    {
        return $query->where('sub_type', 'operating_expenses');
    }

    public function scopeByLevel($query, $level)
    {
        return $query->where('level', $level);
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    // Business logic methods
    public function getBalance($storeId = null, $endDate = null): float
    {
        $query = $this->transactions()->completed();

        if ($storeId) {
            $query->byStore($storeId);
        }

        if ($endDate) {
            $query->where('transaction_date', '<=', $endDate);
        }

        $debits = (clone $query)->debit()->sum('amount');
        $credits = (clone $query)->credit()->sum('amount');

        // For different account types, balance calculation differs
        return match ($this->type) {
            'asset', 'expense' => $debits - $credits,
            'liability', 'equity', 'income' => $credits - $debits,
            default => $debits - $credits,
        };
    }

    public function getChildrenBalance($storeId = null, $endDate = null): float
    {
        $balance = $this->getBalance($storeId, $endDate);

        foreach ($this->children as $child) {
            $balance += $child->getChildrenBalance($storeId, $endDate);
        }

        return $balance;
    }

    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    public function isLeaf(): bool
    {
        return !$this->hasChildren();
    }

    public function getFullName(): string
    {
        if (!$this->parent) {
            return $this->name;
        }

        return $this->parent->getFullName() . ' > ' . $this->name;
    }

    public function updateChildrenPaths(): void
    {
        foreach ($this->children as $child) {
            $child->path = $this->path ? $this->path . '/' . $child->id : $child->id;
            $child->level = $this->level + 1;
            $child->save();

            $child->updateChildrenPaths();
        }
    }

    public function deactivate(): bool
    {
        if ($this->hasChildren()) {
            return false; // Cannot deactivate parent accounts
        }

        if ($this->transactions()->exists()) {
            return false; // Cannot deactivate accounts with transactions
        }

        $this->is_active = false;
        return $this->save();
    }

    // Accessors
    public function getTypeColorAttribute(): string
    {
        return match ($this->type) {
            'asset' => 'success',
            'liability' => 'warning',
            'equity' => 'info',
            'income' => 'primary',
            'expense' => 'danger',
            default => 'secondary',
        };
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            'asset' => 'Asset',
            'liability' => 'Liability',
            'equity' => 'Equity',
            'income' => 'Income',
            'expense' => 'Expense',
            default => 'Unknown',
        };
    }

    public function getSubTypeLabelAttribute(): string
    {
        return match ($this->sub_type) {
            'current_asset' => 'Current Asset',
            'fixed_asset' => 'Fixed Asset',
            'other_asset' => 'Other Asset',
            'current_liability' => 'Current Liability',
            'long_term_liability' => 'Long Term Liability',
            'owner_equity' => 'Owner Equity',
            'retained_earnings' => 'Retained Earnings',
            'sales_revenue' => 'Sales Revenue',
            'other_income' => 'Other Income',
            'cost_of_goods_sold' => 'Cost of Goods Sold',
            'operating_expenses' => 'Operating Expenses',
            'other_expenses' => 'Other Expenses',
            default => 'Unknown',
        };
    }

    public function getFormattedBalanceAttribute(): string
    {
        $balance = $this->getBalance();
        $symbol = $balance < 0 ? '-' : '';
        return $symbol . number_format(abs($balance), 2);
    }

    // Static methods
    public static function getCashAccount(): ?self
    {
        return static::where('account_code', '1001')->first(); // Default cash account
    }

    public static function getAccountsReceivableAccount(): ?self
    {
        return static::where('account_code', '1002')->first(); // Default AR account
    }

    public static function getAccountsPayableAccount(): ?self
    {
        return static::where('account_code', '2001')->first(); // Default AP account
    }

    public static function getSalesRevenueAccount(): ?self
    {
        return static::where('account_code', '4001')->first(); // Default sales account
    }

    public static function getExpenseAccount(string $expenseType = null): ?self
    {
        // Return appropriate expense account based on type
        return match ($expenseType) {
            'operating' => static::where('account_code', '5001')->first(),
            'cost_of_goods_sold' => static::where('account_code', '5002')->first(),
            default => static::where('account_code', '5001')->first(),
        };
    }

    public static function createDefaultChartOfAccounts(): void
    {
        $accounts = [
            // Assets
            ['account_code' => '1000', 'name' => 'Current Assets', 'type' => 'asset', 'sub_type' => 'current_asset', 'parent_id' => null],
            ['account_code' => '1001', 'name' => 'Cash and Cash Equivalents', 'type' => 'asset', 'sub_type' => 'current_asset', 'parent_id' => 1],
            ['account_code' => '1002', 'name' => 'Accounts Receivable', 'type' => 'asset', 'sub_type' => 'current_asset', 'parent_id' => 1],
            ['account_code' => '1003', 'name' => 'Inventory', 'type' => 'asset', 'sub_type' => 'current_asset', 'parent_id' => 1],

            ['account_code' => '1100', 'name' => 'Fixed Assets', 'type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_id' => null],
            ['account_code' => '1101', 'name' => 'Property, Plant and Equipment', 'type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_id' => 5],
            ['account_code' => '1102', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'sub_type' => 'fixed_asset', 'parent_id' => 5],

            // Liabilities
            ['account_code' => '2000', 'name' => 'Current Liabilities', 'type' => 'liability', 'sub_type' => 'current_liability', 'parent_id' => null],
            ['account_code' => '2001', 'name' => 'Accounts Payable', 'type' => 'liability', 'sub_type' => 'current_liability', 'parent_id' => 8],

            // Equity
            ['account_code' => '3000', 'name' => 'Owner Equity', 'type' => 'equity', 'sub_type' => 'owner_equity', 'parent_id' => null],
            ['account_code' => '3001', 'name' => 'Retained Earnings', 'type' => 'equity', 'sub_type' => 'retained_earnings', 'parent_id' => 10],

            // Income
            ['account_code' => '4000', 'name' => 'Revenue', 'type' => 'income', 'sub_type' => 'sales_revenue', 'parent_id' => null],
            ['account_code' => '4001', 'name' => 'Sales Revenue', 'type' => 'income', 'sub_type' => 'sales_revenue', 'parent_id' => 12],
            ['account_code' => '4002', 'name' => 'Service Revenue', 'type' => 'income', 'sub_type' => 'other_income', 'parent_id' => 12],

            // Expenses
            ['account_code' => '5000', 'name' => 'Expenses', 'type' => 'expense', 'sub_type' => 'operating_expenses', 'parent_id' => null],
            ['account_code' => '5001', 'name' => 'Operating Expenses', 'type' => 'expense', 'sub_type' => 'operating_expenses', 'parent_id' => 15],
            ['account_code' => '5002', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'sub_type' => 'cost_of_goods_sold', 'parent_id' => 15],
        ];

        foreach ($accounts as $accountData) {
            static::create($accountData);
        }

        // Update paths after creation
        foreach (static::all() as $account) {
            if ($account->parent_id) {
                $parent = static::find($account->parent_id);
                if ($parent) {
                    $account->path = $parent->path ? $parent->path . '/' . $account->id : $account->id;
                    $account->level = $parent->level + 1;
                    $account->save();
                }
            } else {
                $account->path = $account->id;
                $account->level = 1;
                $account->save();
            }
        }
    }
}
