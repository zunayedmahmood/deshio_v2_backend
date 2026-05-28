<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeletedPurchaseOrderBarcode extends Model
{
    use HasFactory;

    protected $fillable = [
        'deleted_purchase_order_id',
        'product_barcode_id',
        'deleted_product_batch_id',
        'deleted_po_number',
        'deleted_batch_number',
        'product_id',
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
