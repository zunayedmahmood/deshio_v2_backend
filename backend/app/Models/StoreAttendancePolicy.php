<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAttendancePolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'mode',
        'fixed_days_off',
        'fixed_start_time',
        'fixed_end_time',
        'timezone',
        'effective_from',
        'effective_to',
        'declared_by',
        'notes',
        'late_fee_per_minute',
        'overtime_rate_per_hour',
        'grace_period_minutes',
    ];

    protected $casts = [
        'fixed_days_off' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function declaredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'declared_by');
    }
}