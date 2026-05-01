<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCampaign extends Model 
{
    protected $fillable = [
        'name',
        'platform',
        'status',
        'starts_at',
        'ends_at',
        'budget_type',
        'budget_amount',
        'notes',
        'created_by',
        'updated_by'
    ];
    
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'budget_amount' => 'decimal:2',
    ];
    
    // Relationships
    public function targetedProducts(): HasMany
    {
        return $this->hasMany(AdCampaignProduct::class, 'campaign_id');
    }
    
    public function credits(): HasMany
    {
        return $this->hasMany(OrderItemCampaignCredit::class, 'campaign_id');
    }
    
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
    
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }
    
    // Business logic methods
    public function isActiveAt(\DateTime $time): bool
    {
        return $this->status === 'RUNNING'
            && $this->starts_at <= $time
            && ($this->ends_at === null || $this->ends_at >= $time);
    }
    
    public function canTransitionTo(string $newStatus): bool
    {
        $transitions = [
            'DRAFT' => ['RUNNING'],
            'RUNNING' => ['PAUSED', 'ENDED'],
            'PAUSED' => ['RUNNING', 'ENDED'],
            'ENDED' => [], // Terminal state
        ];
        
        return in_array($newStatus, $transitions[$this->status] ?? []);
    }
    
    // Query scopes
    public function scopeActiveAt($query, \DateTime $time)
    {
        return $query->where('status', 'RUNNING')
            ->where('starts_at', '<=', $time)
            ->where(function($q) use ($time) {
                $q->whereNull('ends_at')
                  ->orWhere('ends_at', '>=', $time);
            });
    }
    
    public function scopeRunning($query)
    {
        return $query->where('status', 'RUNNING');
    }
    
    public function scopePlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
