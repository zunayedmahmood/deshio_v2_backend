<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\AutoLogsActivity;

class Role extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'guard_name',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByGuard($query, $guard)
    {
        return $query->where('guard_name', $guard);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('title');
    }

    // -------------------------------------------------------------------------
    // Permissions relation is kept for backwards-compatibility with any existing
    // pivot data, but access control is now enforced entirely on the frontend
    // via role slugs. Do not rely on this relation for authorization logic.
    // -------------------------------------------------------------------------
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function getDisplayNameAttribute()
    {
        return $this->title;
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function activeEmployees()
    {
        return $this->employees()->active()->inService();
    }
}
