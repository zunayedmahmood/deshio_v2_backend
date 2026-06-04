<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchDeletedBarcode extends Model
{
    use HasFactory;

    protected $table = 'batch_deleted_barcodes';

    protected $fillable = [
        'product_barcode_id',
        'deleted_product_batch_id',
        'deleted_batch_number',
        'product_id',
        'store_id',
        'store_name',
        'purchase_order_id',
        'purchase_order_number',
        'deleted_by',
        'deleted_at',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function barcode(): BelongsTo
    {
        return $this->belongsTo(ProductBarcode::class, 'product_barcode_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
