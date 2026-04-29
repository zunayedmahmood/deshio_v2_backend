<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAttendanceHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_id',
        'old_status',
        'new_status',
        'old_in_time',
        'new_in_time',
        'old_out_time',
        'new_out_time',
        'reason',
        'changed_by',
        'changed_at',
        'metadata',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAttendance::class, 'attendance_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}