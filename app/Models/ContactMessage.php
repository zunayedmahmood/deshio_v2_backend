<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'phone',
        'name',
        'message',
        'status',
        'admin_reply',
        'replied_at',
        'replied_by',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
    ];

    /**
     * Get the employee who replied to this message
     */
    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'replied_by');
    }

    /**
     * Scope to filter by status
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by phone
     */
    public function scopeByPhone($query, $phone)
    {
        $cleanPhone = preg_replace('/\D+/', '', $phone);
        return $query->where('phone', $cleanPhone)
                    ->orWhere('phone', $phone);
    }
}
