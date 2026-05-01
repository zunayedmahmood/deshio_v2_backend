<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\AutoLogsActivity;

class ServiceOrderItem extends Model
{
    use HasFactory, AutoLogsActivity;

    protected $fillable = [
        'service_order_id',
        'service_id',
        'service_field_id',
        'service_name',
        'service_code',
        'service_description',
        'quantity',
        'unit_price',
        'base_price',
        'total_price',
        'selected_options',
        'field_values',
        'customizations',
        'status',
        'scheduled_date',
        'scheduled_time',
        'estimated_duration',
        'started_at',
        'completed_at',
        'special_instructions',
        'internal_notes',
        'customer_notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'float',
        'base_price' => 'float',
        'total_price' => 'float',
        'selected_options' => 'array',
        'field_values' => 'array',
        'customizations' => 'array',
        'scheduled_date' => 'datetime',
        'scheduled_time' => 'datetime',
        'estimated_duration' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($item) {
            if (empty($item->total_price)) {
                $item->total_price = $item->quantity * $item->unit_price;
            }

            // Set service details if not provided
            if ($item->service && empty($item->service_name)) {
                $item->service_name = $item->service->name;
                $item->service_code = $item->service->service_code;
                $item->service_description = $item->service->description;
                $item->base_price = $item->service->base_price;
            }
        });

        static::updating(function ($item) {
            if ($item->isDirty(['quantity', 'unit_price'])) {
                $item->total_price = $item->quantity * $item->unit_price;
            }
        });
    }

    // Relationships
    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceField(): BelongsTo
    {
        return $this->belongsTo(ServiceField::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeByService($query, $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    public function scopeScheduledToday($query)
    {
        return $query->whereDate('scheduled_date', today());
    }

    public function scopeOverdue($query)
    {
        return $query->where('scheduled_date', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled']);
    }

    // Business logic methods
    public function confirm(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update(['status' => 'confirmed']);
        return true;
    }

    public function start(): bool
    {
        if ($this->status !== 'confirmed') {
            return false;
        }

        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return true;
    }

    public function complete(): bool
    {
        if ($this->status !== 'in_progress') {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return true;
    }

    public function cancel(): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        $this->update(['status' => 'cancelled']);
        return true;
    }

    public function updateQuantity(int $newQuantity): self
    {
        $this->quantity = $newQuantity;
        $this->total_price = $newQuantity * $this->unit_price;
        $this->save();

        $this->serviceOrder->recalculateTotals();

        return $this;
    }

    public function updateUnitPrice(float $newPrice): self
    {
        $this->unit_price = $newPrice;
        $this->total_price = $this->quantity * $newPrice;
        $this->save();

        $this->serviceOrder->recalculateTotals();

        return $this;
    }

    public function calculatePriceWithOptions(): float
    {
        $basePrice = $this->unit_price * $this->quantity;
        $optionsPrice = 0;

        if ($this->selected_options) {
            foreach ($this->selected_options as $option) {
                if (isset($option['price_modifier'])) {
                    $optionsPrice += $option['price_modifier'];
                }
            }
        }

        return $basePrice + $optionsPrice;
    }

    public function getFieldValue(string $fieldCode): mixed
    {
        if (!$this->field_values) {
            return null;
        }

        return $this->field_values[$fieldCode] ?? null;
    }

    public function setFieldValue(string $fieldCode, mixed $value): self
    {
        $fieldValues = $this->field_values ?? [];
        $fieldValues[$fieldCode] = $value;
        $this->field_values = $fieldValues;
        $this->save();

        return $this;
    }

    public function canBeModified(): bool
    {
        return in_array($this->status, ['pending', 'confirmed']);
    }

    public function isOverdue(): bool
    {
        if (!$this->scheduled_date) {
            return false;
        }

        return $this->scheduled_date->isPast() && !in_array($this->status, ['completed', 'cancelled']);
    }

    public function getEstimatedCompletionTime(): ?string
    {
        if (!$this->scheduled_date || !$this->estimated_duration) {
            return null;
        }

        $startTime = $this->scheduled_time ?? $this->scheduled_date->setTime(9, 0); // Default to 9 AM
        return $startTime->addMinutes($this->estimated_duration)->format('Y-m-d H:i:s');
    }

    // Accessors
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'warning',
            'confirmed' => 'info',
            'in_progress' => 'primary',
            'completed' => 'success',
            'cancelled' => 'secondary',
            default => 'secondary',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => 'Unknown',
        };
    }

    public function getScheduledDateTimeAttribute(): ?string
    {
        if (!$this->scheduled_date) {
            return null;
        }

        $datetime = $this->scheduled_date;

        if ($this->scheduled_time) {
            $datetime = $datetime->setTimeFrom($this->scheduled_time);
        }

        return $datetime->format('Y-m-d H:i:s');
    }

    public function getDurationLabelAttribute(): string
    {
        if (!$this->estimated_duration) {
            return 'Not specified';
        }

        $hours = floor($this->estimated_duration / 60);
        $minutes = $this->estimated_duration % 60;

        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm';
        }

        return $minutes . ' minutes';
    }

    public function getSelectedOptionsSummaryAttribute(): array
    {
        if (!$this->selected_options) {
            return [];
        }

        return collect($this->selected_options)->map(function ($option) {
            return [
                'label' => $option['label'] ?? 'Unknown',
                'price_modifier' => $option['price_modifier'] ?? 0,
            ];
        })->toArray();
    }

    // Static methods
    public static function createFromService(Service $service, array $options = []): self
    {
        $quantity = $options['quantity'] ?? 1;
        $unitPrice = $options['unit_price'] ?? $service->calculatePrice($quantity, $options['selected_options'] ?? []);

        return static::create([
            'service_id' => $service->id,
            'service_name' => $service->name,
            'service_code' => $service->service_code,
            'service_description' => $service->description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'base_price' => $service->base_price,
            'total_price' => $quantity * $unitPrice,
            'selected_options' => $options['selected_options'] ?? [],
            'field_values' => $options['field_values'] ?? [],
            'customizations' => $options['customizations'] ?? [],
            'scheduled_date' => $options['scheduled_date'] ?? null,
            'scheduled_time' => $options['scheduled_time'] ?? null,
            'estimated_duration' => $service->estimated_duration,
            'special_instructions' => $options['special_instructions'] ?? null,
            'customer_notes' => $options['customer_notes'] ?? null,
        ]);
    }
}
