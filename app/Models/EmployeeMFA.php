<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeMFA extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'type',
        'secret',
        'is_enabled',
        'verified_at',
        'last_used_at',
        'settings',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
        'settings' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function backupCodes()
    {
        return $this->hasMany(EmployeeMFABackupCode::class, 'employee_mfa_id');
    }

    public function activeBackupCodes()
    {
        return $this->backupCodes()->active();
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeVerified($query)
    {
        return $query->whereNotNull('verified_at');
    }

    public function scopeByEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    public function isEnabled(): bool
    {
        return $this->is_enabled && $this->isVerified();
    }

    public function enable()
    {
        $this->update(['is_enabled' => true]);
        return $this;
    }

    public function disable()
    {
        $this->update(['is_enabled' => false]);
        return $this;
    }

    public function verify()
    {
        $this->update(['verified_at' => now()]);
        return $this;
    }

    public function updateLastUsed()
    {
        $this->update(['last_used_at' => now()]);
        return $this;
    }

    public function hasBackupCodes(): bool
    {
        return $this->backupCodes()->exists();
    }

    public function useBackupCode($code): bool
    {
        $backupCode = $this->backupCodes()->byCode($code)->active()->first();

        if ($backupCode) {
            $backupCode->useCode();
            $this->updateLastUsed();
            return true;
        }

        return false;
    }

    public function generateBackupCodes($count = 10): array
    {
        // Delete existing codes
        $this->backupCodes()->delete();

        // Generate new codes
        $codes = EmployeeMFABackupCode::generateForMfa($this, $count);

        return collect($codes)->pluck('code')->toArray();
    }

    public function getIsVerifiedAttribute()
    {
        return $this->isVerified();
    }

    public function getIsEnabledAttribute()
    {
        return $this->isEnabled();
    }
}
