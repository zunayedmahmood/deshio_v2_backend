<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBarcode;
use App\Models\ProductBarcodeRelabel;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FloatingBarcodeRelabelService
{
    /**
     * Statuses that may still be used as a sellable/scanable identity.
     */
    public const SELLABLE_STATUSES = ['available', 'in_warehouse', 'in_shop', 'on_display'];

    /**
     * Create a floating replacement barcode for one existing physical unit.
     * This intentionally does NOT change ProductBatch::quantity.
     */
    public function createReplacement(array $data, ?int $createdBy = null): ProductBarcodeRelabel
    {
        return DB::transaction(function () use ($data, $createdBy) {
            /** @var ProductBatch $batch */
            $batch = ProductBatch::with(['product', 'store'])
                ->lockForUpdate()
                ->findOrFail($data['batch_id']);

            $productId = (int)($data['product_id'] ?? $batch->product_id);
            if ($productId !== (int)$batch->product_id) {
                throw ValidationException::withMessages([
                    'product_id' => ['Selected product does not match the selected batch.'],
                ]);
            }

            if ((int)$batch->quantity <= 0) {
                throw ValidationException::withMessages([
                    'batch_id' => ['Cannot relabel a batch with zero available stock.'],
                ]);
            }

            $storeId = $data['store_id'] ?? $batch->store_id;
            if ($storeId && (int)$storeId !== (int)$batch->store_id) {
                throw ValidationException::withMessages([
                    'store_id' => ['Replacement barcode must stay inside the same store/location as the batch.'],
                ]);
            }

            $replacementCode = trim((string)($data['barcode'] ?? '')) ?: $this->generateReplacementCode();
            if (ProductBarcode::where('barcode', $replacementCode)->exists()) {
                throw ValidationException::withMessages([
                    'barcode' => ['This barcode already exists. Please use a different temporary barcode.'],
                ]);
            }

            $currentStatus = $data['current_status'] ?? $this->inferSaleableStatus($batch, $storeId);
            $reason = $data['reason'] ?? 'lost_sticker';

            $replacementBarcode = ProductBarcode::create([
                'product_id' => $batch->product_id,
                'batch_id' => $batch->id,
                'barcode' => $replacementCode,
                'current_store_id' => $storeId,
                'current_status' => $currentStatus,
                'type' => $data['type'] ?? 'CODE128',
                'is_primary' => false,
                'is_active' => true,
                'generated_at' => now(),
                'is_defective' => false,
                'is_replacement' => true,
                'replacement_status' => 'open',
                'relabel_reason' => $reason,
                'location_updated_at' => now(),
                'location_metadata' => [
                    'relabel_status' => 'open',
                    'relabel_reason' => $reason,
                    'created_by' => $createdBy,
                    'created_without_stock_increase' => true,
                ],
                'relabel_metadata' => [
                    'batch_id' => $batch->id,
                    'store_id' => $storeId,
                    'batch_quantity_at_creation' => $batch->quantity,
                    'notes' => $data['notes'] ?? null,
                ],
            ]);

            $relabel = ProductBarcodeRelabel::create([
                'product_id' => $batch->product_id,
                'batch_id' => $batch->id,
                'store_id' => $storeId,
                'replacement_barcode_id' => $replacementBarcode->id,
                'known_original_barcode_id' => $data['known_original_barcode_id'] ?? null,
                'status' => 'open',
                'reason' => $reason,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
                'metadata' => [
                    'batch_quantity_at_creation' => $batch->quantity,
                    'rule' => 'floating replacement barcode; stock quantity unchanged',
                ],
            ]);

            return $relabel->load(['product', 'batch.store', 'store', 'replacementBarcode']);
        });
    }

    public function validateBarcodeCanBeSold(ProductBarcode $barcode, ?Order $order = null, ?int $ignoreOrderItemId = null): void
    {
        if (!$barcode->is_active) {
            throw new \Exception("Barcode {$barcode->barcode} is inactive and cannot be sold.");
        }

        if ($barcode->is_defective) {
            throw new \Exception("Barcode {$barcode->barcode} is marked as defective.");
        }

        if (!in_array($barcode->current_status, self::SELLABLE_STATUSES, true)) {
            throw new \Exception("Barcode {$barcode->barcode} is not available for sale. Current status: {$barcode->current_status}.");
        }

        if (!$barcode->batch) {
            throw new \Exception("Barcode {$barcode->barcode} is not associated with any batch.");
        }

        if ($order && $order->store_id) {
            $productName = $barcode->product->name ?? 'Product';
            if ($barcode->batch->store_id && (int)$barcode->batch->store_id !== (int)$order->store_id) {
                throw new \Exception("Product \"{$productName}\" is not available in this store.");
            }
            if ($barcode->current_store_id && (int)$barcode->current_store_id !== (int)$order->store_id) {
                throw new \Exception("Product \"{$productName}\" is not available in this store.");
            }
        }

        if ((int)$barcode->batch->quantity < 1) {
            throw new \Exception("Product batch {$barcode->batch->batch_number} has no stock available.");
        }

        if ($barcode->is_replacement) {
            $relabel = ProductBarcodeRelabel::where('replacement_barcode_id', $barcode->id)->first();
            if (!$relabel || !in_array($relabel->status, ['open', 'used'], true)) {
                throw new \Exception("Replacement barcode {$barcode->barcode} is not open for sale.");
            }
        }

        $reservedElsewhere = OrderItem::where('product_barcode_id', $barcode->id)
            ->when($ignoreOrderItemId, fn($q) => $q->where('id', '!=', $ignoreOrderItemId))
            ->whereHas('order', function ($q) {
                $q->whereNotIn('status', ['cancelled', 'delivered', 'completed', 'refunded', 'returned']);
            })
            ->exists();

        if ($reservedElsewhere) {
            throw new \Exception("Barcode {$barcode->barcode} is already attached to another open order.");
        }
    }

    /**
     * Mark a barcode sold. If it is a replacement, the relabel record becomes used.
     */
    public function markBarcodeSold(ProductBarcode $barcode, Order $order): void
    {
        $metadata = array_merge($barcode->location_metadata ?? [], [
            'sold_via' => 'order',
            'order_number' => $order->order_number,
            'order_id' => $order->id,
            'sale_date' => now()->toISOString(),
            'sold_by' => auth()->id(),
        ]);

        if ($barcode->is_replacement) {
            $metadata['replacement_sale'] = true;
            $metadata['replacement_status'] = 'used';
        }

        $barcode->update([
            'is_active' => true, // Keep historical/return traceability.
            'current_status' => 'with_customer',
            'replacement_status' => $barcode->is_replacement ? 'used' : $barcode->replacement_status,
            'location_updated_at' => now(),
            'location_metadata' => $metadata,
        ]);

        if ($barcode->is_replacement) {
            $relabel = ProductBarcodeRelabel::where('replacement_barcode_id', $barcode->id)
                ->whereIn('status', ['open', 'used'])
                ->first();

            if ($relabel) {
                $relabel->update([
                    'status' => 'used',
                    'used_at' => now(),
                    'metadata' => array_merge($relabel->metadata ?? [], [
                        'used_order_id' => $order->id,
                        'used_order_number' => $order->order_number,
                    ]),
                ]);
            }
        }
    }

    /**
     * Return a barcode from sold status to available.
     * Used when editing a confirmed order.
     */
    public function returnBarcodeFromSold(ProductBarcode $barcode, Order $order): void
    {
        $metadata = array_merge($barcode->location_metadata ?? [], [
            'returned_to_stock_via' => 'order_edit',
            'order_number' => $order->order_number,
            'order_id' => $order->id,
            'return_date' => now()->toISOString(),
            'returned_by' => auth()->id(),
        ]);

        $barcode->update([
            'current_status' => 'available', // Or should we use original status? available is safest.
            'replacement_status' => $barcode->is_replacement ? 'open' : $barcode->replacement_status,
            'location_updated_at' => now(),
            'location_metadata' => $metadata,
        ]);

        if ($barcode->is_replacement) {
            $relabel = ProductBarcodeRelabel::where('replacement_barcode_id', $barcode->id)
                ->where('status', 'used')
                ->first();

            if ($relabel) {
                $relabel->update([
                    'status' => 'open',
                    'used_at' => null,
                    'metadata' => array_merge($relabel->metadata ?? [], [
                        'returned_from_order_id' => $order->id,
                        'returned_from_order_number' => $order->order_number,
                    ]),
                ]);
            }
        }
    }

    /**
     * When a batch/location stock reaches zero, remove leftover floating scan identities.
     * This is where the unknown lost original barcode gets voided.
     */
    public function reconcilePoolAfterStockChange(ProductBatch $batch, ?Order $order = null): array
    {
        $batch->refresh();

        if ((int)$batch->quantity > 0) {
            return [
                'reconciled' => false,
                'reason' => 'stock_still_available',
                'voided_barcodes' => [],
            ];
        }

        $hasRelabelHistory = ProductBarcodeRelabel::where('batch_id', $batch->id)
            ->where('product_id', $batch->product_id)
            ->exists();

        if (!$hasRelabelHistory) {
            return [
                'reconciled' => false,
                'reason' => 'no_relabel_history_for_pool',
                'voided_barcodes' => [],
            ];
        }

        $voided = [];
        $saleableLeftovers = ProductBarcode::where('product_id', $batch->product_id)
            ->where('batch_id', $batch->id)
            ->where('current_store_id', $batch->store_id)
            ->where('is_active', true)
            ->where('is_defective', false)
            ->whereIn('current_status', self::SELLABLE_STATUSES)
            ->orderBy('is_replacement') // Prefer voiding original leftover identities first.
            ->orderBy('id')
            ->get();

        foreach ($saleableLeftovers as $leftover) {
            $oldMetadata = $leftover->location_metadata ?? [];
            $leftover->update([
                'is_active' => false,
                'current_status' => 'disposed',
                'replacement_status' => $leftover->is_replacement ? 'cancelled' : $leftover->replacement_status,
                'location_updated_at' => now(),
                'location_metadata' => array_merge($oldMetadata, [
                    'voided_by_relabel_reconciliation' => true,
                    'void_reason' => 'batch_stock_reached_zero_after_floating_relabel',
                    'order_id' => $order?->id,
                    'order_number' => $order?->order_number,
                    'voided_at' => now()->toISOString(),
                ]),
            ]);

            $voided[] = [
                'id' => $leftover->id,
                'barcode' => $leftover->barcode,
                'was_replacement' => (bool)$leftover->is_replacement,
            ];
        }

        $firstOriginal = collect($voided)->firstWhere('was_replacement', false);

        $relabels = ProductBarcodeRelabel::where('batch_id', $batch->id)
            ->where('product_id', $batch->product_id)
            ->where(function ($q) use ($batch) {
                $q->where('store_id', $batch->store_id)->orWhereNull('store_id');
            })
            ->whereIn('status', ['open', 'used'])
            ->get();

        foreach ($relabels as $relabel) {
            $relabel->update([
                'status' => 'reconciled',
                'reconciled_at' => now(),
                'reconciled_original_barcode_id' => $firstOriginal['id'] ?? $relabel->reconciled_original_barcode_id,
                'metadata' => array_merge($relabel->metadata ?? [], [
                    'reconciled_order_id' => $order?->id,
                    'reconciled_order_number' => $order?->order_number,
                    'voided_barcodes' => $voided,
                    'rule' => 'stock reached zero; leftover barcode identities were voided',
                ]),
            ]);
        }

        return [
            'reconciled' => count($voided) > 0,
            'reason' => 'batch_stock_zero',
            'voided_barcodes' => $voided,
        ];
    }

    private function inferSaleableStatus(ProductBatch $batch, ?int $storeId): string
    {
        $existing = ProductBarcode::where('product_id', $batch->product_id)
            ->where('batch_id', $batch->id)
            ->when($storeId, fn($q) => $q->where('current_store_id', $storeId))
            ->whereIn('current_status', self::SELLABLE_STATUSES)
            ->orderByRaw("CASE current_status WHEN 'in_shop' THEN 0 WHEN 'available' THEN 1 WHEN 'on_display' THEN 2 WHEN 'in_warehouse' THEN 3 ELSE 4 END")
            ->first();

        return $existing?->current_status ?: 'in_shop';
    }

    private function generateReplacementCode(): string
    {
        do {
            $code = '9' . now()->format('ymdHis') . random_int(10, 99);
        } while (ProductBarcode::where('barcode', $code)->exists());

        return $code;
    }
}
