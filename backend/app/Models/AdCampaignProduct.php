<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdCampaignProduct extends Model 
{
    protected $fillable = [
        'campaign_id',
        'product_id',
        'effective_from',
        'effective_to',
        'created_by'
    ];
    
    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];
    
    // Relationships
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'campaign_id');
    }
    
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
    
    // Business logic
    public function isEffectiveAt(\DateTime $time): bool
    {
        return $this->effective_from <= $time
            && ($this->effective_to === null || $this->effective_to >= $time);
    }
    
    public function deactivate(): void
    {
        $this->effective_to = now();
        $this->save();
    }
    
    // Query scopes
    public function scopeEffectiveAt($query, \DateTime $time)
    {
        return $query->where('effective_from', '<=', $time)
            ->where(function($q) use ($time) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $time);
            });
    }
    
    public function scopeActive($query)
    {
        return $query->whereNull('effective_to');
    }
}
