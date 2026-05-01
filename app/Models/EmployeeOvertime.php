<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeOvertime extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'store_id',
        'overtime_date',
        'overtime_minutes',
        'overtime_hours',
        'overtime_hhmm',
        'notes',
        'marked_by',
        'marked_at',
        'is_modified',
        'overtime_pay',
        'is_applied',
        'applied_at',
    ];

    protected $casts = [
        'overtime_date' => 'date',
        'overtime_minutes' => 'integer',
        'overtime_hours' => 'decimal:2',
        'marked_at' => 'datetime',
        'is_modified' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'marked_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(EmployeeOvertimeHistory::class, 'overtime_id');
    }
}
