<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDailySale extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'store_id',
        'sales_date',
        'order_count',
        'total_sales_amount',
        'last_computed_at',
    ];

    protected $casts = [
        'sales_date' => 'date',
        'order_count' => 'integer',
        'total_sales_amount' => 'decimal:2',
        'last_computed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}