<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerDailyEntry extends Model
{
    protected $fillable = [
        'entry_date',
        'sslzc_received',
        'pathao_received',
        'boss_cash_add',
        'boss_cash_add_details',
        'boss_bank_add',
        'boss_bank_add_details',
        'boss_cash_cost',
        'boss_cash_cost_details',
        'boss_bank_cost',
        'boss_bank_cost_details',
        'updated_by',
    ];

    protected $casts = [
        'entry_date'      => 'date',
        'sslzc_received'  => 'decimal:2',
        'pathao_received' => 'decimal:2',
        'boss_cash_add'   => 'decimal:2',
        'boss_bank_add'   => 'decimal:2',
        'boss_cash_cost'  => 'decimal:2',
        'boss_bank_cost'  => 'decimal:2',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }
}
