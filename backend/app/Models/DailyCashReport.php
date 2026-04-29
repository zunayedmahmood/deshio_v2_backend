<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyCashReport extends Model
{
    protected $fillable = [
        'report_date',
        'store_id',
        'salary_set_aside',
        'daily_cost',
        'daily_cost_details',
        'updated_by',
    ];

    protected $casts = [
        'report_date'      => 'date',
        'salary_set_aside' => 'decimal:2',
        'daily_cost'       => 'decimal:2',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }
}
