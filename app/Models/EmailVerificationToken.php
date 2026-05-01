<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmailVerificationToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'email',
        'token',
        'expires_at',
        'verified_at',
        'type',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('verified_at')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    });
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeVerified($query)
    {
        return $query->whereNotNull('verified_at');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
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

    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    public function isActive(): bool
    {
        return !$this->isVerified() && !$this->isExpired();
    }

    public function verify()
    {
        $this->update(['verified_at' => now()]);
        return $this;
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public static function createForEmployee(Employee $employee, string $email, string $type = 'email_verification', $expiresInHours = 24)
    {
        // Clean up old tokens for this employee and type
        static::where('employee_id', $employee->id)
              ->where('type', $type)
              ->delete();

        return static::create([
            'employee_id' => $employee->id,
            'email' => $email,
            'token' => static::generateToken(),
            'type' => $type,
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

    public function getIsVerifiedAttribute()
    {
        return $this->isVerified();
    }
}
