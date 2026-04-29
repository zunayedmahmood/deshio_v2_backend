<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmployeeSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'token',
        'ip_address',
        'user_agent',
        'revoked_at',
        'expires_at',
        'last_activity_at',
        'device_info',
        'session_id',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'device_info' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    });
    }

    public function scopeRevoked($query)
    {
        return $query->whereNotNull('revoked_at');
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeByEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeByToken($query, $token)
    {
        return $query->where('token', $token);
    }

    public function isActive(): bool
    {
        return is_null($this->revoked_at) &&
               (is_null($this->expires_at) || $this->expires_at->isFuture());
    }

    public function isRevoked(): bool
    {
        return !is_null($this->revoked_at);
    }

    public function isExpired(): bool
    {
        return !is_null($this->expires_at) && $this->expires_at->isPast();
    }

    public function revoke()
    {
        $this->update(['revoked_at' => now()]);
        return $this;
    }

    public function updateActivity()
    {
        $this->update(['last_activity_at' => now()]);
        return $this;
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public function getDeviceInfoAttribute()
    {
        return $this->attributes['device_info'] ?? [];
    }

    public function getIsActiveAttribute()
    {
        return $this->isActive();
    }

    public function getIsExpiredAttribute()
    {
        return $this->isExpired();
    }

    public function getIsRevokedAttribute()
    {
        return $this->isRevoked();
    }
}