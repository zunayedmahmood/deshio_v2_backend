<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\AutoLogsActivity;

class ExpenseCategory extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'name',
        'code',
        'description',
        'type',
        'parent_id',
        'monthly_budget',
        'yearly_budget',
        'requires_approval',
        'approval_threshold',
        'is_active',
        'sort_order',
        'icon',
        'color',
        'metadata',
    ];

    protected $casts = [
        'monthly_budget' => 'float',
        'yearly_budget' => 'float',
        'approval_threshold' => 'float',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    // Relationships
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class, 'parent_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    public function activeExpenses()
    {
        return $this->expenses()->whereNotIn('status', ['cancelled', 'rejected']);
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

    public function scopeRequiresApproval($query)
    {
        return $query->where('requires_approval', true);
    }

    public function scopeRootCategories($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Business logic methods
    public function isChild(): bool
    {
        return !is_null($this->parent_id);
    }

    public function isParent(): bool
    {
        return $this->children()->exists();
    }

    public function getFullNameAttribute(): string
    {
        if ($this->isChild()) {
            return $this->parent->name . ' > ' . $this->name;
        }
        return $this->name;
    }

    public function requiresApprovalForAmount(float $amount): bool
    {
        if (!$this->requires_approval) {
            return false;
        }

        return $this->approval_threshold ? $amount >= $this->approval_threshold : true;
    }

    public function getMonthlyBudgetUsed(): float
    {
        if (!$this->monthly_budget) {
            return 0;
        }

        return $this->activeExpenses()
            ->whereMonth('expense_date', now()->month)
            ->whereYear('expense_date', now()->year)
            ->sum('total_amount');
    }

    public function getMonthlyBudgetRemaining(): float
    {
        if (!$this->monthly_budget) {
            return 0;
        }

        return $this->monthly_budget - $this->getMonthlyBudgetUsed();
    }

    public function getYearlyBudgetUsed(): float
    {
        if (!$this->yearly_budget) {
            return 0;
        }

        return $this->activeExpenses()
            ->whereYear('expense_date', now()->year)
            ->sum('total_amount');
    }

    public function getYearlyBudgetRemaining(): float
    {
        if (!$this->yearly_budget) {
            return 0;
        }

        return $this->yearly_budget - $this->getYearlyBudgetUsed();
    }

    public function isOverMonthlyBudget(): bool
    {
        return $this->monthly_budget && $this->getMonthlyBudgetUsed() > $this->monthly_budget;
    }

    public function isOverYearlyBudget(): bool
    {
        return $this->yearly_budget && $this->getYearlyBudgetUsed() > $this->yearly_budget;
    }

    // Accessors
    public function getBudgetStatusAttribute(): string
    {
        if ($this->isOverMonthlyBudget() || $this->isOverYearlyBudget()) {
            return 'over_budget';
        }

        $monthlyUsage = $this->monthly_budget ? ($this->getMonthlyBudgetUsed() / $this->monthly_budget) * 100 : 0;
        $yearlyUsage = $this->yearly_budget ? ($this->getYearlyBudgetUsed() / $this->yearly_budget) * 100 : 0;

        if ($monthlyUsage >= 90 || $yearlyUsage >= 90) {
            return 'near_limit';
        }

        return 'within_budget';
    }

    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'operational' => 'Operational',
            'capital' => 'Capital',
            'personnel' => 'Personnel',
            'marketing' => 'Marketing',
            'administrative' => 'Administrative',
            'logistics' => 'Logistics',
            'utilities' => 'Utilities',
            'maintenance' => 'Maintenance',
            'taxes' => 'Taxes',
            'insurance' => 'Insurance',
            'other' => 'Other',
            default => 'Unknown',
        };
    }

    // Static methods
    public static function getCategoryTree(): array
    {
        $categories = [];
        $rootCategories = static::active()->rootCategories()->ordered()->get();

        foreach ($rootCategories as $root) {
            $categories[] = [
                'id' => $root->id,
                'name' => $root->name,
                'code' => $root->code,
                'type' => $root->type,
                'children' => $root->children()->active()->ordered()->get()->toArray(),
            ];
        }

        return $categories;
    }

    public static function createDefaultCategories(): void
    {
        $categories = [
            // Operational Expenses
            ['name' => 'Office Supplies', 'code' => 'OPS', 'type' => 'operational', 'requires_approval' => false],
            ['name' => 'Office Equipment', 'code' => 'OFE', 'type' => 'operational', 'requires_approval' => true, 'approval_threshold' => 5000],
            ['name' => 'Software Licenses', 'code' => 'SWL', 'type' => 'operational', 'requires_approval' => true, 'approval_threshold' => 1000],

            // Personnel Expenses
            ['name' => 'Salaries', 'code' => 'SAL', 'type' => 'personnel', 'requires_approval' => false],
            ['name' => 'Employee Benefits', 'code' => 'BEN', 'type' => 'personnel', 'requires_approval' => true, 'approval_threshold' => 2000],
            ['name' => 'Training & Development', 'code' => 'TRD', 'type' => 'personnel', 'requires_approval' => true, 'approval_threshold' => 1000],

            // Marketing Expenses
            ['name' => 'Advertising', 'code' => 'ADV', 'type' => 'marketing', 'requires_approval' => true, 'approval_threshold' => 2000],
            ['name' => 'Promotional Materials', 'code' => 'PRM', 'type' => 'marketing', 'requires_approval' => true, 'approval_threshold' => 1000],
            ['name' => 'Events & Sponsorships', 'code' => 'EVS', 'type' => 'marketing', 'requires_approval' => true, 'approval_threshold' => 5000],

            // Administrative Expenses
            ['name' => 'Rent & Lease', 'code' => 'RNT', 'type' => 'administrative', 'requires_approval' => false],
            ['name' => 'Insurance', 'code' => 'INS', 'type' => 'administrative', 'requires_approval' => true, 'approval_threshold' => 10000],
            ['name' => 'Legal & Professional Fees', 'code' => 'LPF', 'type' => 'administrative', 'requires_approval' => true, 'approval_threshold' => 2000],

            // Logistics Expenses
            ['name' => 'Shipping & Courier', 'code' => 'SHP', 'type' => 'logistics', 'requires_approval' => false],
            ['name' => 'Vehicle Maintenance', 'code' => 'VMT', 'type' => 'logistics', 'requires_approval' => true, 'approval_threshold' => 2000],
            ['name' => 'Fuel & Transportation', 'code' => 'FLT', 'type' => 'logistics', 'requires_approval' => false],

            // Utilities
            ['name' => 'Electricity', 'code' => 'ELE', 'type' => 'utilities', 'requires_approval' => false],
            ['name' => 'Water', 'code' => 'WTR', 'type' => 'utilities', 'requires_approval' => false],
            ['name' => 'Internet & Communications', 'code' => 'ITC', 'type' => 'utilities', 'requires_approval' => false],
            ['name' => 'Gas', 'code' => 'GAS', 'type' => 'utilities', 'requires_approval' => false],

            // Maintenance
            ['name' => 'Facility Maintenance', 'code' => 'FCM', 'type' => 'maintenance', 'requires_approval' => true, 'approval_threshold' => 3000],
            ['name' => 'Equipment Maintenance', 'code' => 'EQM', 'type' => 'maintenance', 'requires_approval' => true, 'approval_threshold' => 2000],

            // Taxes
            ['name' => 'Income Tax', 'code' => 'ITX', 'type' => 'taxes', 'requires_approval' => false],
            ['name' => 'VAT & Sales Tax', 'code' => 'VTX', 'type' => 'taxes', 'requires_approval' => false],
            ['name' => 'Other Taxes', 'code' => 'OTX', 'type' => 'taxes', 'requires_approval' => false],

            // Other
            ['name' => 'Bank Charges', 'code' => 'BCH', 'type' => 'other', 'requires_approval' => false],
            ['name' => 'Miscellaneous', 'code' => 'MSC', 'type' => 'other', 'requires_approval' => true, 'approval_threshold' => 500],
        ];

        foreach ($categories as $category) {
            static::create($category);
        }
    }
}
