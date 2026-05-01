<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemCampaignCredit extends Model 
{
    protected $fillable = [
        'order_id',
        'order_item_id',
        'campaign_id',
        'sale_time',
        'credit_mode',
        'credited_qty',
        'credited_revenue',
        'credited_profit',
        'is_reversed',
        'reversed_at',
        'matched_campaigns_count'
    ];
    
    protected $casts = [
        'sale_time' => 'datetime',
        'reversed_at' => 'datetime',
        'credited_qty' => 'decimal:4',
        'credited_revenue' => 'decimal:2',
        'credited_profit' => 'decimal:2',
        'is_reversed' => 'boolean',
    ];
    
    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
    
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
    
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class, 'campaign_id');
    }
    
    // Query scopes
    public function scopeActive($query)
    {
        return $query->where('is_reversed', false);
    }
    
    public function scopeInDateRange($query, $from, $to)
    {
        return $query->whereBetween('sale_time', [$from, $to]);
    }
    
    public function scopeFullCredit($query)
    {
        return $query->where('credit_mode', 'FULL');
    }
    
    public function scopeSplitCredit($query)
    {
        return $query->where('credit_mode', 'SPLIT');
    }
    
    public function scopeByCampaign($query, int $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }
}
