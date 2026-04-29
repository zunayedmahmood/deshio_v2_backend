<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductField extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'field_id',
        'value',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class);
    }

    public function getParsedValueAttribute()
    {
        if (!$this->value) {
            return null;
        }

        $field = $this->field;

        switch ($field->type) {
            case 'number':
                return is_numeric($this->value) ? (float) $this->value : $this->value;
            case 'boolean':
                return in_array(strtolower($this->value), ['true', '1', 'yes', 'on']);
            case 'date':
                return $this->value; // Will be cast by Laravel if needed
            case 'json':
            case 'select':
            case 'checkbox':
                return json_decode($this->value, true) ?? $this->value;
            default:
                return $this->value;
        }
    }

    public function setParsedValueAttribute($value)
    {
        $field = $this->field;

        switch ($field->type) {
            case 'boolean':
                $this->attributes['value'] = $value ? 'true' : 'false';
                break;
            case 'json':
            case 'select':
            case 'checkbox':
                $this->attributes['value'] = json_encode($value);
                break;
            default:
                $this->attributes['value'] = (string) $value;
        }
    }
}