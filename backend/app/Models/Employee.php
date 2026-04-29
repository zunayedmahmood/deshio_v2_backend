<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use App\Traits\AutoLogsActivity;

class Employee extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, SoftDeletes, AutoLogsActivity;

    protected $fillable = [
        'name',
        'email',
        'password',
        'store_id',
        'is_in_service',
        'role_id',
        'phone',
        'address',
        'employee_code',
        'hire_date',
        'department',
        'salary',
        'manager_id',
        'is_active',
        'avatar',
        'last_login_at',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'hire_date' => 'date',
        'salary' => 'decimal:2',
        'is_in_service' => 'boolean',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    // Rest omitted for brevity

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EmployeeSession::class);
    }

    public function activeSessions()
    {
        return $this->sessions()->active();
    }

    public function emailVerificationTokens(): HasMany
    {
        return $this->hasMany(EmailVerificationToken::class);
    }

    public function activeEmailVerificationTokens()
    {
        return $this->emailVerificationTokens()->active();
    }

    public function passwordResetTokens(): HasMany
    {
        return $this->hasMany(PasswordResetToken::class);
    }

    public function activePasswordResetTokens()
    {
        return $this->passwordResetTokens()->active();
    }

    public function mfa(): HasMany
    {
        return $this->hasMany(EmployeeMFA::class);
    }

    public function enabledMfa()
    {
        return $this->mfa()->enabled();
    }

    public function mfaBackupCodes()
    {
        return $this->hasManyThrough(EmployeeMFABackupCode::class, EmployeeMFA::class);
    }

    public function activeMfaBackupCodes()
    {
        return $this->mfaBackupCodes()->active();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'employee_id');
    }

    public function createdNotes(): HasMany
    {
        return $this->hasMany(Note::class, 'created_by');
    }

    public function activeNotes()
    {
        return $this->notes()->active();
    }

    public function publicNotes()
    {
        return $this->notes()->active()->public();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInService($query)
    {
        return $query->where('is_in_service', true);
    }

    public function scopeByStore($query, $storeId)
    {
        return $query->where('store_id', $storeId);
    }

    public function scopeByRole($query, $roleId)
    {
        return $query->where('role_id', $roleId);
    }

    /**
     * Check if this employee has global visibility (not scoped to a single store).
     * Global roles: super-admin, admin.
     * All other roles are store-scoped (branch-manager, pos-salesman, etc.).
     */
    public function isGlobal(): bool
    {
        $globalSlugs = ['super-admin', 'admin'];
        return $this->role && in_array($this->role->slug, $globalSlugs);
    }

    /**
     * @deprecated Permission-based checks are no longer used.
     *             Access control is enforced on the frontend via role slugs.
     *             This method always returns false to avoid any accidental usage.
     */
    public function hasPermission($permission): bool
    {
        return false;
    }

    public function getFullNameAttribute()
    {
        return $this->name;
    }

    public function getIsManagerAttribute()
    {
        return $this->subordinates()->exists();
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(EmployeeWorkSchedule::class);
    }

    public function activeWorkSchedule()
    {
        return $this->workSchedules()->where('is_active', true)->where('effective_from', '<=', now())->first();
    }

    public function overtime(): HasMany
    {
        return $this->hasMany(EmployeeOvertime::class);
    }

    public function rewardFines(): HasMany
    {
        return $this->hasMany(EmployeeRewardFine::class);
    }

    public function salaryAdjustments(): HasMany
    {
        return $this->hasMany(EmployeeSalaryAdjustment::class);
    }

    public function salesTargets(): HasMany
    {
        return $this->hasMany(EmployeeSalesTarget::class);
    }

    public function dailySales(): HasMany
    {
        return $this->hasMany(EmployeeDailySale::class);
    }

    public function updateLastLogin()
    {
        $this->update(['last_login_at' => now()]);
    }

    public static function generateEmployeeCode(): string
    {
        do {
            $code = 'EMP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
        } while (static::where('employee_code', $code)->exists());

        return $code;
    }
}
