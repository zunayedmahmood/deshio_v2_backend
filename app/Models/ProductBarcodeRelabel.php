<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcodeRelabel extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'batch_id',
        'store_id',
        'replacement_barcode_id',
        'known_original_barcode_id',
        'reconciled_original_barcode_id',
        'status',
        'reason',
        'notes',
        'created_by',
        'used_at',
        'reconciled_at',
        'metadata',
    ];

    protected $casts = [
        'used_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function replacementBarcode(): BelongsTo
    {
        return $this->belongsTo(ProductBarcode::class, 'replacement_barcode_id');
    }

    public function knownOriginalBarcode(): BelongsTo
    {
        return $this->belongsTo(ProductBarcode::class, 'known_original_barcode_id');
    }

    public function reconciledOriginalBarcode(): BelongsTo
    {
        return $this->belongsTo(ProductBarcode::class, 'reconciled_original_barcode_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
