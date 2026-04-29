<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'image_path',
        'alt_text',
        'is_primary',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = ['image_url'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('created_at');
    }

    public function getImageUrlAttribute()
    {
        return $this->image_path ? Storage::url($this->image_path) : null;
    }

    public function makePrimary()
    {
        // Remove primary status from other images of this product
        static::where('product_id', $this->product_id)
              ->where('id', '!=', $this->id)
              ->update(['is_primary' => false]);

        $this->update(['is_primary' => true]);

        return $this;
    }

    public function deleteImage()
    {
        if ($this->image_path && Storage::exists($this->image_path)) {
            Storage::delete($this->image_path);
        }

        return $this->delete();
    }

    public static function getPrimaryImageForProduct($productId)
    {
        return static::byProduct($productId)->primary()->active()->first();
    }

    public static function getImagesForProduct($productId, $onlyActive = true)
    {
        $query = static::byProduct($productId)->ordered();

        if ($onlyActive) {
            $query->active();
        }

        return $query->get();
    }
}