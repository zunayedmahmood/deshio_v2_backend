<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AutoLogsActivity;

class Category extends Model
{
    use HasFactory, SoftDeletes, AutoLogsActivity;

    protected $fillable = [
        'title',
        'description',
        'image',
        'color',
        'icon',
        'slug',
        'order',
        'is_active',
        'parent_id',
        'level',
        'path',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
        'level' => 'integer',
    ];

    protected $appends = [
        'image_url',
    ];

    // Boot method to auto-update level and path
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if ($category->parent_id) {
                $parent = static::find($category->parent_id);
                if ($parent) {
                    $category->level = $parent->level + 1;
                    $category->path = $parent->path ? $parent->path . '/' . $parent->id : $parent->id;
                }
            } else {
                $category->level = 0;
                $category->path = null;
            }
        });

        static::updating(function ($category) {
            if ($category->isDirty('parent_id')) {
                if ($category->parent_id) {
                    $parent = static::find($category->parent_id);
                    if ($parent) {
                        $category->level = $parent->level + 1;
                        $category->path = $parent->path ? $parent->path . '/' . $parent->id : $parent->id;
                    }
                } else {
                    $category->level = 0;
                    $category->path = null;
                }

                // Update all children's level and path
                $category->updateChildrenHierarchy();
            }
        });
    }

    // Relationships
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function allChildren()
    {
        return $this->children()->with('allChildren');
    }

    public function ancestors()
    {
        $ancestors = collect();
        $parent = $this->parent;

        while ($parent) {
            $ancestors->push($parent);
            $parent = $parent->parent;
        }

        return $ancestors;
    }

    public function descendants()
    {
        $descendants = collect();
        
        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->descendants());
        }

        return $descendants;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRootCategories($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeChildCategories($query)
    {
        return $query->whereNotNull('parent_id');
    }

    public function scopeByLevel($query, $level)
    {
        return $query->where('level', $level);
    }

    // Helper methods
    public function isRoot()
    {
        return is_null($this->parent_id);
    }

    public function hasChildren()
    {
        return $this->children()->count() > 0;
    }

    public function hasParent()
    {
        return !is_null($this->parent_id);
    }

    public function getFullPath()
    {
        if ($this->isRoot()) {
            return $this->title;
        }

        $path = collect($this->ancestors()->reverse())->pluck('title')->implode(' > ');
        return $path . ' > ' . $this->title;
    }

    public function updateChildrenHierarchy()
    {
        foreach ($this->children as $child) {
            $child->level = $this->level + 1;
            $child->path = $this->path ? $this->path . '/' . $this->id : $this->id;
            $child->save();
            $child->updateChildrenHierarchy();
        }
    }

    // Product relationships

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function activeProducts()
    {
        return $this->products()->active();
    }

    // Accessors

    /**
     * Get the full URL for the category image
     */
    public function getImageUrlAttribute()
    {
        if ($this->image) {
            return asset('storage/' . $this->image);
        }
        return null;
    }
}
