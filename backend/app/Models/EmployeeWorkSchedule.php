<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWorkSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'store_id',
        'start_time',
        'end_time',
        'effective_from',
        'effective_to',
        'duty_mode',
        'weekly_days',
        'duty_dates',
        'is_active',
        'assigned_by',
        'notes',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'weekly_days' => 'array',
        'duty_dates' => 'array',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }
}
