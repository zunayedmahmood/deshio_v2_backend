<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Support\MediaUrl;

class HomepageSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'title',
        'subtitle',
        'image',
        'link_url',
        'button_text',
        'settings',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        return MediaUrl::toPublicUrl($this->image);
    }
}
