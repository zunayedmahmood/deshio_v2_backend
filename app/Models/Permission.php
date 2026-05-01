<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'module',
        'guard_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByModule($query, $module)
    {
        return $query->where('module', $module);
    }

    public function scopeByGuard($query, $guard)
    {
        return $query->where('guard_name', $guard);
    }

    public function scopeForGuard($query, $guard = null)
    {
        if ($guard) {
            return $query->where(function ($q) use ($guard) {
                $q->where('guard_name', $guard)
                  ->orWhereNull('guard_name');
            });
        }

        return $query->whereNull('guard_name');
    }

    public function getDisplayNameAttribute()
    {
        return $this->title . ($this->module ? ' (' . $this->module . ')' : '');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
