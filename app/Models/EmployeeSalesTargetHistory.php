<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalesTargetHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_target_id',
        'action',
        'old_target_month',
        'new_target_month',
        'old_target_amount',
        'new_target_amount',
        'old_notes',
        'new_notes',
        'reason',
        'changed_by',
        'changed_at',
        'metadata',
    ];

    protected $casts = [
        'old_target_month' => 'date',
        'new_target_month' => 'date',
        'old_target_amount' => 'decimal:2',
        'new_target_amount' => 'decimal:2',
        'changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function salesTarget(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalesTarget::class, 'sales_target_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
