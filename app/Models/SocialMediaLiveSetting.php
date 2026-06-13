<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialMediaLiveSetting extends Model
{
    protected $fillable = [
        'is_live',
        'displaying_now_enabled',
    ];

    protected $casts = [
        'is_live' => 'boolean',
        'displaying_now_enabled' => 'boolean',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'is_live' => false,
            'displaying_now_enabled' => false,
        ]);
    }
}
