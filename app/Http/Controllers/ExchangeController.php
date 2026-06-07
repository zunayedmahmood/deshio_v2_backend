<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductReturn;
use App\Models\ProductBatch;
use App\Models\ProductBarcode;
use App\Models\ProductMovement;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\Employee;
use App\Models\ReservedProduct;
use App\Models\PaymentMethod;
use App\Models\OrderPayment;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Services\FloatingBarcodeRelabelService;
use App\Services\OrderBarcodeLifecycleService;
use App\Services\InventoryReservationService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ExchangeController extends Controller
{
    protected $relabelService;

    public function __construct(FloatingBarcodeRelabelService $relabelService)
    {
        $this->relabelService = $relabelService;
    }

    /**
     * Assert that the order is in a state that allows return or exchange.
     */
    protected function assertOrderCanReturnOrExchange(Order $order): void
    {
        $restrictedStatuses = ['pending', 'assigned_to_store', 'pending_assignment'];
        
        if (in_array($order->status, $restrictedStatuses, true)) {
            throw ValidationException::withMessages([
                'order_id' => ["Orders in '{$order->status}' status cannot be returned or exchanged yet. Please confirm or ship the order first."],
            ]);
        }
    }

    /**
     * Process an atomic exchange: Return items + Replacement items + Financial settlement.
     */
    public function process(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'nullable|exists:orders,id',
            'customer_id' => 'required_without:order_id|exists:customers,id',
            'exchangeAtStoreId' => 'required|exists:stores,id',
            'removedProducts' => 'required|array|min:1',
            'removedProducts.*.product_id' => 'required|exists:products,id',
            'removedProducts.*.product_batch_id' => 'required_without:removedProducts.*.order_item_id|exists:product_batches,id',
            'removedProducts.*.quantity' => 'required|integer|min:1',
            'removedProducts.*.unit_price' => 'required|numeric|min:0',
            'removedProducts.*.total_price' => 'required|numeric|min:0',
            'removedProducts.*.order_item_id' => 'nullable|exists:order_items,id',
            'removedProducts.*.barcode_id' => 'nullable|exists:product_barcodes,id',
            'removedProducts.*.product_barcode_id' => 'nullable|exists:product_barcodes,id',
            'removedProducts.*.return_reason' => 'required|string',
            'removedProducts.*.quality_check_passed' => 'required|boolean',
            
            'replacementProducts' => 'required|array|min:1',
            'replacementProducts.*.product_id' => 'required|exists:products,id',
            'replacementProducts.*.batch_id' => 'required|exists:product_batches,id',
            'replacementProducts.*.quantity' => 'required|integer|min:1',
            'replacementProducts.*.unit_price' => 'required|numeric|min:0',
            'replacementProducts.*.total_price' => 'nullable|numeric|min:0',
            'replacementProducts.*.discount_amount' => 'nullable|numeric|min:0',
            'replacementProducts.*.barcode' => 'nullable|string',
            
            'paymentRefund' => 'required|array',
            'paymentRefund.type' => 'required|in:surplus,refund,even',
            'paymentRefund.amount' => 'required|numeric|min:0',
            'paymentRefund.method' => 'nullable|string', // cash, bkash, etc (needed for surplus/refund)
            
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $employee = auth()->user();
            if (!$employee) {
                throw new \Exception('Employee authentication required');
            }

            $originalOrder = null;
            if ($request->order_id) {
                $originalOrder = Order::findOrFail($request->order_id);
                $this->assertOrderCanReturnOrExchange($originalOrder);

                $existingReturn = ProductReturn::where('order_id', $originalOrder->id)
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->first();
                if ($existingReturn) {
                    throw new \Exception("A return request (#{$existingReturn->return_number}) already exists for this order.");
                }
            }

            $storeId = $request->exchangeAtStoreId;
            $customer_id = $request->customer_id;

            if (!$customer_id && $request->order_id) {
                $order = Order::find($request->order_id);
                $customer_id = $order->customer_id;
            }

            if (!$customer_id) {
                throw new \Exception('Customer ID is required for exchange');
            }

            // --- 1. CREATE PRODUCT RETURN ---
            $returnNumber = $this->generateReturnNumber();
            $totalReturnValue = 0;
            $returnItems = [];

            foreach ($request->removedProducts as $item) {
                $orderItem = null;
                $batchId = $item['product_batch_id'] ?? null;

                if (!empty($item['order_item_id'])) {
                    $orderItem = OrderItem::findOrFail($item['order_item_id']);
                    if ($originalOrder && (int) $orderItem->order_id !== (int) $originalOrder->id) {
                        throw new \Exception("Removed item {$item['order_item_id']} does not belong to order {$originalOrder->order_number}.");
                    }
                    $batchId = $batchId ?: $orderItem->product_batch_id;
                }

                $barcodeId = $item['barcode_id'] ?? $item['product_barcode_id'] ?? null;
                $returnedBarcodeIds = [];
                $returnedBarcodes = [];
                if ($barcodeId && $orderItem && $originalOrder) {
                    $barcode = $this->getExactReturnableBarcodeForOrderItem($originalOrder, $orderItem, (int) $barcodeId, (int) $item['quantity']);
                    $batchId = $barcode->batch_id;
                    $returnedBarcodeIds = [$barcode->id];
                    $returnedBarcodes = [$barcode->barcode];
                } elseif ($barcodeId) {
                    $barcode = ProductBarcode::whereKey($barcodeId)->lockForUpdate()->firstOrFail();
                    if ((int) $barcode->product_id !== (int) $item['product_id']) {
                        throw new \Exception("Barcode {$barcode->barcode} does not match removed product.");
                    }
                    if (!in_array($barcode->current_status, ['with_customer', 'sold'], true)) {
                        throw new \Exception("Barcode {$barcode->barcode} is not currently with the customer.");
                    }
                    $batchId = $batchId ?: $barcode->batch_id;
                    $returnedBarcodeIds = [$barcode->id];
                    $returnedBarcodes = [$barcode->barcode];
                }

                if (!$batchId && empty($returnedBarcodeIds)) {
                    throw new \Exception("Product batch ID is missing for removed product: " . $item['product_id']);
                }

                $manualUnitPrice = round((float) $item['unit_price'], 2);
                $itemReturnValue = round($manualUnitPrice * (int) $item['quantity'], 2);
                $totalReturnValue += $itemReturnValue;
                $returnItems[] = [
                    'product_id' => $item['product_id'],
                    'product_batch_id' => $batchId,
                    'order_item_id' => $item['order_item_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $manualUnitPrice,
                    'manual_sold_at_price' => $manualUnitPrice,
                    'total_price' => $itemReturnValue,
                    'refundable_amount' => $itemReturnValue,
                    'return_reason' => $item['return_reason'],
                    'quality_check_passed' => $item['quality_check_passed'],
                    'returned_barcode_ids' => $returnedBarcodeIds,
                    'returned_barcodes' => $returnedBarcodes,
                ];
            }

            $productReturn = ProductReturn::create([
                'return_number' => $returnNumber,
                'order_id' => $request->order_id,
                'customer_id' => $customer_id,
                'store_id' => $storeId, // Returned to THIS store
                'return_reason' => 'other',
                'return_type' => 'customer_return',
                'internal_notes' => 'Exchange Processed',
                'status' => 'processing',
                'return_date' => now(),
                'received_date' => now(),
                'processed_date' => now(),
                'total_return_value' => $totalReturnValue,
                'total_refund_amount' => $totalReturnValue,
                'processing_fee' => 0,
                'return_items' => $returnItems,
                'quality_check_passed' => true, // Overall passed (items might differ)
                'processed_by' => $employee->id,
            ]);

            // Restore Inventory for Return
            $this->restoreInventoryForReturn($productReturn, $employee);

            // Mark Return as Completed
            $productReturn->status = 'completed';
            $productReturn->save();


            // --- 2. CREATE REPLACEMENT ORDER ---
            $orderNumber = Order::generateOrderNumber('counter');
            $replacementOrder = Order::create([
                'order_number' => $orderNumber,
                'customer_id' => $customer_id,
                'store_id' => $storeId,
                'order_type' => 'counter', // Primary for exchange
                'status' => 'pending',
                'subtotal' => 0,
                'total_amount' => 0,
                'outstanding_amount' => 0,
                'paid_amount' => 0,
                'payment_status' => 'unpaid',
                'created_by' => $employee->id,
                'order_date' => now(),
            ]);

            $subtotal = 0;
            $taxTotal = 0;
            $totalItemDiscount = 0;

            foreach ($request->replacementProducts as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $batch = ProductBatch::findOrFail($itemData['batch_id']);

                // Validate stock
                if ($batch->quantity < $itemData['quantity']) {
                    throw new \Exception("Insufficient local stock for {$product->name}. ID: {$batch->id}");
                }

                $reservedRecord = ReservedProduct::where('product_id', $product->id)->lockForUpdate()->first();
                $globalAvailable = $reservedRecord ? $reservedRecord->available_inventory : 0;
                if ($globalAvailable < $itemData['quantity']) {
                    throw new \Exception("Global stock reserved for {$product->name}");
                }

                // Handle Barcode
                $barcodeId = null;
                if (!empty($itemData['barcode'])) {
                    $barcode = ProductBarcode::where('barcode', $itemData['barcode'])
                        ->where('product_id', $product->id)
                        ->where('batch_id', $batch->id)
                        ->first();
                    
                    if (!$barcode) throw new \Exception("Barcode {$itemData['barcode']} not found");
                    
                    // Use Relabel Service to validate
                    $this->relabelService->validateBarcodeCanBeSold($barcode);
                    
                    $barcodeId = $barcode->id;
                }

                $quantity = $itemData['quantity'];
                $unitPrice = $itemData['unit_price'];
                $discount = $itemData['discount_amount'] ?? 0;
                
                $taxPercentage = $batch->tax_percentage ?? 0;
                $taxCalculation = $this->calculateTax($unitPrice, $quantity, $taxPercentage);
                $tax = $taxCalculation['total_tax'];
                
                $itemSubtotal = $quantity * $unitPrice;
                $itemTotal = $itemData['total_price'] ?? ($itemSubtotal - $discount);
                $cogs = round(($batch->cost_price ?? 0) * $quantity, 2);

                OrderItem::create([
                    'order_id' => $replacementOrder->id,
                    'product_id' => $product->id,
                    'product_batch_id' => $batch->id,
                    'product_barcode_id' => $barcodeId,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                    'cogs' => $cogs,
                    'total_amount' => $itemTotal,
                ]);

                // Stock deduction (Realtime for Counter sales)
                $batch->removeStock($quantity);
                if ($reservedRecord) {
                    app(\App\Services\InventoryReservationService::class)
                        ->release((int) $product->id, (int) $quantity);
                }

                // Update barcode status
                if ($barcodeId) {
                    $barcode = ProductBarcode::find($barcodeId);
                    
                    // Use Relabel Service to mark sold (handles relabel status)
                    $this->relabelService->markBarcodeSold($barcode, $replacementOrder);
                    
                    // Additionally update location with audit trail details
                    $barcode->updateLocation(null, 'with_customer', [
                        'order_id' => $replacementOrder->id,
                        'reference_type' => 'order',
                        'reference_id' => $replacementOrder->id,
                        'notes' => "Sold via Exchange. Order #{$replacementOrder->order_number}"
                    ], true, $employee->id);
                }

                $subtotal += $itemSubtotal;
                $taxTotal += $tax;
                $totalItemDiscount += $discount;
            }

            $taxMode = config('app.tax_mode', 'inclusive');
            if ($taxMode === 'inclusive') {
                $totalAmount = $subtotal - $totalItemDiscount;
            } else {
                $totalAmount = $subtotal + $taxTotal - $totalItemDiscount;
            }

            $replacementOrder->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'total_amount' => $totalAmount,
                'outstanding_amount' => $totalAmount,
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);


            // --- 3. FINANCIAL SETTLEMENT ---
            $exchangeBalanceUsed = round(min($totalReturnValue, $totalAmount), 2);
            $surplusDue = round(max(0, $totalAmount - $totalReturnValue), 2);
            $refundDue = round(max(0, $totalReturnValue - $totalAmount), 2);
            $settlementAmount = round((float) ($request->paymentRefund['amount'] ?? 0), 2);
            $settlementMethodCode = $request->paymentRefund['method'] ?? 'cash';

            if ($exchangeBalanceUsed > 0) {
                $exchangeMethod = $this->getOrCreatePaymentMethod('exchange_balance', 'Exchange Balance', 'other');
                $payment = OrderPayment::createPayment(
                    $replacementOrder,
                    $exchangeMethod,
                    $exchangeBalanceUsed,
                    [
                        'notes' => "Exchange Credit from Return #{$returnNumber}",
                        'payment_type' => 'exchange_balance'
                    ],
                    $employee
                );
                $payment->complete('EXC-' . $returnNumber, 'INTERNAL');
            }

            // 3b. Handle surplus: replacement is more expensive, customer pays only the difference.
            if ($surplusDue > 0) {
                if ($settlementAmount < $surplusDue) {
                    throw new \Exception("Exchange surplus payment is short by ৳" . number_format($surplusDue - $settlementAmount, 2));
                }

                $surplusPaid = round(min($settlementAmount, $surplusDue), 2);
                if ($surplusPaid > 0) {
                    $surplusMethod = $this->findPaymentMethodForExchange($settlementMethodCode);
                    $payment = OrderPayment::createPayment(
                        $replacementOrder,
                        $surplusMethod,
                        $surplusPaid,
                        [
                            'notes' => "Surplus payment for exchange",
                            'payment_type' => 'exchange_surplus'
                        ],
                        $employee
                    );
                    $payment->complete('EXC-SUR-' . $returnNumber, 'EXTERNAL');
                }
            }

            // 3c. Handle refund/credit: replacement is less expensive, store owes the difference.
            if ($refundDue > 0) {
                if (!$this->partialRefundsEnabled() && $settlementAmount + 0.01 < $refundDue) {
                    throw new \Exception("Partial refunds are disabled. Refund the full exchange difference of ৳" . number_format($refundDue, 2) . " before submitting this exchange.");
                }

                $immediateRefund = round(min($settlementAmount, $refundDue), 2);

                if ($immediateRefund > 0) {
                    $this->createExchangeRefund(
                        $productReturn,
                        $originalOrder ?: $replacementOrder,
                        $customer_id,
                        $totalReturnValue,
                        $immediateRefund,
                        $this->normalizeRefundMethod($settlementMethodCode),
                        $employee
                    );
                }
            }

            // Final Order Update
            $replacementOrder->refresh();
            $replacementOrder->updatePaymentStatus();


            // --- 4. LINK EXCHANGE & ACCOUNTING ---
            $productReturn->status_history = array_merge($productReturn->status_history ?? [], [[
                'status' => 'exchange_linked',
                'changed_at' => now()->toISOString(),
                'changed_by' => $employee->id,
                'order_id' => $replacementOrder->id,
            ]]);
            
            $productReturn->status = $productReturn->isFullyRefunded() ? 'refunded' : 'completed';
            $productReturn->save();

            // Unified Exchange Journal
            Transaction::createFromExchange($productReturn, $replacementOrder);

            DB::commit();

            $affectedProductIds = collect($request->removedProducts)
                ->pluck('product_id')
                ->merge(collect($request->replacementProducts)->pluck('product_id'))
                ->filter()
                ->unique();

            foreach ($affectedProductIds as $productId) {
                app(\App\Services\InventoryReservationService::class)->syncProduct((int) $productId);
            }

            return response()->json([
                'success' => true,
                'message' => 'Exchange processed successfully.',
                'data' => [
                    'return' => $productReturn->load(['customer', 'refunds']),
                    'order' => $replacementOrder->load('items'),
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Exchange processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Exchange failed: ' . $e->getMessage()
            ], 500);
        }
    }


    private function partialRefundsEnabled(): bool
    {
        $setting = Setting::where('key', 'allow_partial_refunds')->first();
        $value = $setting?->value;

        if (is_array($value)) {
            return (bool) ($value['enabled'] ?? false);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return (bool) ($decoded['enabled'] ?? false);
            }

            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
    }

    private function getOrCreatePaymentMethod(string $code, string $name, string $type = 'other'): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'description' => $name . ' for exchange settlement',
                'type' => $type,
                'allowed_customer_types' => ['counter', 'social_commerce', 'ecommerce'],
                'is_active' => true,
                'requires_reference' => false,
                'supports_partial' => true,
                'fixed_fee' => 0,
                'percentage_fee' => 0,
                'sort_order' => 90,
            ]
        );
    }

    private function findPaymentMethodForExchange(?string $methodCode): PaymentMethod
    {
        $methodCode = strtolower(trim((string) $methodCode));
        $mappedCode = match ($methodCode) {
            'bkash', 'bikash', 'nagad', 'rocket', 'mobile', 'mobile_banking' => 'mobile_banking',
            'card', 'cash', 'bank_transfer', 'online_banking', 'digital_wallet' => $methodCode,
            default => 'cash',
        };

        $method = PaymentMethod::where('code', $mappedCode)->first();
        if ($method) {
            return $method;
        }

        if ($mappedCode === 'cash') {
            return $this->getOrCreatePaymentMethod('cash', 'Cash', 'cash');
        }

        $fallback = PaymentMethod::where('code', 'cash')->first();
        return $fallback ?: $this->getOrCreatePaymentMethod('cash', 'Cash', 'cash');
    }

    private function normalizeRefundMethod(?string $methodCode): string
    {
        return match (strtolower(trim((string) $methodCode))) {
            'cash' => 'cash',
            'card', 'card_refund' => 'card_refund',
            'bank_transfer' => 'bank_transfer',
            'bkash', 'bikash', 'nagad', 'rocket', 'mobile_banking', 'digital_wallet' => 'digital_wallet',
            'store_credit' => 'store_credit',
            default => 'cash',
        };
    }

    private function createExchangeRefund(
        ProductReturn $productReturn,
        Order $order,
        int $customerId,
        float $originalAmount,
        float $refundAmount,
        string $refundMethod,
        $employee
    ): Refund {
        $refund = Refund::create([
            'refund_number' => 'REF-EXC-' . date('Ymd') . '-' . Str::random(4),
            'return_id' => $productReturn->id,
            'order_id' => $order->id,
            'customer_id' => $customerId,
            'refund_type' => 'exchange_refund',
            'original_amount' => $originalAmount,
            'refund_amount' => $refundAmount,
            'refund_method' => $refundMethod,
            'status' => 'completed',
            'processed_by' => $employee->id,
            'approved_by' => $employee->id,
            'completed_at' => now(),
            'store_credit_expires_at' => $refundMethod === 'store_credit' ? now()->addYear() : null,
            'internal_notes' => $refundMethod === 'store_credit'
                ? 'Store credit generated from exchange price difference.'
                : 'Immediate refund generated from exchange price difference.',
        ]);

        if ($refundMethod === 'store_credit') {
            $refund->store_credit_code = $refund->generateStoreCreditCode();
            $refund->save();
            $this->getOrCreatePaymentMethod('store_credit', 'Store Credit', 'other');
        }

        return $refund;
    }

    private function generateReturnNumber(): string
    {
        $date = now()->format('Ymd');
        $count = DB::table('product_returns')->whereDate('created_at', now())->count() + 1;
        return 'RET-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }



    private function calculateTax($unitPrice, $quantity, $taxPercentage)
    {
        $taxMode = config('app.tax_mode', 'inclusive');
        $subtotal = $unitPrice * $quantity;
        
        if ($taxMode === 'inclusive') {
            $taxAmount = $subtotal - ($subtotal / (1 + ($taxPercentage / 100)));
        } else {
            $taxAmount = $subtotal * ($taxPercentage / 100);
        }
        
        return ['total_tax' => round($taxAmount, 2)];
    }

    private function getExactReturnableBarcodeForOrderItem(Order $order, OrderItem $orderItem, int $barcodeId, int $quantity): ProductBarcode
    {
        if ($quantity !== 1) {
            throw new \Exception('Exact barcode exchanges must be submitted one unit at a time.');
        }

        $barcode = ProductBarcode::whereKey($barcodeId)->lockForUpdate()->firstOrFail();

        if ((int) $barcode->product_id !== (int) $orderItem->product_id) {
            throw new \Exception("Barcode {$barcode->barcode} does not match {$orderItem->product_name}.");
        }

        if (!empty($orderItem->product_batch_id) && (int) $barcode->batch_id !== (int) $orderItem->product_batch_id) {
            $deletedBatchId = \App\Models\BatchDeletedBarcode::where('product_barcode_id', $barcode->id)
                ->value('deleted_product_batch_id')
                ?: \App\Models\DeletedPurchaseOrderBarcode::where('product_barcode_id', $barcode->id)
                    ->value('deleted_product_batch_id');

            if ((int) $deletedBatchId !== (int) $orderItem->product_batch_id) {
                throw new \Exception("Barcode {$barcode->barcode} does not match the sold batch for {$orderItem->product_name}.");
            }
        }

        if (!in_array($barcode->current_status, ['with_customer', 'sold'], true)) {
            throw new \Exception("Barcode {$barcode->barcode} is not currently with the customer.");
        }

        if ($barcode->is_defective) {
            throw new \Exception("Barcode {$barcode->barcode} is already marked defective.");
        }

        $metadata = $barcode->location_metadata ?? [];
        $belongsToOrder = (int) ($orderItem->product_barcode_id ?? 0) === (int) $barcode->id
            || (int) ($metadata['order_id'] ?? 0) === (int) $order->id
            || (string) ($metadata['order_number'] ?? '') === (string) $order->order_number;

        if (!$belongsToOrder) {
            throw new \Exception("Barcode {$barcode->barcode} was not sold in order {$order->order_number}.");
        }

        return $barcode;
    }

    private function restoreInventoryForReturn(ProductReturn $return, Employee $employee): void
    {
        $returnStore = $return->store_id;
        $order = $return->order ?: Order::find($return->order_id);

        foreach ($return->return_items ?? [] as $item) {
            $batchId = $item['product_batch_id'] ?? null;
            $originalBatch = $batchId ? ProductBatch::find($batchId) : null;
            $barcodeIds = collect($item['returned_barcode_ids'] ?? [])->filter()->values();

            if (!$originalBatch) {
                $barcodes = $barcodeIds->isNotEmpty()
                    ? ProductBarcode::whereIn('id', $barcodeIds)->get()
                    : ProductBarcode::where('product_id', $item['product_id'])
                        ->whereIn('current_status', ['with_customer', 'sold'])
                        ->where(function ($q) use ($return, $order) {
                            $q->where('location_metadata->order_id', $return->order_id);
                            if ($order?->order_number) {
                                $q->orWhere('location_metadata->order_number', $order->order_number);
                            }
                        })
                        ->limit((int) $item['quantity'])
                        ->get();

                $priceSourceBatch = ProductBatch::where('product_id', $item['product_id'])
                    ->whereNotNull('sell_price')
                    ->orderByDesc('updated_at')
                    ->first();

                $targetBatch = ProductBatch::firstOrCreate([
                    'product_id' => $item['product_id'],
                    'store_id' => $returnStore,
                    'batch_number' => 'RTN-RESTORE-P' . (int) $item['product_id'] . '-S' . (int) $returnStore,
                ], [
                    'quantity' => 0,
                    'cost_price' => $priceSourceBatch?->cost_price ?? 0,
                    'sell_price' => $priceSourceBatch?->sell_price ?? ($item['unit_price'] ?? 0),
                    'tax_percentage' => $priceSourceBatch?->tax_percentage ?? 0,
                    'manufactured_date' => $priceSourceBatch?->manufactured_date,
                    'expiry_date' => $priceSourceBatch?->expiry_date,
                    'availability' => true,
                    'is_active' => true,
                    'notes' => 'Auto-created when an exchanged returned barcode had no live batch to rejoin.',
                ]);

                $targetBatch->forceFill([
                    'quantity' => max((int) $targetBatch->quantity + (int) $item['quantity'], 1),
                    'availability' => true,
                    'is_active' => true,
                ])->save();

                foreach ($barcodes as $barcode) {
                    $oldStatus = $barcode->current_status;

                    if ($order) {
                        $this->relabelService->returnBarcodeFromSold($barcode, $order);
                        $barcode->refresh();
                    } else {
                        \App\Models\DeletedPurchaseOrderBarcode::where('product_barcode_id', $barcode->id)->delete();
                        \App\Models\BatchDeletedBarcode::where('product_barcode_id', $barcode->id)->delete();
                    }

                    \App\Models\DeletedPurchaseOrderBarcode::where('product_barcode_id', $barcode->id)->delete();
                    \App\Models\BatchDeletedBarcode::where('product_barcode_id', $barcode->id)->delete();

                    $barcode->update([
                        'batch_id'            => $targetBatch->id,
                        'product_id'          => $targetBatch->product_id,
                        'is_active'           => true,
                        'is_defective'        => false,
                        'current_store_id'    => $returnStore,
                        'current_status'      => 'available',
                        'location_updated_at' => now(),
                        'location_metadata'   => array_merge($barcode->location_metadata ?? [], [
                            'return_id' => $return->id,
                            'reference_type' => 'return',
                            'reference_id' => $return->id,
                            'notes' => "Customer Return via Exchange. Reason: " . ($item['return_reason'] ?? 'N/A'),
                            'previous_status' => $oldStatus,
                            'po_deleted_before_return' => true,
                            'batch_deleted_before_return' => true,
                            'deleted_purchase_order_reference_cleared' => true,
                            'batch_deleted_reference_cleared' => true,
                            'restored_batch_id' => $targetBatch->id,
                        ]),
                    ]);


                    app(InventoryReservationService::class)->restoreReturnedBarcodeToSellableBatch(
                        $barcode->fresh(),
                        (int) $returnStore,
                        $targetBatch,
                        [
                            'restore_source' => 'exchange_return_deleted_batch',
                            'return_id' => $return->id,
                            'return_number' => $return->return_number,
                            'order_id' => $return->order_id,
                        ]
                    );

                    ProductMovement::create([
                        'product_id' => $item['product_id'],
                        'product_batch_id' => $targetBatch->id,
                        'product_barcode_id' => $barcode->id,
                        'to_store_id' => $returnStore,
                        'movement_type' => 'return',
                        'quantity' => 1,
                        'unit_cost' => $targetBatch->cost_price ?? 0,
                        'total_cost' => $targetBatch->cost_price ?? 0,
                        'reference_type' => 'return',
                        'reference_id' => $return->id,
                        'status_before' => $oldStatus,
                        'status_after' => 'available',
                        'notes' => "Exchange return restored into rescue batch #{$return->return_number}",
                        'performed_by' => $employee->id,
                    ]);

                    Log::info('Barcode restored after exchange return without original batch', [
                        'barcode_id' => $barcode->id,
                        'barcode' => $barcode->barcode,
                        'return_id' => $return->id,
                        'old_status' => $oldStatus,
                        'store_id' => $returnStore,
                        'restored_batch_id' => $targetBatch->id,
                    ]);

                    app(OrderBarcodeLifecycleService::class)->detachBarcodeFromOrderItems(
                        $barcode,
                        $return->order_id,
                        'exchange_return_restored_deleted_po',
                        [
                            'return_id' => $return->id,
                            'return_number' => $return->return_number,
                        ]
                    );
                }

                app(InventoryReservationService::class)->syncProduct((int) $item['product_id']);
                continue;
            }

            // In lookup page exchange, we usually restore to the current store
            $targetBatch = null;
            if ((int) $originalBatch->store_id === (int) $returnStore) {
                $targetBatch = $originalBatch;
            } else {
                // Find or create batch at this store
                $targetBatch = ProductBatch::firstOrCreate([
                    'product_id' => $item['product_id'],
                    'store_id' => $returnStore,
                    'batch_number' => $originalBatch->batch_number, // Try same batch number
                ], [
                    'quantity' => 0,
                    'cost_price' => $originalBatch->cost_price,
                    'sell_price' => $originalBatch->sell_price,
                    'tax_percentage' => $originalBatch->tax_percentage,
                    'availability' => true,
                    'is_active' => true,
                ]);
            }

            $targetBatch->increment('quantity', (int) $item['quantity']);
            $targetBatch->forceFill([
                'availability' => true,
                'is_active' => true,
            ])->save();

            if ($barcodeIds->isEmpty()) {
                // Try to find barcodes that were sold from this order/item
                $barcodes = ProductBarcode::where('product_id', $item['product_id'])
                    ->where('batch_id', $item['product_batch_id'])
                    ->whereIn('current_status', ['with_customer', 'sold'])
                    ->limit((int) $item['quantity'])
                    ->get();
            } else {
                $barcodes = ProductBarcode::whereIn('id', $barcodeIds)->get();
            }

            if ($barcodes->isNotEmpty()) {
                foreach ($barcodes as $barcode) {
                    $oldStatus = $barcode->current_status;

                    if ($order) {
                        $this->relabelService->returnBarcodeFromSold($barcode, $order);
                        $barcode->refresh();
                    }

                    \App\Models\DeletedPurchaseOrderBarcode::where('product_barcode_id', $barcode->id)->delete();
                    \App\Models\BatchDeletedBarcode::where('product_barcode_id', $barcode->id)->delete();

                    // Use a single atomic update to restore ALL barcode fields at once.
                    $barcode->update([
                        'batch_id'           => $targetBatch->id,
                        'product_id'         => $targetBatch->product_id,
                        'is_active'          => true,
                        'is_defective'       => false,
                        'current_store_id'   => $returnStore,
                        'current_status'     => 'available',
                        'location_updated_at' => now(),
                        'location_metadata'   => array_merge($barcode->location_metadata ?? [], [
                            'return_id' => $return->id,
                            'reference_type' => 'return',
                            'reference_id' => $return->id,
                            'notes' => "Customer Return via Exchange. Reason: " . ($item['return_reason'] ?? 'N/A'),
                            'previous_status' => $oldStatus,
                        ]),
                    ]);


                    app(InventoryReservationService::class)->restoreReturnedBarcodeToSellableBatch(
                        $barcode->fresh(),
                        (int) $returnStore,
                        $targetBatch,
                        [
                            'restore_source' => 'exchange_return_original_batch',
                            'return_id' => $return->id,
                            'return_number' => $return->return_number,
                            'order_id' => $return->order_id,
                        ]
                    );

                    // Create movement record for audit trail
                    ProductMovement::create([
                        'product_id' => $item['product_id'],
                        'product_batch_id' => $targetBatch->id,
                        'product_barcode_id' => $barcode->id,
                        'to_store_id' => $returnStore,
                        'movement_type' => 'return',
                        'quantity' => 1,
                        'unit_cost' => $originalBatch->cost_price,
                        'total_cost' => $originalBatch->cost_price,
                        'reference_type' => 'return',
                        'reference_id' => $return->id,
                        'status_before' => $oldStatus,
                        'status_after' => 'available',
                        'notes' => "Customer Return via Exchange #{$return->return_number}",
                        'performed_by' => $employee->id,
                    ]);

                    Log::info('Barcode restored after exchange return', [
                        'barcode_id'  => $barcode->id,
                        'barcode'     => $barcode->barcode,
                        'return_id'   => $return->id,
                        'old_status'  => $oldStatus,
                        'new_status'  => $barcode->current_status,
                        'is_active'   => $barcode->is_active,
                        'store_id'    => $returnStore,
                    ]);

                    app(OrderBarcodeLifecycleService::class)->detachBarcodeFromOrderItems(
                        $barcode,
                        $return->order_id,
                        'exchange_return_restored',
                        [
                            'return_id' => $return->id,
                            'return_number' => $return->return_number,
                        ]
                    );
                }
            } else {
                // Fallback for products that might not have barcodes (if any)
                // BUT we still need to record a movement.
                // If the DB requires product_barcode_id, we have a problem.
                // However, in this system, most items should have barcodes.
                // If it really has no barcodes, we might need a placeholder or the DB column should be nullable.
                
                // For now, let's log if no barcodes found to help debugging
                if ($item['quantity'] > 0) {
                    Log::warning("No barcodes found for return item", ['product_id' => $item['product_id'], 'batch_id' => $item['product_batch_id'] ?? null]);
                }
                
                // If we absolutely must record it and DB requires barcode_id:
                // We'll throw an exception or try to create a movement without it (which will fail if strict)
                ProductMovement::create([
                    'product_id' => $item['product_id'],
                    'product_batch_id' => $targetBatch->id,
                    // 'product_barcode_id' => null, // This will fail if DB constraint is strict
                    'to_store_id' => $returnStore,
                    'movement_type' => 'return',
                    'quantity' => $item['quantity'],
                    'unit_cost' => $originalBatch->cost_price,
                    'total_cost' => $originalBatch->cost_price * $item['quantity'],
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'performed_by' => $employee->id,
                ]);
            }

            app(InventoryReservationService::class)->syncProduct((int) $item['product_id']);
        }
    }

}
