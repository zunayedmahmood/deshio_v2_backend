<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PasswordResetToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'email',
        'token',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('used_at')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    });
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeUsed($query)
    {
        return $query->whereNotNull('used_at');
    }

    public function scopeByToken($query, $token)
    {
        return $query->where('token', $token);
    }

    public function scopeByEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function isExpired(): bool
    {
        return !is_null($this->expires_at) && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return !is_null($this->used_at);
    }

    public function isActive(): bool
    {
        return !$this->isUsed() && !$this->isExpired();
    }

    public function markAsUsed()
    {
        $this->update(['used_at' => now()]);
        return $this;
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function createForEmployee(Employee $employee, $expiresInHours = 1)
    {
        // Clean up old tokens for this employee
        static::where('employee_id', $employee->id)->delete();

        return static::create([
            'employee_id' => $employee->id,
            'email' => $employee->email,
            'token' => static::generateToken(),
            'expires_at' => now()->addHours($expiresInHours),
        ]);
    }

    public function getIsActiveAttribute()
    {
        return $this->isActive();
    }

    public function getIsExpiredAttribute()
    {
        return $this->isExpired();
    }

    public function getIsUsedAttribute()
    {
        return $this->isUsed();
    }
}
