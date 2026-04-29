<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeMFABackupCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_mfa_id',
        'code',
        'is_used',
        'used_at',
        'expires_at',
    ];

    protected $casts = [
        'is_used' => 'boolean',
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function employeeMfa(): BelongsTo
    {
        return $this->belongsTo(EmployeeMFA::class, 'employee_mfa_id');
    }

    public function employee()
    {
        return $this->hasOneThrough(Employee::class, EmployeeMFA::class, 'id', 'id', 'employee_mfa_id', 'employee_id');
    }

    public function scopeUnused($query)
    {
        return $query->where('is_used', false);
    }

    public function scopeUsed($query)
    {
        return $query->where('is_used', true);
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeActive($query)
    {
        return $query->unused()->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeByMfa($query, $mfaId)
    {
        return $query->where('employee_mfa_id', $mfaId);
    }

    public function scopeByCode($query, $code)
    {
        return $query->where('code', $code);
    }

    public function isExpired(): bool
    {
        return !is_null($this->expires_at) && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return !$this->is_used && !$this->isExpired();
    }

    public function useCode()
    {
        $this->update([
            'is_used' => true,
            'used_at' => now(),
        ]);
        return $this;
    }

    public static function generateCode(): string
    {
        return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }

    public static function generateForMfa(EmployeeMFA $mfa, $count = 10, $expiresInDays = 365): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = static::create([
                'employee_mfa_id' => $mfa->id,
                'code' => static::generateCode(),
                'expires_at' => now()->addDays($expiresInDays),
            ]);
        }
        return $codes;
    }

    public function getIsActiveAttribute()
    {
        return $this->isActive();
    }

    public function getIsExpiredAttribute()
    {
        return $this->isExpired();
    }
}
