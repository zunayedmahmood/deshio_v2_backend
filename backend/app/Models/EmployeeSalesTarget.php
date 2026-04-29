<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeSalesTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'store_id',
        'target_month',
        'target_amount',
        'notes',
        'set_by',
        'updated_by',
    ];

    protected $casts = [
        'target_month' => 'date',
        'target_amount' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'set_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(EmployeeSalesTargetHistory::class, 'sales_target_id');
    }
}
