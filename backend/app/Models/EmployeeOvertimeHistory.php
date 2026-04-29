<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeOvertimeHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'overtime_id',
        'old_overtime_date',
        'new_overtime_date',
        'old_overtime_minutes',
        'new_overtime_minutes',
        'old_overtime_hours',
        'new_overtime_hours',
        'old_overtime_hhmm',
        'new_overtime_hhmm',
        'reason',
        'change_note',
        'changed_by',
        'changed_at',
        'metadata',
    ];

    protected $casts = [
        'old_overtime_date' => 'date',
        'new_overtime_date' => 'date',
        'old_overtime_minutes' => 'integer',
        'new_overtime_minutes' => 'integer',
        'old_overtime_hours' => 'decimal:2',
        'new_overtime_hours' => 'decimal:2',
        'changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function overtime(): BelongsTo
    {
        return $this->belongsTo(EmployeeOvertime::class, 'overtime_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
