<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'store_id',
        'attendance_date',
        'status',
        'in_time',
        'out_time',
        'marked_by',
        'marked_at',
        'notes',
        'is_modified',
        'late_minutes',
        'late_fee',
        'is_applied',
        'applied_at',
    ];

    protected $casts = [
        'attendance_date' => 'date',
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
        return $this->hasMany(EmployeeAttendanceHistory::class, 'attendance_id');
    }
}