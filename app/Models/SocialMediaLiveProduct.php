<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMediaLiveProduct extends Model
{
    protected $fillable = [
        'product_id',
        'sort_order',
        'is_displaying_now',
    ];

    protected $casts = [
        'is_displaying_now' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
