
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeRewardFineHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'reward_fine_id',
        'action',
        'old_entry_date',
        'new_entry_date',
        'old_entry_type',
        'new_entry_type',
        'old_amount',
        'new_amount',
        'old_title',
        'new_title',
        'old_notes',
        'new_notes',
        'reason',
        'changed_by',
        'changed_at',
        'metadata',
    ];

    protected $casts = [
        'old_entry_date' => 'date',
        'new_entry_date' => 'date',
        'old_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
        'changed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function rewardFine(): BelongsTo
    {
        return $this->belongsTo(EmployeeRewardFine::class, 'reward_fine_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
