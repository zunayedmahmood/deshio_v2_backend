<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReservedProduct extends Model
{
    protected $fillable = [
        'product_id',
        'total_inventory',
        'reserved_inventory',
        'available_inventory',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
