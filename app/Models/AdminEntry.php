<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminEntry extends Model
{
    protected $fillable = ['entry_date','type','store_id','amount','details','created_by'];
    protected $casts = ['entry_date' => 'date', 'amount' => 'decimal:2'];
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(Employee::class,'created_by'); }
}
