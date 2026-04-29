<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductDispatchItemBarcode extends Pivot
{
    protected $table = 'product_dispatch_item_barcodes';

    protected $fillable = [
        'product_dispatch_item_id',
        'product_barcode_id',
        'scanned_at',
        'scanned_by',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function dispatchItem(): BelongsTo
    {
        return $this->belongsTo(ProductDispatchItem::class, 'product_dispatch_item_id');
    }

    public function barcode(): BelongsTo
    {
        return $this->belongsTo(ProductBarcode::class, 'product_barcode_id');
    }

    public function scannedByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'scanned_by');
    }
}
