<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EmployeeSalaryAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'store_id',
        'adjustment_month',
        'source',
        'total_reward',
        'total_fine',
        'net_adjustment',
        'applied_by',
        'applied_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'adjustment_month' => 'date',
        'total_reward' => 'decimal:2',
        'total_fine' => 'decimal:2',
        'net_adjustment' => 'decimal:2',
        'applied_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'applied_by');
    }

    public function rewardFineItems(): BelongsToMany
    {
        return $this->belongsToMany(EmployeeRewardFine::class, 'employee_salary_adjustment_items', 'adjustment_id', 'reward_fine_id');
    }
}
