<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Field extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'type',
        'description',
        'is_required',
        'default_value',
        'options',
        'validation_rules',
        'placeholder',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'options' => 'array',
        'order' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('title');
    }

    public function getValidationRulesArray()
    {
        if (!$this->validation_rules) {
            return [];
        }

        return explode('|', $this->validation_rules);
    }

    public function hasOptions()
    {
        return !empty($this->options) && is_array($this->options);
    }

    public function getOptionsList()
    {
        return $this->hasOptions() ? $this->options : [];
    }

    public function isSelectType()
    {
        return in_array($this->type, ['select', 'radio', 'checkbox']);
    }

    public function isFileType()
    {
        return $this->type === 'file';
    }

    public function getDefaultValidationRules()
    {
        $rules = [];

        if ($this->is_required) {
            $rules[] = 'required';
        } else {
            $rules[] = 'nullable';
        }

        switch ($this->type) {
            case 'email':
                $rules[] = 'email';
                break;
            case 'url':
                $rules[] = 'url';
                break;
            case 'number':
                $rules[] = 'numeric';
                break;
            case 'date':
                $rules[] = 'date';
                break;
            case 'file':
                $rules[] = 'file';
                break;
        }

        return implode('|', $rules);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_fields')
                    ->withPivot('value')
                    ->withTimestamps();
    }

    public function productFields()
    {
        return $this->hasMany(ProductField::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'service_fields')
                    ->withPivot('value', 'value_json', 'is_visible', 'display_order')
                    ->withTimestamps();
    }

    public function serviceFields()
    {
        return $this->hasMany(ServiceField::class);
    }
}
