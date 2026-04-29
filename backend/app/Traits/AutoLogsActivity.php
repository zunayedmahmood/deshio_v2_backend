<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

/**
 * Auto-logging trait for all models
 * Automatically tracks WHO, WHEN, and WHAT for all database operations
 * 
 * Usage: Add this trait to any model that needs automatic logging
 * use AutoLogsActivity;
 */
trait AutoLogsActivity
{
    use LogsActivity;

    /**
     * Configure what and how to log
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->getLoggableAttributes())  // Log specific attributes
            ->logOnlyDirty()  // Only log changed attributes
            ->dontSubmitEmptyLogs()  // Skip if no changes
            ->useLogName($this->getTable())  // Use table name as log name
            ->setDescriptionForEvent(fn(string $eventName) => $this->getLogDescription($eventName));
    }

    /**
     * Get attributes that should be logged
     * Override this in model if you want to exclude sensitive fields
     */
    protected function getLoggableAttributes(): array
    {
        // Get all fillable attributes
        $attributes = $this->getFillable();
        
        // Add timestamps if they exist
        if ($this->timestamps) {
            $attributes[] = 'created_at';
            $attributes[] = 'updated_at';
        }
        
        // Add soft deletes if they exist
        if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($this))) {
            $attributes[] = 'deleted_at';
        }
        
        // Exclude sensitive fields by default
        $excludeFields = ['password', 'remember_token', 'api_token', 'access_token'];
        
        return array_diff($attributes, $excludeFields);
    }

    /**
     * Get human-readable description for the event
     */
    protected function getLogDescription(string $eventName): string
    {
        $modelName = class_basename($this);
        $identifier = $this->getLogIdentifier();
        
        switch ($eventName) {
            case 'created':
                return "Created {$modelName}: {$identifier}";
            case 'updated':
                return "Updated {$modelName}: {$identifier}";
            case 'deleted':
                return "Deleted {$modelName}: {$identifier}";
            default:
                return "{$eventName} {$modelName}: {$identifier}";
        }
    }

    /**
     * Get identifier for the model (name, title, id, etc.)
     */
    protected function getLogIdentifier(): string
    {
        // Try common identifier fields
        $identifierFields = ['name', 'title', 'order_number', 'invoice_number', 'sku', 'email', 'phone'];
        
        foreach ($identifierFields as $field) {
            if (isset($this->$field)) {
                return $this->$field;
            }
        }
        
        // Fallback to primary key
        return "#{$this->getKey()}";
    }

    /**
     * Tap into the activity log before it's saved
     * This is where we add WHO information
     */
    public function tapActivity($activity, string $eventName)
    {
        // Store the authenticated user (WHO)
        if (Auth::guard('api')->check()) {
            $activity->causer_type = 'App\Models\Employee';
            $activity->causer_id = Auth::guard('api')->id();
        } elseif (Auth::guard('customer')->check()) {
            $activity->causer_type = 'App\Models\Customer';
            $activity->causer_id = Auth::guard('customer')->id();
        }

        // Add additional metadata
        $activity->properties = $activity->properties->put('ip_address', request()->ip());
        $activity->properties = $activity->properties->put('user_agent', request()->userAgent());
        $activity->properties = $activity->properties->put('url', request()->fullUrl());
        $activity->properties = $activity->properties->put('method', request()->method());
    }
}
