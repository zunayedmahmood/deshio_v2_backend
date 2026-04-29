<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceField extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'field_id',
        'value',
        'value_json',
        'is_visible',
        'display_order',
    ];

    protected $casts = [
        'value_json' => 'array',
        'is_visible' => 'boolean',
        'display_order' => 'integer',
    ];

    // Relationships
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class);
    }

    // Scopes
    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // Accessors
    public function getDisplayValueAttribute()
    {
        $field = $this->field;

        if (!$field) {
            return $this->value;
        }

        // Handle different field types
        switch ($field->type) {
            case 'checkbox':
                return $this->value_json ? 'Yes' : 'No';

            case 'select':
            case 'radio':
                if ($this->value && $field->hasOptions()) {
                    $options = array_column($field->options, 'label', 'value');
                    return $options[$this->value] ?? $this->value;
                }
                return $this->value;

            case 'multiselect':
            case 'checkbox_group':
                if ($this->value_json && is_array($this->value_json) && $field->hasOptions()) {
                    $options = array_column($field->options, 'label', 'value');
                    $labels = [];
                    foreach ($this->value_json as $value) {
                        $labels[] = $options[$value] ?? $value;
                    }
                    return implode(', ', $labels);
                }
                return is_array($this->value_json) ? implode(', ', $this->value_json) : $this->value;

            case 'file':
                return $this->value ? 'File uploaded' : 'No file';

            case 'date':
                return $this->value ? date('M d, Y', strtotime($this->value)) : null;

            case 'number':
                return $this->value ? number_format((float) $this->value) : null;

            default:
                return $this->value;
        }
    }

    public function getRawValueAttribute()
    {
        // Return the appropriate value based on field type
        if ($this->field && in_array($this->field->type, ['multiselect', 'checkbox_group', 'checkbox'])) {
            return $this->value_json ?? $this->value;
        }

        return $this->value;
    }

    // Mutators
    public function setValueAttribute($value)
    {
        $field = $this->field;

        // Handle array/object values
        if (is_array($value) || is_object($value)) {
            $this->attributes['value_json'] = json_encode($value);
            $this->attributes['value'] = null;
        } else {
            $this->attributes['value'] = $value;
            $this->attributes['value_json'] = null;
        }
    }

    // Helper methods
    public function isEmpty(): bool
    {
        return empty($this->value) && empty($this->value_json);
    }

    public function hasValue(): bool
    {
        return !$this->isEmpty();
    }

    public function clearValue(): bool
    {
        $this->value = null;
        $this->value_json = null;
        return $this->save();
    }
}