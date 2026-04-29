<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeRewardFine extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'store_id',
        'entry_date',
        'entry_type',
        'amount',
        'title',
        'notes',
        'created_by',
        'updated_by',
        'is_applied',
        'applied_month',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
        'is_applied' => 'boolean',
        'applied_month' => 'date',
        'applied_at' => 'datetime',
    ];

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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'applied_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(EmployeeRewardFineHistory::class, 'reward_fine_id');
    }
}
