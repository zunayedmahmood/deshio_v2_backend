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
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Services\FloatingBarcodeRelabelService;

class ExchangeController extends Controller
{
    protected FloatingBarcodeRelabelService $relabelService;

    public function __construct(FloatingBarcodeRelabelService $relabelService)
    {
        $this->relabelService = $relabelService;
    }

    private const BLOCKED_RETURN_EXCHANGE_STATUSES = [
        'pending',
        'assigned_to_store',
        'pending_assignment',
    ];

    /**
     * Process an atomic exchange: Return items + Replacement items + Financial settlement.
     */
    public function process(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'customer_id' => 'required|exists:customers,id',
            'exchangeAtStoreId' => 'required|exists:stores,id',
            'removedProducts' => 'required|array|min:1',
            'removedProducts.*.product_id' => 'required|exists:products,id',
            'removedProducts.*.product_batch_id' => 'nullable|exists:product_batches,id',
            'removedProducts.*.batch_id' => 'nullable|exists:product_batches,id',
            'removedProducts.*.quantity' => 'required|integer|min:1',
            'removedProducts.*.unit_price' => 'required|numeric|min:0',
            'removedProducts.*.total_price' => 'nullable|numeric|min:0',
            'removedProducts.*.order_item_id' => 'nullable|exists:order_items,id',
            'removedProducts.*.barcode' => 'nullable|string',
            'removedProducts.*.barcode_id' => 'nullable|exists:product_barcodes,id',
            'removedProducts.*.product_barcode_id' => 'nullable|exists:product_barcodes,id',
            'removedProducts.*.return_reason' => 'required|string',
            'removedProducts.*.quality_check_passed' => 'required|boolean',

            'replacementProducts' => 'required|array|min:1',
            'replacementProducts.*.product_id' => 'required|exists:products,id',
            'replacementProducts.*.batch_id' => 'nullable|exists:product_batches,id',
            'replacementProducts.*.barcode_id' => 'nullable|exists:product_barcodes,id',
            'replacementProducts.*.quantity' => 'required|integer|min:1',
            'replacementProducts.*.unit_price' => 'required|numeric|min:0',
            'replacementProducts.*.total_price' => 'nullable|numeric|min:0',
            'replacementProducts.*.discount_amount' => 'nullable|numeric|min:0',
            'replacementProducts.*.barcode' => 'nullable|string',

            'paymentRefund' => 'required|array',
            'paymentRefund.type' => 'required|in:surplus,refund,even',
            'paymentRefund.amount' => 'required|numeric|min:0',
            'paymentRefund.method' => 'nullable|string',
            'paymentRefund.details' => 'nullable|array',

            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $employee = auth()->user();
            if (!$employee) {
                throw new \Exception('Employee authentication required');
            }

            $storeId = (int) $request->exchangeAtStoreId;
            $customerId = (int) $request->customer_id;

            $originalOrder = Order::with(['items', 'customer'])->lockForUpdate()->findOrFail($request->order_id);
            $this->assertOrderCanReturnOrExchange($originalOrder, 'Exchange');

            if ((int) $originalOrder->customer_id !== $customerId) {
                throw new \Exception('Exchange customer does not match the original order customer.');
            }

            // --- 1. CREATE PRODUCT RETURN ---
            $returnNumber = $this->generateReturnNumber();
            $totalReturnValue = 0;
            $returnItems = [];

            foreach ($request->removedProducts as $item) {
                $orderItem = null;
                if (!empty($item['order_item_id'])) {
                    $orderItem = OrderItem::where('order_id', $originalOrder->id)
                        ->where('id', $item['order_item_id'])
                        ->lockForUpdate()
                        ->first();

                    if (!$orderItem) {
                        throw new \Exception("Returned item {$item['order_item_id']} does not belong to order {$originalOrder->order_number}.");
                    }
                }

                $batchId = $item['product_batch_id'] ?? $item['batch_id'] ?? $orderItem?->product_batch_id;
                $barcode = $this->resolveReturnedBarcode($item, $originalOrder, $orderItem);

                if ($barcode && !$batchId) {
                    $batchId = $barcode->batch_id;
                }

                if (!$batchId) {
                    throw new \Exception("Product batch ID could not be resolved for returned item. Product ID: {$item['product_id']}");
                }

                $quantity = (int) $item['quantity'];
                $alreadyReturned = $orderItem ? $this->getReturnedQuantity($orderItem->id) : 0;
                if ($orderItem && ($alreadyReturned + $quantity) > (int) $orderItem->quantity) {
                    $available = max(0, (int) $orderItem->quantity - $alreadyReturned);
                    throw new \Exception("Cannot exchange {$quantity} unit(s) of {$orderItem->product_name}. Only {$available} unit(s) remain returnable.");
                }

                // Barcode-managed sold items must come back with the exact barcode/physical unit.
                if ($orderItem && !empty($orderItem->product_barcode_id) && !$barcode) {
                    throw new \Exception("Barcode is required to exchange {$orderItem->product_name} because it was sold as a tracked unit.");
                }

                $itemTotal = isset($item['total_price'])
                    ? (float) $item['total_price']
                    : ($quantity * (float) $item['unit_price']);
                $totalReturnValue += $itemTotal;

                $returnItems[] = [
                    'product_id' => $item['product_id'],
                    'product_batch_id' => $batchId,
                    'order_item_id' => $orderItem?->id ?? $item['order_item_id'] ?? null,
                    'product_name' => $orderItem?->product_name,
                    'quantity' => $quantity,
                    'unit_price' => (float) $item['unit_price'],
                    'total_price' => $itemTotal,
                    'refundable_amount' => $itemTotal,
                    'return_reason' => $item['return_reason'],
                    'quality_check_passed' => (bool) $item['quality_check_passed'],
                    'barcode_id' => $barcode?->id,
                    'returned_barcode_ids' => $barcode ? [$barcode->id] : [],
                    'returned_barcodes' => $barcode ? [$barcode->barcode] : [],
                ];
            }

            $productReturn = ProductReturn::create([
                'return_number' => $returnNumber,
                'order_id' => $originalOrder->id,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'received_at_store_id' => $storeId,
                'return_reason' => 'other',
                'return_type' => 'customer_return',
                'status' => 'processing',
                'return_date' => now(),
                'received_date' => now(),
                'processed_date' => now(),
                'total_return_value' => $totalReturnValue,
                'total_refund_amount' => $totalReturnValue,
                'processing_fee' => 0,
                'return_items' => $returnItems,
                'quality_check_passed' => true,
                'processed_by' => $employee->id,
                'internal_notes' => $request->notes,
            ]);

            $this->restoreInventoryForReturn($productReturn, $employee);
            $productReturn->status = 'completed';
            $productReturn->save();

            // --- 2. CREATE REPLACEMENT ORDER ---
            $orderNumber = $this->generateOrderNumber();
            $replacementOrder = Order::create([
                'order_number' => $orderNumber,
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'order_type' => 'counter',
                'status' => 'pending',
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 0,
                'outstanding_amount' => 0,
                'paid_amount' => 0,
                'payment_status' => 'pending',
                'created_by' => $employee->id,
                'order_date' => now(),
                'notes' => "Exchange replacement for order #{$originalOrder->order_number}",
                'metadata' => [
                    'is_exchange_replacement' => true,
                    'original_order_id' => $originalOrder->id,
                    'original_order_number' => $originalOrder->order_number,
                    'product_return_id' => $productReturn->id,
                    'return_number' => $returnNumber,
                    'reporting_note' => 'Use net exchange difference for cash reporting; replacement value is settled by exchange balance.',
                ],
            ]);

            $subtotal = 0;
            $taxTotal = 0;
            $totalItemDiscount = 0;

            foreach ($request->replacementProducts as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $quantity = (int) $itemData['quantity'];
                $unitPrice = (float) $itemData['unit_price'];
                $discount = (float) ($itemData['discount_amount'] ?? 0);

                $scannedBarcode = $this->resolveReplacementBarcode($itemData, $product->id, $storeId);
                $batchId = $itemData['batch_id'] ?? $scannedBarcode?->batch_id;

                if (!$batchId) {
                    throw new \Exception("Batch ID or barcode is required for replacement product {$product->name}.");
                }

                $batch = ProductBatch::where('id', $batchId)->lockForUpdate()->firstOrFail();
                if ((int) $batch->product_id !== (int) $product->id) {
                    throw new \Exception("Selected batch does not belong to replacement product {$product->name}.");
                }
                if ((int) $batch->store_id !== $storeId) {
                    throw new \Exception("Replacement product {$product->name} must be taken from the selected exchange store.");
                }
                if ((int) $batch->quantity < $quantity) {
                    throw new \Exception("Insufficient stock for {$product->name} at selected exchange store. Available: {$batch->quantity}, needed: {$quantity}.");
                }

                if ($scannedBarcode) {
                    if ((int) $scannedBarcode->batch_id !== (int) $batch->id) {
                        throw new \Exception("Barcode {$scannedBarcode->barcode} does not belong to the selected replacement batch.");
                    }
                    if (!$scannedBarcode->isAvailableForSale()) {
                        throw new \Exception("Barcode {$scannedBarcode->barcode} is not available for replacement sale.");
                    }

                    // Deshio-specific: temporary/floating relabel barcodes need an extra
                    // integrity check before they can be sold.
                    $this->relabelService->validateBarcodeCanBeSold($scannedBarcode);
                }

                $taxPercentage = $batch->tax_percentage ?? 0;
                $taxCalculation = $this->calculateTax($unitPrice, $quantity, $taxPercentage);
                $tax = $taxCalculation['total_tax'];
                $itemSubtotal = $quantity * $unitPrice;
                $itemTotal = isset($itemData['total_price']) ? (float) $itemData['total_price'] : ($itemSubtotal - $discount);
                $cogs = round(((float) ($batch->cost_price ?? 0)) * $quantity, 2);

                OrderItem::create([
                    'order_id' => $replacementOrder->id,
                    'product_id' => $product->id,
                    'product_batch_id' => $batch->id,
                    'product_barcode_id' => $scannedBarcode?->id,
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                    'cogs' => $cogs,
                    'total_amount' => $itemTotal,
                ]);

                $batch->removeStock($quantity);
                $this->syncReservedProductAfterExchangeSale($product->id, $quantity);

                if ($scannedBarcode) {
                    // Deshio supports floating/replacement barcodes. Mark through the
                    // relabel service first so replacement_status/relabel history stays correct.
                    $this->relabelService->markBarcodeSold($scannedBarcode, $replacementOrder);

                    $scannedBarcode->updateLocation(null, 'with_customer', [
                        'order_id' => $replacementOrder->id,
                        'order_number' => $replacementOrder->order_number,
                        'customer_id' => $customerId,
                        'exchange_return_id' => $productReturn->id,
                        'reference_type' => 'exchange_replacement',
                        'reference_id' => $replacementOrder->id,
                        'notes' => "Sold via exchange replacement. Order #{$replacementOrder->order_number}",
                        'performed_by' => $employee->id,
                    ], true, $employee->id);

                    // Do not deactivate the barcode here. Deshio keeps sold/replacement
                    // barcodes active for return/exchange traceability.
                }

                ProductMovement::create([
                    'product_id' => $product->id,
                    'product_batch_id' => $batch->id,
                    'product_barcode_id' => $scannedBarcode?->id,
                    'from_store_id' => $storeId,
                    'movement_type' => 'sale',
                    'quantity' => $quantity,
                    'unit_cost' => $batch->cost_price ?? 0,
                    'unit_price' => $unitPrice,
                    'total_cost' => ($batch->cost_price ?? 0) * $quantity,
                    'total_value' => $itemTotal,
                    'reference_type' => 'exchange_replacement',
                    'reference_id' => $replacementOrder->id,
                    'notes' => "Exchange replacement for return #{$returnNumber}",
                    'performed_by' => $employee->id,
                ]);

                $subtotal += $itemSubtotal;
                $taxTotal += $tax;
                $totalItemDiscount += $discount;
            }

            $taxMode = config('app.tax_mode', 'inclusive');
            $totalAmount = $taxMode === 'inclusive'
                ? ($subtotal - $totalItemDiscount)
                : ($subtotal + $taxTotal - $totalItemDiscount);

            $replacementOrder->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'discount_amount' => $totalItemDiscount,
                'total_amount' => $totalAmount,
                'outstanding_amount' => $totalAmount,
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            // --- 3. FINANCIAL SETTLEMENT ---
            $exchangeBalanceUsed = min($totalReturnValue, $totalAmount);
            $difference = round($totalAmount - $totalReturnValue, 2);
            $requestedSettlementAmount = (float) ($request->paymentRefund['amount'] ?? 0);

            $exchangeMethod = PaymentMethod::where('code', 'exchange_balance')->first()
                ?? PaymentMethod::where('code', 'other')->first()
                ?? PaymentMethod::first();

            if (!$exchangeMethod) {
                throw new \Exception('No valid payment method found for exchange settlement.');
            }

            if ($exchangeBalanceUsed > 0) {
                $payment = OrderPayment::createPayment(
                    $replacementOrder,
                    $exchangeMethod,
                    $exchangeBalanceUsed,
                    [
                        'notes' => "Exchange credit from return #{$returnNumber}",
                        'payment_type' => 'exchange_balance',
                        'exchange_return_id' => $productReturn->id,
                    ],
                    $employee
                );
                $payment->payment_type = 'exchange_balance';
                $payment->notes = "Exchange credit from return #{$returnNumber}";
                $payment->save();
                $payment->process($employee);
                $payment->complete('EXC-' . $returnNumber, 'INTERNAL');
            }

            $surplusPaid = 0;
            $refundPaid = 0;

            if ($difference > 0) {
                $surplusDue = $difference;
                $surplusPaid = min($requestedSettlementAmount, $surplusDue);

                if ($surplusPaid > 0) {
                    $methodCode = $this->normalizePaymentMethodCode($request->paymentRefund['method'] ?? 'cash');
                    $surplusMethod = PaymentMethod::where('code', $methodCode)->first()
                        ?? PaymentMethod::where('code', 'cash')->first()
                        ?? PaymentMethod::where('code', 'other')->first()
                        ?? PaymentMethod::first();

                    if (!$surplusMethod) {
                        throw new \Exception('No valid payment method found for surplus payment.');
                    }

                    $payment = OrderPayment::createPayment(
                        $replacementOrder,
                        $surplusMethod,
                        $surplusPaid,
                        [
                            'notes' => 'Extra payment collected for exchange upgrade',
                            'payment_type' => 'exchange_surplus',
                            'exchange_return_id' => $productReturn->id,
                            'details' => $request->paymentRefund['details'] ?? [],
                        ],
                        $employee
                    );
                    $payment->payment_type = 'exchange_surplus';
                    $payment->notes = 'Extra payment collected for exchange upgrade';
                    $payment->save();
                    $payment->process($employee);
                    $payment->complete('EXC-SUR-' . $returnNumber, 'EXTERNAL');
                }
            } elseif ($difference < 0) {
                $refundDue = abs($difference);
                $refundPaid = min($requestedSettlementAmount, $refundDue);

                if ($refundPaid > 0) {
                    Refund::create([
                        'refund_number' => 'REF-EXC-' . date('Ymd') . '-' . Str::random(4),
                        'return_id' => $productReturn->id,
                        'order_id' => $originalOrder->id,
                        'customer_id' => $customerId,
                        'refund_type' => 'exchange_refund',
                        'original_amount' => $totalReturnValue,
                        'refund_amount' => $refundPaid,
                        'refund_method' => $this->normalizeRefundMethod($request->paymentRefund['method'] ?? 'cash'),
                        'status' => 'completed',
                        'processed_by' => $employee?->id,
                        'approved_by' => $employee?->id,
                        'processed_at' => now(),
                        'completed_at' => now(),
                        'transaction_reference' => 'EXC-REF-' . $returnNumber,
                        'internal_notes' => "Automatic exchange refund difference. Original Order: {$originalOrder->order_number}",
                        'refund_method_details' => $request->paymentRefund['details'] ?? null,
                    ]);
                }
            }

            $replacementOrder->refresh();
            $replacementOrder->updatePaymentStatus();

            // --- 4. LINK EXCHANGE & ACCOUNTING ---
            $refundDue = max(0, $totalReturnValue - $totalAmount);
            $remainingRefundDue = max(0, $refundDue - $refundPaid);

            $history = $productReturn->status_history ?? [];
            $history[] = [
                'status' => 'exchange_linked',
                'changed_at' => now()->toISOString(),
                'changed_by' => $employee?->id,
                'notes' => 'Exchange transaction completed via Lookup Page',
                'replacement_order_id' => $replacementOrder->id,
                'return_value' => $totalReturnValue,
                'replacement_value' => $totalAmount,
                'exchange_balance_used' => $exchangeBalanceUsed,
                'surplus_paid' => $surplusPaid,
                'refund_paid' => $refundPaid,
                'remaining_refund_due' => $remainingRefundDue,
            ];

            $productReturn->status_history = $history;
            $productReturn->internal_notes = trim(($productReturn->internal_notes ? $productReturn->internal_notes . "\n" : '') .
                "Exchange linked to replacement order #{$replacementOrder->order_number}. Return value={$totalReturnValue}, replacement value={$totalAmount}, surplus paid={$surplusPaid}, refund paid={$refundPaid}, remaining refund due={$remainingRefundDue}.");
            $productReturn->status = $remainingRefundDue > 0 ? 'completed' : 'refunded';
            $productReturn->save();

            Transaction::createFromExchange($productReturn, $replacementOrder);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Exchange processed successfully.',
                'data' => [
                    'return' => $productReturn->load('customer'),
                    'order' => $replacementOrder->load('items'),
                    'settlement' => [
                        'return_value' => $totalReturnValue,
                        'replacement_value' => $totalAmount,
                        'difference' => $difference,
                        'exchange_balance_used' => $exchangeBalanceUsed,
                        'surplus_paid' => $surplusPaid,
                        'refund_paid' => $refundPaid,
                        'remaining_refund_due' => $remainingRefundDue,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Exchange processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Exchange failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function assertOrderCanReturnOrExchange(Order $order, string $action): void
    {
        $status = $this->normalizeStatus($order->status);
        if (!$status || in_array($status, self::BLOCKED_RETURN_EXCHANGE_STATUSES, true)) {
            throw new \Exception("{$action} is not available while order status is '{$order->status}'. Eligible statuses are any status except pending, assigned_to_store and pending_assignment.");
        }
    }

    private function normalizeStatus(?string $status): string
    {
        return str_replace([' ', '-'], '_', strtolower(trim((string) $status)));
    }

    private function resolveReturnedBarcode(array $item, Order $order, ?OrderItem $orderItem): ?ProductBarcode
    {
        $barcodeId = $item['product_barcode_id'] ?? $item['barcode_id'] ?? null;
        $barcodeString = $item['barcode'] ?? null;

        $orderItemSoldBarcodeId = $orderItem?->product_barcode_id;

        // Non-barcode/counter items do not have a sold unit barcode. Older Lookup modal
        // builds sent the product SKU in the `barcode` field for these rows. Treat that
        // as a normal quantity-based return instead of failing with "barcode not found".
        if (!$barcodeId && !$barcodeString) {
            return null;
        }

        $barcode = null;
        if ($barcodeId) {
            $barcode = ProductBarcode::query()->lockForUpdate()->where('id', $barcodeId)->first();
        } elseif ($barcodeString) {
            $barcode = ProductBarcode::query()->lockForUpdate()->where('barcode', $barcodeString)->first();
        }

        // Lookup order rows may show the product SKU when barcode_id was not flattened
        // correctly by older frontend builds. If the order item already has the sold
        // barcode id, use that as the source of truth instead of failing on the SKU text.
        if (!$barcode && $orderItemSoldBarcodeId) {
            $barcode = ProductBarcode::query()->lockForUpdate()->find($orderItemSoldBarcodeId);
        }

        if (!$barcode) {
            if (!$orderItemSoldBarcodeId) {
                return null;
            }
            throw new \Exception('Returned barcode was not found. Please refresh the Lookup order and select the barcode again.');
        }

        if ((int) $barcode->product_id !== (int) ($orderItem?->product_id ?? $item['product_id'])) {
            throw new \Exception("Returned barcode {$barcode->barcode} does not match the returned product.");
        }

        if ($orderItem && !empty($orderItem->product_barcode_id) && (int) $barcode->id !== (int) $orderItem->product_barcode_id) {
            throw new \Exception("Returned barcode {$barcode->barcode} does not match the barcode sold on this order item.");
        }

        $metadata = $barcode->location_metadata ?? [];
        $metadataOrderId = $metadata['order_id'] ?? null;
        $metadataOrderNumber = $metadata['order_number'] ?? null;
        $belongsToOrder = ((int) $metadataOrderId === (int) $order->id)
            || ((string) $metadataOrderNumber === (string) $order->order_number)
            || ($orderItem && (int) $barcode->id === (int) $orderItem->product_barcode_id);

        if (!$belongsToOrder) {
            throw new \Exception("Returned barcode {$barcode->barcode} was not sold under order {$order->order_number}.");
        }

        if (!in_array($barcode->current_status, ['with_customer', 'sold', 'in_shipment'], true)) {
            throw new \Exception("Returned barcode {$barcode->barcode} is not currently marked as sold/with customer.");
        }

        return $barcode;
    }

    private function resolveReplacementBarcode(array $itemData, int $productId, int $storeId): ?ProductBarcode
    {
        $barcodeId = $itemData['barcode_id'] ?? null;
        $barcodeString = $itemData['barcode'] ?? null;

        if (!$barcodeId && !$barcodeString) {
            return null;
        }

        $query = ProductBarcode::query()->lockForUpdate();
        $barcode = $barcodeId
            ? $query->where('id', $barcodeId)->first()
            : $query->where('barcode', $barcodeString)->first();

        if (!$barcode) {
            throw new \Exception('Replacement barcode was not found.');
        }

        if ((int) $barcode->product_id !== $productId) {
            throw new \Exception("Replacement barcode {$barcode->barcode} does not match selected product.");
        }

        if ((int) $barcode->current_store_id !== $storeId) {
            throw new \Exception("Replacement barcode {$barcode->barcode} is not located at the selected exchange store.");
        }

        return $barcode;
    }

    private function getReturnedQuantity(int $orderItemId): int
    {
        $returns = ProductReturn::whereIn('status', ['pending', 'approved', 'processing', 'completed', 'refunded'])->get();
        $totalReturned = 0;

        foreach ($returns as $return) {
            foreach ($return->return_items ?? [] as $returnItem) {
                if ((int) ($returnItem['order_item_id'] ?? 0) === $orderItemId) {
                    $totalReturned += (int) ($returnItem['quantity'] ?? 0);
                }
            }
        }

        return $totalReturned;
    }

    private function generateReturnNumber(): string
    {
        $date = now()->format('Ymd');
        do {
            $returnNumber = 'RET-' . $date . '-' . strtoupper(substr(uniqid(), -8));
        } while (ProductReturn::where('return_number', $returnNumber)->exists());

        return $returnNumber;
    }

    private function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        do {
            $orderNumber = 'ORD-' . $date . '-' . strtoupper(substr(uniqid(), -8));
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    private function calculateTax($unitPrice, $quantity, $taxPercentage): array
    {
        $taxMode = config('app.tax_mode', 'inclusive');
        $subtotal = $unitPrice * $quantity;

        if ($taxMode === 'inclusive') {
            $taxAmount = $taxPercentage > 0
                ? $subtotal - ($subtotal / (1 + ($taxPercentage / 100)))
                : 0;
        } else {
            $taxAmount = $subtotal * ($taxPercentage / 100);
        }

        return ['total_tax' => round($taxAmount, 2)];
    }

    private function restoreInventoryForReturn(ProductReturn $return, Employee $employee): void
    {
        $returnStore = $return->received_at_store_id ?? $return->store_id;

        foreach ($return->return_items ?? [] as $item) {
            $originalBatch = ProductBatch::where('id', $item['product_batch_id'])->lockForUpdate()->first();
            if (!$originalBatch) {
                continue;
            }

            $shouldRestock = $this->shouldRestockReturnedItem($item);
            $targetBatch = $this->resolveReturnTargetBatch($originalBatch, (int) $item['product_id'], (int) $returnStore, $return);

            if ($shouldRestock) {
                $targetBatch->increment('quantity', (int) $item['quantity']);
                $targetStatus = 'in_warehouse';
                $isActive = true;
                $isDefective = false;
            } else {
                $targetStatus = 'defective';
                $isActive = false;
                $isDefective = true;
            }

            $barcodeIds = collect($item['returned_barcode_ids'] ?? [])->filter()->values()->all();
            $barcodes = !empty($barcodeIds)
                ? ProductBarcode::whereIn('id', $barcodeIds)->lockForUpdate()->get()
                : collect();

            foreach ($barcodes as $barcode) {
                if ($shouldRestock && $barcode->is_replacement) {
                    $this->relabelService->returnBarcodeFromSold($barcode, $return->order);
                }

                $barcode->updateLocation($returnStore, $targetStatus, [
                    'return_id' => $return->id,
                    'return_reason' => $item['return_reason'] ?? $return->return_reason,
                    'returned_at' => now()->toISOString(),
                    'performed_by' => $employee->id,
                ], false);
                $barcode->batch_id = $targetBatch->id;
                $barcode->is_active = $isActive;
                $barcode->is_defective = $isDefective;
                $barcode->save();

                ProductMovement::create([
                    'product_id' => $barcode->product_id,
                    'product_batch_id' => $targetBatch->id,
                    'product_barcode_id' => $barcode->id,
                    'from_store_id' => (int) $originalBatch->store_id !== (int) $returnStore ? $originalBatch->store_id : null,
                    'to_store_id' => $returnStore,
                    'movement_type' => 'return',
                    'quantity' => 1,
                    'unit_cost' => $originalBatch->cost_price ?? 0,
                    'unit_price' => $item['unit_price'] ?? 0,
                    'total_cost' => $originalBatch->cost_price ?? 0,
                    'total_value' => $item['unit_price'] ?? 0,
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'notes' => $shouldRestock
                        ? "Exchange return restocked: {$return->return_number}"
                        : "Exchange return marked defective/non-sellable: {$return->return_number}",
                    'performed_by' => $employee->id,
                ]);
            }

            if (empty($barcodeIds)) {
                ProductMovement::create([
                    'product_id' => $item['product_id'],
                    'product_batch_id' => $targetBatch->id,
                    'product_barcode_id' => null,
                    'from_store_id' => (int) $originalBatch->store_id !== (int) $returnStore ? $originalBatch->store_id : null,
                    'to_store_id' => $returnStore,
                    'movement_type' => 'return',
                    'quantity' => $item['quantity'],
                    'unit_cost' => $originalBatch->cost_price,
                    'unit_price' => $item['unit_price'] ?? 0,
                    'total_cost' => $originalBatch->cost_price * $item['quantity'],
                    'total_value' => ($item['unit_price'] ?? 0) * $item['quantity'],
                    'reference_type' => 'return',
                    'reference_id' => $return->id,
                    'notes' => $shouldRestock
                        ? "Exchange return restocked: {$return->return_number}"
                        : "Exchange return received as non-sellable: {$return->return_number}",
                    'performed_by' => $employee->id,
                ]);
            }
        }
    }

    private function resolveReturnTargetBatch(ProductBatch $originalBatch, int $productId, int $returnStore, ProductReturn $return): ProductBatch
    {
        if ((int) $originalBatch->store_id === $returnStore) {
            return $originalBatch;
        }

        $baseBatchNumber = $originalBatch->batch_number . '-RTN-S' . $returnStore;
        $batchNumber = $baseBatchNumber;
        $counter = 1;

        while (ProductBatch::where('batch_number', $batchNumber)->exists()) {
            $existing = ProductBatch::where('batch_number', $batchNumber)->first();
            if ($existing && (int) $existing->product_id === $productId && (int) $existing->store_id === $returnStore) {
                return $existing;
            }
            $batchNumber = $baseBatchNumber . '-' . $counter++;
        }

        return ProductBatch::create([
            'product_id' => $productId,
            'store_id' => $returnStore,
            'batch_number' => $batchNumber,
            'quantity' => 0,
            'cost_price' => $originalBatch->cost_price,
            'sell_price' => $originalBatch->sell_price,
            'tax_percentage' => $originalBatch->tax_percentage,
            'manufactured_date' => $originalBatch->manufactured_date,
            'expiry_date' => $originalBatch->expiry_date,
            'availability' => true,
            'is_active' => true,
            'notes' => "Exchange return batch created from original batch {$originalBatch->batch_number} for return {$return->return_number}",
        ]);
    }

    private function shouldRestockReturnedItem(array $item): bool
    {
        $defectiveReasons = ['defective_product', 'quality_issue', 'not_as_described', 'wrong_item'];
        $reason = $item['return_reason'] ?? null;

        return (bool) ($item['quality_check_passed'] ?? true) && !in_array($reason, $defectiveReasons, true);
    }

    private function syncReservedProductAfterExchangeSale(int $productId, int $quantity): void
    {
        $reservedRecord = ReservedProduct::where('product_id', $productId)->lockForUpdate()->first();
        if (!$reservedRecord) {
            return;
        }

        $reservedRecord->total_inventory = max(0, (int) $reservedRecord->total_inventory - $quantity);
        $reservedRecord->available_inventory = max(0, (int) $reservedRecord->total_inventory - (int) $reservedRecord->reserved_inventory);
        $reservedRecord->save();
    }

    private function normalizePaymentMethodCode(string $method): string
    {
        $method = strtolower(trim($method));
        return match ($method) {
            'bkash', 'bikash', 'nagad', 'rocket', 'mfs', 'mobile' => 'mobile_banking',
            'card_refund', 'card-payment', 'card_payment' => 'card',
            default => $method ?: 'cash',
        };
    }

    private function normalizeRefundMethod(string $method): string
    {
        $method = strtolower(trim($method));
        return match ($method) {
            'bkash', 'bikash', 'nagad', 'rocket', 'mfs', 'mobile', 'mobile_banking' => 'digital_wallet',
            'card', 'card_payment' => 'card_refund',
            'bank', 'bank-transfer' => 'bank_transfer',
            default => in_array($method, ['cash', 'bank_transfer', 'card_refund', 'store_credit', 'gift_card', 'digital_wallet', 'check', 'other'], true) ? $method : 'cash',
        };
    }
}
