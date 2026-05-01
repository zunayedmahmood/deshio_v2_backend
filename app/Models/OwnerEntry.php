<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerEntry extends Model
{
    protected $fillable = ['entry_date','type','amount','details','created_by'];
    protected $casts = ['entry_date' => 'date', 'amount' => 'decimal:2'];
    public function createdBy(): BelongsTo { return $this->belongsTo(Employee::class,'created_by'); }
}
