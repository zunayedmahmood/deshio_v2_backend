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

class ExchangeController extends Controller
{
    /**
     * Process an atomic exchange: Return items + Replacement items + Financial settlement.
     */
    public function process(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|exists:customers,id',
            'exchangeAtStoreId' => 'required|exists:stores,id',
            'removedProducts' => 'required|array|min:1',
            'removedProducts.*.product_id' => 'required|exists:products,id',
            'removedProducts.*.product_batch_id' => 'required|exists:product_batches,id',
            'removedProducts.*.quantity' => 'required|integer|min:1',
            'removedProducts.*.unit_price' => 'required|numeric|min:0',
            'removedProducts.*.total_price' => 'required|numeric|min:0',
            'removedProducts.*.order_item_id' => 'nullable|exists:order_items,id',
            'removedProducts.*.barcode_id' => 'nullable|exists:product_barcodes,id',
            'removedProducts.*.return_reason' => 'required|string',
            'removedProducts.*.quality_check_passed' => 'required|boolean',
            
            'replacementProducts' => 'required|array|min:1',
            'replacementProducts.*.product_id' => 'required|exists:products,id',
            'replacementProducts.*.batch_id' => 'required|exists:product_batches,id',
            'replacementProducts.*.quantity' => 'required|integer|min:1',
            'replacementProducts.*.unit_price' => 'required|numeric|min:0',
            'replacementProducts.*.total_price' => 'required|numeric|min:0',
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

            $storeId = $request->exchangeAtStoreId;
            $customer_id = $request->customer_id;

            // --- 1. CREATE PRODUCT RETURN ---
            $returnNumber = $this->generateReturnNumber();
            $totalReturnValue = 0;
            $returnItems = [];

            foreach ($request->removedProducts as $item) {
                $totalReturnValue += (float) $item['total_price'];
                $returnItems[] = [
                    'product_id' => $item['product_id'],
                    'product_batch_id' => $item['product_batch_id'],
                    'order_item_id' => $item['order_item_id'] ?? null,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $item['total_price'],
                    'refundable_amount' => $item['total_price'],
                    'return_reason' => $item['return_reason'],
                    'quality_check_passed' => $item['quality_check_passed'],
                    'returned_barcode_ids' => isset($item['barcode_id']) ? [$item['barcode_id']] : [],
                ];
            }

            $productReturn = ProductReturn::create([
                'return_number' => $returnNumber,
                'customer_id' => $customer_id,
                'store_id' => $storeId, // Returned to THIS store
                'return_reason' => 'Exchange',
                'return_type' => 'exchange',
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
            $orderNumber = $this->generateOrderNumber();
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
                    if (in_array($barcode->current_status, ['sold', 'with_customer'])) throw new \Exception("Barcode sold");
                    
                    $barcodeId = $barcode->id;
                }

                $quantity = $itemData['quantity'];
                $unitPrice = $itemData['unit_price'];
                $discount = $itemData['discount_amount'] ?? 0;
                
                $taxPercentage = $batch->tax_percentage ?? 0;
                $taxCalculation = $this->calculateTax($unitPrice, $quantity, $taxPercentage);
                $tax = $taxCalculation['total_tax'];
                
                $itemSubtotal = $quantity * $unitPrice;
                $itemTotal = $itemSubtotal - $discount;
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
                    $reservedRecord->decrement('reserved_inventory', $quantity);
                    $reservedRecord->refresh();
                    $reservedRecord->available_inventory = $reservedRecord->total_inventory - $reservedRecord->reserved_inventory;
                    $reservedRecord->save();
                }

                // Update barcode status
                if ($barcodeId) {
                    $barcode = ProductBarcode::find($barcodeId);
                    $barcode->updateLocation(null, 'with_customer', ['order_id' => $replacementOrder->id]);
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
            $exchangeBalanceUsed = min($totalReturnValue, $totalAmount);
            
            // 3a. Use "Exchange Balance" payment method
            $exchangeMethod = PaymentMethod::where('code', 'exchange_balance')->first();
            if (!$exchangeMethod) {
                // Fallback or dynamic creation if needed, but per GEMINI.md, system should have it.
                // For now, let's assume it exists or use 'other'
                $exchangeMethod = PaymentMethod::where('code', 'other')->first();
            }

            if ($exchangeBalanceUsed > 0) {
                $payment = OrderPayment::createPayment(
                    $replacementOrder,
                    $exchangeMethod,
                    $exchangeBalanceUsed,
                    [
                        'notes' => "Exchange Credit from Return #{$returnNumber}",
                        'payment_type' => 'exchange_balance' // Handled by observer exclusion
                    ],
                    $employee
                );
                $payment->complete('EXC-' . $returnNumber, 'INTERNAL');
            }

            // 3b. Handle Surplus (Customer pays extra)
            if ($request->paymentRefund['type'] === 'surplus' && $request->paymentRefund['amount'] > 0) {
                $surplusMethodCode = $request->paymentRefund['method'] ?? 'cash';
                $surplusMethod = PaymentMethod::where('code', $surplusMethodCode)->first();
                if (!$surplusMethod) $surplusMethod = PaymentMethod::where('code', 'cash')->first();

                $payment = OrderPayment::createPayment(
                    $replacementOrder,
                    $surplusMethod,
                    (float)$request->paymentRefund['amount'],
                    [
                        'notes' => "Surplus payment for exchange",
                        'payment_type' => 'exchange_surplus' // Silences OrderPaymentObserver for ledger
                    ],
                    $employee
                );
                $payment->complete('EXC-SUR-' . $returnNumber, 'EXTERNAL');
            }

            // 3c. Handle Refund (Store pays back)
            if ($request->paymentRefund['type'] === 'refund' && $request->paymentRefund['amount'] > 0) {
                $refundMethodCode = $request->paymentRefund['method'] ?? 'cash';
                
                $refund = Refund::create([
                    'refund_number' => 'REF-EXC-' . date('Ymd') . '-' . Str::random(4),
                    'return_id' => $productReturn->id,
                    'order_id' => null, // This refund is from the return value difference
                    'customer_id' => $customer_id,
                    'refund_type' => 'exchange_refund', // Silences RefundObserver for ledger
                    'original_amount' => $totalReturnValue,
                    'refund_amount' => (float)$request->paymentRefund['amount'],
                    'refund_method' => $refundMethodCode,
                    'status' => 'completed', // In-person exchange typically means immediate refund
                    'processed_by' => $employee->id,
                    'approved_by' => $employee->id,
                    'completed_at' => now(),
                ]);

                // Note: Ledger entry for refund is handled by Transaction::createFromExchange below
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
            
            if ($request->paymentRefund['type'] !== 'surplus') {
                 $productReturn->status = 'refunded'; // Fully utilized or refunded
            }
            $productReturn->save();

            // Unified Exchange Journal
            Transaction::createFromExchange($productReturn, $replacementOrder);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Exchange processed successfully.',
                'data' => [
                    'return' => $productReturn->load('customer'),
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

    private function generateReturnNumber(): string
    {
        $date = now()->format('Ymd');
        $count = DB::table('product_returns')->whereDate('created_at', now())->count() + 1;
        return 'RET-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $count = DB::table('orders')->whereDate('created_at', now())->count() + 1;
        return 'ORD-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
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

    private function restoreInventoryForReturn(ProductReturn $return, Employee $employee): void
    {
        $returnStore = $return->store_id;

        foreach ($return->return_items ?? [] as $item) {
            $originalBatch = ProductBatch::find($item['product_batch_id']);
            if (!$originalBatch) continue;

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

            // Barcodes
            $barcodeIds = $item['returned_barcode_ids'] ?? [];
            if (!empty($barcodeIds)) {
                ProductBarcode::whereIn('id', $barcodeIds)->each(function ($barcode) use ($returnStore, $targetBatch, $return, $employee) {
                    $barcode->updateLocation($returnStore, 'in_warehouse', ['return_id' => $return->id]);
                    $barcode->batch_id = $targetBatch->id;
                    $barcode->is_active = true;
                    $barcode->current_status = 'in_warehouse';
                    $barcode->save();

                    ProductMovement::create([
                        'product_id' => $barcode->product_id,
                        'product_batch_id' => $targetBatch->id,
                        'product_barcode_id' => $barcode->id,
                        'to_store_id' => $returnStore,
                        'movement_type' => 'return',
                        'quantity' => 1,
                        'unit_cost' => $barcode->batch->cost_price ?? 0,
                        'reference_type' => 'return',
                        'reference_id' => $return->id,
                        'performed_by' => $employee->id,
                    ]);
                });
            } else {
                // Bulk return without specific barcodes
                ProductMovement::create([
                    'product_id' => $item['product_id'],
                    'product_batch_id' => $targetBatch->id,
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
        }
    }
}
