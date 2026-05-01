<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Traits\AutoLogsActivity;

class Service extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'service_code',
        'name',
        'description',
        'category',
        'base_price',
        'min_price',
        'max_price',
        'pricing_type',
        'estimated_duration',
        'unit',
        'min_quantity',
        'max_quantity',
        'is_active',
        'requires_approval',
        'is_featured',
        'images',
        'icon',
        'options',
        'requirements',
        'instructions',
        'metadata',
        'sort_order',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'estimated_duration' => 'integer',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'is_active' => 'boolean',
        'requires_approval' => 'boolean',
        'is_featured' => 'boolean',
        'images' => 'array',
        'options' => 'array',
        'requirements' => 'array',
        'metadata' => 'array',
        'sort_order' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($service) {
            if (empty($service->service_code)) {
                $service->service_code = static::generateServiceCode();
            }
        });
    }

    // Relationships
    public function fields(): BelongsToMany
    {
        return $this->belongsToMany(Field::class, 'service_fields')
                    ->withPivot('value', 'value_json', 'is_visible', 'display_order')
                    ->withTimestamps()
                    ->orderBy('service_fields.display_order');
    }

    public function serviceFields(): HasMany
    {
        return $this->hasMany(ServiceField::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeRequiresApproval($query)
    {
        return $query->where('requires_approval', true);
    }

    public function scopeByPricingType($query, string $type)
    {
        return $query->where('pricing_type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Business logic methods
    public function calculatePrice(int $quantity = 1, array $options = []): float
    {
        $basePrice = (float) $this->base_price;

        switch ($this->pricing_type) {
            case 'per_unit':
                return $basePrice * $quantity;

            case 'hourly':
                // Assuming duration is in minutes, convert to hours
                $hours = $this->estimated_duration ? $this->estimated_duration / 60 : 1;
                return $basePrice * $hours * $quantity;

            case 'custom':
                // Custom pricing logic can be implemented here
                return $this->calculateCustomPrice($quantity, $options);

            case 'fixed':
            default:
                return $basePrice;
        }
    }

    protected function calculateCustomPrice(int $quantity, array $options): float
    {
        // Implement custom pricing logic based on options
        // This can be extended based on business requirements
        $price = $this->base_price;

        // Example: add price based on selected options
        if (isset($options['urgent']) && $options['urgent']) {
            $price *= 1.5; // 50% surcharge for urgent service
        }

        if (isset($options['express']) && $options['express']) {
            $price *= 1.25; // 25% surcharge for express service
        }

        return $price * $quantity;
    }

    public function isAvailableForQuantity(int $quantity): bool
    {
        if ($quantity < $this->min_quantity) {
            return false;
        }

        if ($this->max_quantity && $quantity > $this->max_quantity) {
            return false;
        }

        return true;
    }

    public function getFieldValue(string $fieldCode)
    {
        $serviceField = $this->serviceFields()
            ->whereHas('field', function ($query) use ($fieldCode) {
                $query->where('title', $fieldCode);
            })
            ->first();

        if (!$serviceField) {
            return null;
        }

        $field = $serviceField->field;

        // Return appropriate value based on field type
        if ($field->type === 'checkbox' || $field->hasOptions()) {
            return $serviceField->value_json ?? $serviceField->value;
        }

        return $serviceField->value;
    }

    public function setFieldValue(string $fieldCode, $value): bool
    {
        $field = Field::where('title', $fieldCode)->first();

        if (!$field) {
            return false;
        }

        $serviceField = $this->serviceFields()
            ->where('field_id', $field->id)
            ->first();

        if (!$serviceField) {
            $serviceField = $this->serviceFields()->create([
                'field_id' => $field->id,
            ]);
        }

        // Store value based on field type
        if (is_array($value) || is_object($value)) {
            $serviceField->value_json = $value;
            $serviceField->value = null;
        } else {
            $serviceField->value = $value;
            $serviceField->value_json = null;
        }

        return $serviceField->save();
    }

    public function getVisibleFields()
    {
        return $this->serviceFields()
            ->where('is_visible', true)
            ->with('field')
            ->orderBy('display_order')
            ->get()
            ->map(function ($serviceField) {
                return [
                    'field' => $serviceField->field,
                    'value' => $serviceField->value,
                    'value_json' => $serviceField->value_json,
                    'display_order' => $serviceField->display_order,
                ];
            });
    }

    public function getDurationInHours(): float
    {
        return $this->estimated_duration ? $this->estimated_duration / 60 : 0;
    }

    public function getDurationFormatted(): string
    {
        if (!$this->estimated_duration) {
            return 'N/A';
        }

        $hours = intdiv($this->estimated_duration, 60);
        $minutes = $this->estimated_duration % 60;

        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h {$minutes}m";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$minutes}m";
        }
    }

    public function hasOptions(): bool
    {
        return !empty($this->options) && is_array($this->options);
    }

    public function hasRequirements(): bool
    {
        return !empty($this->requirements) && is_array($this->requirements);
    }

    // Static methods
    public static function generateServiceCode(): string
    {
        do {
            $code = 'SRV-' . date('Y') . '-' . strtoupper(Str::random(6));
        } while (static::where('service_code', $code)->exists());

        return $code;
    }

    public static function getCategories(): array
    {
        return [
            'laundry' => 'Laundry',
            'dry_cleaning' => 'Dry Cleaning',
            'cleaning' => 'Cleaning',
            'repair' => 'Repair',
            'maintenance' => 'Maintenance',
            'delivery' => 'Delivery',
            'other' => 'Other',
        ];
    }

    public static function getPricingTypes(): array
    {
        return [
            'fixed' => 'Fixed Price',
            'per_unit' => 'Per Unit',
            'hourly' => 'Hourly',
            'custom' => 'Custom',
        ];
    }

    public static function getUnits(): array
    {
        return [
            'piece' => 'Piece',
            'kg' => 'Kilogram',
            'liter' => 'Liter',
            'hour' => 'Hour',
            'day' => 'Day',
            'sqft' => 'Square Feet',
            'other' => 'Other',
        ];
    }

    // Accessors
    public function getCategoryLabelAttribute(): string
    {
        return static::getCategories()[$this->category] ?? ucfirst($this->category ?? 'Other');
    }

    public function getPricingTypeLabelAttribute(): string
    {
        return static::getPricingTypes()[$this->pricing_type] ?? ucfirst($this->pricing_type ?? 'Fixed');
    }

    public function getUnitLabelAttribute(): string
    {
        return static::getUnits()[$this->unit] ?? ucfirst($this->unit ?? 'Piece');
    }

    public function getPrimaryImageAttribute(): ?string
    {
        return $this->images ? $this->images[0] : null;
    }

    public function getPriceRangeAttribute(): string
    {
        if ($this->pricing_type === 'fixed') {
            return '৳' . number_format((float) $this->base_price, 2);
        }

        $min = (float) ($this->min_price ?? $this->base_price);
        $max = (float) ($this->max_price ?? $this->base_price);

        if ($min === $max) {
            return '৳' . number_format($min, 2);
        }

        return '৳' . number_format($min, 2) . ' - ৳' . number_format($max, 2);
    }
}