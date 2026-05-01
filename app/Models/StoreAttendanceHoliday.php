<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAttendanceHoliday extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'start_date',
        'end_date',
        'title',
        'description',
        'declared_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
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
