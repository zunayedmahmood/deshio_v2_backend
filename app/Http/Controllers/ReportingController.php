<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Category;
use App\Models\ProductReturn;
use App\Models\Refund;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class ReportingController extends Controller
{
    /**
     * Statuses that should never be counted as real sales unless the user explicitly filters for them.
     */
    private array $defaultExcludedOrderStatuses = ['cancelled', 'pending_assignment', 'draft'];

    private array $internalPaymentTypes = ['exchange_balance', 'store_credit', 'balance_carryover'];

    private function dateStart(?string $date): ?Carbon
    {
        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    private function dateEnd(?string $date): ?Carbon
    {
        return $date ? Carbon::parse($date)->endOfDay() : null;
    }

    private function csvHeaders(string $filename): array
    {
        return [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ];
    }

    private function writeBom($file): void
    {
        fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
    }

    private function money($value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function qty($value): string
    {
        return number_format((float) ($value ?? 0), 0, '.', '');
    }

    private function normalizeCategoryName(?string $name): string
    {
        $clean = trim((string) ($name ?: 'Uncategorized'));
        $clean = preg_replace('/\s+/', ' ', $clean) ?: 'Uncategorized';
        return mb_strtoupper($clean);
    }

    private function displayCategoryName(?string $name): string
    {
        $clean = trim((string) ($name ?: 'Uncategorized'));
        return preg_replace('/\s+/', ' ', $clean) ?: 'Uncategorized';
    }

    /**
     * CSV/Excel tends to auto-format long numeric barcodes into scientific notation.
     * Prefixing a tab keeps the barcode visually intact without changing the stored value in the DB.
     */
    private function formatBarcodeForCsv(?string $barcode): string
    {
        $value = trim((string) ($barcode ?? ''));
        return $value === '' ? 'N/A' : "\t" . $value;
    }

    private function completedPaymentStatuses(): array
    {
        return ['completed', 'partially_refunded', 'refunded'];
    }

    private function isRealSaleStatus(?string $status): bool
    {
        return !in_array((string) $status, $this->defaultExcludedOrderStatuses, true);
    }

    /**
     * Single source of truth for item money calculation used by sales, booking,
     * category and stock reports. This avoids category subtotal=0 when old rows
     * have quantity/barcode data but stale order_item totals.
     */
    private function resolveLineAmounts(OrderItem $item): array
    {
        $qty = (float) ($item->quantity ?? 0);
        $batch = $item->batch ?: ($item->barcode?->batch);

        $storedTotal = (float) ($item->total_amount ?? 0);
        $discount = (float) ($item->discount_amount ?? 0);
        $tax = (float) ($item->tax_amount ?? 0);

        $unit = (float) ($item->unit_price ?? 0);
        if ($unit <= 0 && $batch && (float) ($batch->sell_price ?? 0) > 0) {
            $unit = (float) $batch->sell_price;
        }
        if ($unit <= 0 && $qty > 0 && $storedTotal > 0) {
            $unit = ($storedTotal + $discount) / $qty;
        }

        $gross = $qty * $unit;
        if ($gross <= 0 && $storedTotal > 0) {
            $gross = $storedTotal + $discount;
        }

        $net = $storedTotal > 0 ? $storedTotal : max(0, $gross - $discount);

        $costUnit = 0.0;
        if ((float) ($item->cogs ?? 0) > 0 && $qty > 0) {
            $costUnit = (float) $item->cogs / $qty;
        } elseif ($batch && (float) ($batch->cost_price ?? 0) > 0) {
            $costUnit = (float) $batch->cost_price;
        }

        return [
            'quantity' => $qty,
            'unit' => $unit,
            'gross' => $gross,
            'discount' => $discount,
            'tax' => $tax,
            'net' => $net,
            'cost_unit' => $costUnit,
            'batch_id' => (int) ($item->product_batch_id ?: ($item->barcode?->batch_id ?? 0)),
        ];
    }

    private function summarizeOrderItems($items): array
    {
        $totals = ['gross' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'net' => 0.0, 'cogs' => 0.0, 'quantity' => 0.0];

        foreach ($items as $item) {
            $line = $this->resolveLineAmounts($item);
            $totals['gross'] += $line['gross'];
            $totals['discount'] += $line['discount'];
            $totals['tax'] += $line['tax'];
            $totals['net'] += $line['net'];
            $totals['cogs'] += $line['cost_unit'] * $line['quantity'];
            $totals['quantity'] += $line['quantity'];
        }

        return $totals;
    }

    private function categoryNameForProduct(?Product $product): string
    {
        return $this->displayCategoryName($product?->category?->title ?? null);
    }

    private function lineGrossSql(string $itemAlias = 'order_items', ?string $batchAlias = null, ?string $barcodeBatchAlias = null): string
    {
        $qty = "COALESCE({$itemAlias}.quantity, 0)";
        $unit = "COALESCE({$itemAlias}.unit_price, 0)";
        $total = "COALESCE({$itemAlias}.total_amount, 0)";
        $discount = "COALESCE({$itemAlias}.discount_amount, 0)";

        $batchFallbacks = '';
        if ($batchAlias) {
            $batchFallbacks .= " WHEN COALESCE({$batchAlias}.sell_price, 0) > 0 THEN {$qty} * COALESCE({$batchAlias}.sell_price, 0)";
        }
        if ($barcodeBatchAlias) {
            $batchFallbacks .= " WHEN COALESCE({$barcodeBatchAlias}.sell_price, 0) > 0 THEN {$qty} * COALESCE({$barcodeBatchAlias}.sell_price, 0)";
        }

        return "CASE
            WHEN {$unit} > 0 THEN {$qty} * {$unit}
            WHEN {$total} > 0 THEN {$total} + {$discount}
            {$batchFallbacks}
            ELSE 0
        END";
    }

    private function lineNetSql(string $itemAlias = 'order_items', ?string $batchAlias = null, ?string $barcodeBatchAlias = null): string
    {
        $gross = $this->lineGrossSql($itemAlias, $batchAlias, $barcodeBatchAlias);
        return "CASE
            WHEN COALESCE({$itemAlias}.total_amount, 0) > 0 THEN COALESCE({$itemAlias}.total_amount, 0)
            ELSE ({$gross}) - COALESCE({$itemAlias}.discount_amount, 0)
        END";
    }

    private function applyOrderScope($query, Request $request, string $orderAlias = 'orders', string $dateColumn = 'order_date')
    {
        $query->whereNull("{$orderAlias}.deleted_at");

        if ($request->filled('status')) {
            $query->where("{$orderAlias}.status", $request->status);
        } else {
            $query->whereNotIn("{$orderAlias}.status", $this->defaultExcludedOrderStatuses);
        }

        if ($request->filled('date_from')) {
            $query->where("{$orderAlias}.{$dateColumn}", '>=', $this->dateStart($request->date_from));
        }

        if ($request->filled('date_to')) {
            $query->where("{$orderAlias}.{$dateColumn}", '<=', $this->dateEnd($request->date_to));
        }

        return $query;
    }

    private function returnItemAmount(array $item): float
    {
        $qty = (float) ($item['quantity'] ?? $item['qty'] ?? 1);
        $unit = (float) ($item['unit_price'] ?? $item['manual_sold_at_price'] ?? $item['sold_price'] ?? 0);
        $explicit = (float) ($item['total_price'] ?? $item['refundable_amount'] ?? $item['amount'] ?? 0);

        if ($explicit > 0) {
            return $explicit;
        }

        return $qty * $unit;
    }

    private function decodeReturnItems($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    private function isExchangeReturn(ProductReturn $return): bool
    {
        $notes = strtolower((string) ($return->internal_notes ?? ''));
        if (str_contains($notes, 'exchange')) {
            return true;
        }

        $history = $return->status_history;
        if (is_string($history)) {
            $history = json_decode($history, true);
        }
        if (is_array($history)) {
            foreach ($history as $entry) {
                $status = strtolower((string) ($entry['status'] ?? ''));
                if (str_contains($status, 'exchange')) {
                    return true;
                }
            }
        }

        return Refund::query()
            ->where('return_id', $return->id)
            ->where('refund_type', 'exchange_refund')
            ->exists();
    }

    private function buildReturnExchangeBuckets(Request $request): array
    {
        $returns = ProductReturn::query()
            ->whereIn('status', ['approved', 'processing', 'completed', 'refunded'])
            ->when($request->filled('date_from'), fn ($q) => $q->where('return_date', '>=', $this->dateStart($request->date_from)))
            ->when($request->filled('date_to'), fn ($q) => $q->where('return_date', '<=', $this->dateEnd($request->date_to)))
            ->when($request->filled('store_id'), fn ($q) => $q->where('store_id', $request->store_id))
            ->get(['id', 'return_items', 'internal_notes', 'status_history']);

        $productIds = [];
        foreach ($returns as $return) {
            foreach ($this->decodeReturnItems($return->return_items) as $item) {
                if (!empty($item['product_id'])) {
                    $productIds[] = (int) $item['product_id'];
                }
            }
        }

        $products = Product::withTrashed()
            ->with(['category' => fn ($q) => $q->withTrashed()])
            ->whereIn('id', array_unique($productIds))
            ->get(['id', 'category_id'])
            ->keyBy('id');

        $returnsByCategory = [];
        $exchangesByCategory = [];

        foreach ($returns as $return) {
            $bucket =& $returnsByCategory;
            if ($this->isExchangeReturn($return)) {
                $bucket =& $exchangesByCategory;
            }

            foreach ($this->decodeReturnItems($return->return_items) as $item) {
                if (empty($item['product_id'])) {
                    continue;
                }
                $product = $products->get((int) $item['product_id']);
                $categoryName = $this->categoryNameForProduct($product);
                $key = $this->normalizeCategoryName($categoryName);
                $bucket[$key] = ($bucket[$key] ?? 0) + $this->returnItemAmount($item);
            }
        }

        return [$returnsByCategory, $exchangesByCategory];
    }

    /**
     * GET /api/reporting/csv/category-sales
     */
    public function exportCategorySalesCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'store_id' => 'nullable|exists:stores,id',
            'status' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = OrderItem::query()
            ->with(['product' => fn ($q) => $q->withTrashed()->with(['category' => fn ($cq) => $cq->withTrashed()]), 'batch', 'barcode.batch'])
            ->whereHas('order', function ($q) use ($request) {
                $this->applyOrderScope($q, $request, 'orders');
            })
            ->whereHas('product', function ($q) {
                $q->withTrashed();
            });

        if ($request->filled('store_id')) {
            $storeId = $request->store_id;
            $query->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)
                    ->orWhereHas('order', fn ($oq) => $oq->where('store_id', $storeId));
            });
        }

        $buckets = [];
        $query->orderBy('id')->chunkById(1000, function ($items) use (&$buckets) {
            foreach ($items as $item) {
                $line = $this->resolveLineAmounts($item);
                $categoryName = $this->categoryNameForProduct($item->product);
                $key = $this->normalizeCategoryName($categoryName);

                if (!isset($buckets[$key])) {
                    $buckets[$key] = [
                        'category' => $categoryName,
                        'qty' => 0.0,
                        'subtotal' => 0.0,
                        'discount' => 0.0,
                        'tax' => 0.0,
                        'line_net_total' => 0.0,
                    ];
                }

                $buckets[$key]['qty'] += $line['quantity'];
                $buckets[$key]['subtotal'] += $line['gross'];
                $buckets[$key]['discount'] += $line['discount'];
                $buckets[$key]['tax'] += $line['tax'];
                $buckets[$key]['line_net_total'] += $line['net'];
            }
        });

        ksort($buckets, SORT_NATURAL | SORT_FLAG_CASE);

        [$returnsByCategory, $exchangesByCategory] = $this->buildReturnExchangeBuckets($request);

        $filename = 'category-sales-report-' . now()->format('Y-m-d-His') . '.csv';
        $callback = function () use ($buckets, $returnsByCategory, $exchangesByCategory) {
            $file = fopen('php://output', 'w');
            $this->writeBom($file);

            fputcsv($file, [
                'Category',
                'Sold Qty',
                'SUB Total',
                'Discount Amount',
                'Exchange Amount',
                'Return Amount',
                'Net Sales (without VAT)',
                'VAT Amount (7.5)',
                'Net Amount',
            ]);

            $totals = array_fill_keys(['qty', 'subtotal', 'discount', 'exchange', 'return', 'net_without_vat', 'vat', 'net'], 0.0);

            foreach ($buckets as $key => $sale) {
                $subtotal = (float) $sale['subtotal'];
                $discount = (float) $sale['discount'];
                $returnAmount = (float) ($returnsByCategory[$key] ?? 0);
                $exchangeAmount = (float) ($exchangesByCategory[$key] ?? 0);
                $taxAmount = (float) $sale['tax'];

                $netAmount = max(0, $subtotal - $discount - $returnAmount - $exchangeAmount);
                $vatAmount = $taxAmount > 0 ? $taxAmount : round($netAmount * 0.075, 2);
                $netWithoutVAT = max(0, $netAmount - $vatAmount);

                $totals['qty'] += (float) $sale['qty'];
                $totals['subtotal'] += $subtotal;
                $totals['discount'] += $discount;
                $totals['exchange'] += $exchangeAmount;
                $totals['return'] += $returnAmount;
                $totals['net_without_vat'] += $netWithoutVAT;
                $totals['vat'] += $vatAmount;
                $totals['net'] += $netAmount;

                fputcsv($file, [
                    $sale['category'],
                    $this->qty($sale['qty']),
                    $this->money($subtotal),
                    $this->money($discount),
                    $this->money($exchangeAmount),
                    $this->money($returnAmount),
                    $this->money($netWithoutVAT),
                    $this->money($vatAmount),
                    $this->money($netAmount),
                ]);
            }

            fputcsv($file, []);
            fputcsv($file, [
                'TOTAL',
                $this->qty($totals['qty']),
                $this->money($totals['subtotal']),
                $this->money($totals['discount']),
                $this->money($totals['exchange']),
                $this->money($totals['return']),
                $this->money($totals['net_without_vat']),
                $this->money($totals['vat']),
                $this->money($totals['net']),
            ]);

            fclose($file);
        };

        return Response::stream($callback, 200, $this->csvHeaders($filename));
    }

    /**
     * GET /api/reporting/csv/sales
     */
    public function exportSalesCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'store_id' => 'nullable|exists:stores,id',
            'status' => 'nullable|string',
            'customer_id' => 'nullable|exists:customers,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = Order::query()
            ->with(['customer', 'store', 'items.product.category', 'items.batch', 'items.barcode.batch', 'payments.paymentMethod', 'payments.paymentSplits.paymentMethod', 'shipments']);

        $this->applyOrderScope($query, $request, 'orders');

        if ($request->filled('store_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('orders.store_id', $request->store_id)
                    ->orWhereHas('items', fn ($iq) => $iq->where('store_id', $request->store_id));
            });
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $orders = $query->orderBy('order_date', 'desc')->get();
        $filename = 'sales-report-' . now()->format('Y-m-d-His') . '.csv';

        $callback = function () use ($orders) {
            $file = fopen('php://output', 'w');
            $this->writeBom($file);

            fputcsv($file, [
                'Creation Date',
                'Invoice Number',
                'Order Type',
                'Store',
                'Customer Name',
                'Customer Phone',
                'Customer Address',
                'Product Name And QTY',
                'Product Specification',
                'Product Attribute',
                'Sub Total Price',
                'Discount',
                'Price After Discount',
                'Delivery Charge',
                'Calculated Item+Delivery Total',
                'Order Adjustment / Extra Charge',
                'Total Price',
                'Actual Paid Amount',
                'Internal Credit Amount',
                'Due Amount',
                'Overpaid Amount',
                'Delivery Partner',
                'Delivery Area',
                'Payment Method',
                'Order Status',
            ]);

            foreach ($orders as $order) {
                $items = $order->items ?? collect();
                $itemTotals = $this->summarizeOrderItems($items);
                $productNames = [];
                $productSpecs = [];
                $productAttrs = [];

                foreach ($items as $item) {
                    $line = $this->resolveLineAmounts($item);
                    $productNames[] = ($item->product_name ?? $item->product?->name ?? 'Unknown') . ' (x' . $line['quantity'] . ')';

                    $options = is_array($item->product_options) ? $item->product_options : [];
                    $specs = [];
                    foreach ($options as $key => $value) {
                        if (is_scalar($value)) {
                            $specs[] = ucfirst((string) $key) . ': ' . $value;
                        }
                    }
                    $productSpecs[] = $specs ? implode('; ', $specs) : 'N/A';

                    $attrs = [];
                    if ($item->product_sku) $attrs[] = 'SKU: ' . $item->product_sku;
                    if ($item->batch?->batch_number) $attrs[] = 'Batch: ' . $item->batch->batch_number;
                    if ($item->barcode?->barcode) $attrs[] = 'Barcode: ' . $item->barcode->barcode;
                    $productAttrs[] = $attrs ? implode('; ', $attrs) : 'N/A';
                }

                $shipping = (float) ($order->shipping_amount ?? 0);
                $subtotal = $itemTotals['gross'] > 0 ? $itemTotals['gross'] : (float) ($order->subtotal ?? 0);
                $discount = $itemTotals['discount'] > 0 ? $itemTotals['discount'] : (float) ($order->discount_amount ?? 0);
                $priceAfterDiscount = max(0, $subtotal - $discount);
                $calculatedTotal = $priceAfterDiscount + $shipping;
                $storedTotal = (float) ($order->total_amount ?? 0);
                $totalPrice = $storedTotal > 0 ? $storedTotal : $calculatedTotal;
                $orderAdjustment = round($totalPrice - $calculatedTotal, 2);

                [$actualPaid, $internalCredit, $paymentMethods] = $this->summarizeOrderPayments($order->payments ?? collect());
                $paidFallback = (float) ($order->paid_amount ?? 0);
                $displayPaid = $actualPaid > 0 ? $actualPaid : max(0, $paidFallback - $internalCredit);
                $balance = $totalPrice - $displayPaid - $internalCredit;
                $due = max(0, $balance);
                $overpaid = max(0, -$balance);
                if ((float) ($order->outstanding_amount ?? 0) > 0 && $displayPaid + $internalCredit <= 0) {
                    $due = (float) $order->outstanding_amount;
                }

                $customerAddress = $this->formatOrderAddress($order);
                $deliveryPartner = $order->shipments?->first()?->carrier_name ?? $order->carrier_name ?? 'N/A';
                $deliveryArea = $this->extractDeliveryArea($order);

                fputcsv($file, [
                    $order->order_date ? $order->order_date->format('Y-m-d H:i:s') : '',
                    $order->order_number ?? '',
                    $order->order_type ?? '',
                    $order->store?->name ?? '',
                    $order->customer?->name ?? 'N/A',
                    $order->customer?->phone ?? 'N/A',
                    $customerAddress,
                    implode(' | ', $productNames),
                    implode(' | ', $productSpecs),
                    implode(' | ', $productAttrs),
                    $this->money($subtotal),
                    $this->money($discount),
                    $this->money($priceAfterDiscount),
                    $this->money($shipping),
                    $this->money($calculatedTotal),
                    $this->money($orderAdjustment),
                    $this->money($totalPrice),
                    $this->money($displayPaid),
                    $this->money($internalCredit),
                    $this->money($due),
                    $this->money($overpaid),
                    $deliveryPartner,
                    $deliveryArea,
                    implode(', ', array_unique($paymentMethods)) ?: ($order->payment_method ?? 'N/A'),
                    ucfirst(str_replace('_', ' ', $order->status ?? '')),
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $this->csvHeaders($filename));
    }

    private function summarizeOrderPayments($payments): array
    {
        $actualPaid = 0.0;
        $internalCredit = 0.0;
        $methods = [];

        foreach ($payments as $payment) {
            if (!in_array($payment->status, ['completed', 'partially_refunded', 'refunded'], true)) {
                continue;
            }

            $paymentType = (string) ($payment->payment_type ?? 'full');
            $isInternal = in_array($paymentType, $this->internalPaymentTypes, true);

            if ($payment->paymentSplits && $payment->paymentSplits->count() > 0) {
                foreach ($payment->paymentSplits as $split) {
                    if (!in_array($split->status, ['completed', 'partially_refunded', 'refunded'], true)) {
                        continue;
                    }
                    $methods[] = $split->paymentMethod?->name ?? 'Split Payment';
                    $amount = max(0, (float) $split->amount - (float) ($split->refunded_amount ?? 0));
                    $isInternal ? $internalCredit += $amount : $actualPaid += $amount;
                }
            } else {
                $methods[] = $payment->paymentMethod?->name ?? $paymentType;
                $amount = max(0, (float) $payment->amount - (float) ($payment->refunded_amount ?? 0));
                $isInternal ? $internalCredit += $amount : $actualPaid += $amount;
            }
        }

        return [$actualPaid, $internalCredit, $methods];
    }

    private function formatOrderAddress(Order $order): string
    {
        $address = $order->shipping_address;
        if (is_array($address)) {
            return implode(', ', array_filter([
                $address['street'] ?? $address['address_line_1'] ?? $address['address'] ?? '',
                $address['area'] ?? $address['address_line_2'] ?? '',
                $address['city'] ?? '',
                $address['district'] ?? '',
            ]));
        }

        return (string) ($order->customer?->address ?? '');
    }

    private function extractDeliveryArea(Order $order): string
    {
        $address = $order->shipping_address;
        if (is_array($address)) {
            return (string) ($address['area'] ?? $address['city'] ?? $address['district'] ?? '');
        }
        return '';
    }

    /**
     * GET /api/reporting/csv/stock
     */
    public function exportStockCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'nullable|exists:stores,id',
            'category_id' => 'nullable|exists:categories,id',
            'product_id' => 'nullable|exists:products,id',
            'include_inactive' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = ProductBatch::query()
            ->with(['product.category', 'store'])
            ->join('products', 'product_batches.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->select('product_batches.*');

        if ($request->filled('store_id')) $query->where('product_batches.store_id', $request->store_id);
        if ($request->filled('category_id')) $query->where('products.category_id', $request->category_id);
        if ($request->filled('product_id')) $query->where('product_batches.product_id', $request->product_id);
        if (!$request->boolean('include_inactive')) $query->where('product_batches.is_active', true);

        $batches = $query->orderBy('products.category_id')->orderBy('products.sku')->orderBy('product_batches.batch_number')->get();
        $batchIds = $batches->pluck('id')->map(fn ($id) => (int) $id)->all();

        $soldQuantities = [];
        $soldSubtotals = [];

        if ($batchIds) {
            OrderItem::query()
                ->with(['order', 'batch', 'barcode.batch'])
                ->whereHas('order', function ($q) {
                    $q->whereNull('orders.deleted_at')
                        ->whereNotIn('orders.status', $this->defaultExcludedOrderStatuses);
                })
                ->where(function ($q) use ($batchIds) {
                    $q->whereIn('product_batch_id', $batchIds)
                        ->orWhereHas('barcode', fn ($bq) => $bq->whereIn('batch_id', $batchIds));
                })
                ->orderBy('id')
                ->chunkById(1000, function ($items) use (&$soldQuantities, &$soldSubtotals, $batchIds) {
                    $batchLookup = array_flip($batchIds);
                    foreach ($items as $item) {
                        $line = $this->resolveLineAmounts($item);
                        $batchId = (int) $line['batch_id'];
                        if (!$batchId || !isset($batchLookup[$batchId])) {
                            continue;
                        }
                        $soldQuantities[$batchId] = ($soldQuantities[$batchId] ?? 0) + $line['quantity'];
                        $soldSubtotals[$batchId] = ($soldSubtotals[$batchId] ?? 0) + $line['net'];
                    }
                });
        }

        $filename = 'stock-report-' . now()->format('Y-m-d-His') . '.csv';
        $callback = function () use ($batches, $soldQuantities, $soldSubtotals) {
            $file = fopen('php://output', 'w');
            $this->writeBom($file);

            fputcsv($file, [
                'Category', 'Product Code', 'Product Name', 'Product Brand', 'Product Description',
                'Batch Number', 'Sold Quantity', 'Sub Total', 'Remaining Stock Quantity',
                'Cost Value', 'Stock Volume', 'Store',
            ]);

            foreach ($batches as $batch) {
                $product = $batch->product;
                $remainingStock = (float) ($batch->quantity ?? 0);
                fputcsv($file, [
                    $product?->category?->title ?? 'Uncategorized',
                    $product?->sku ?? 'N/A',
                    $product?->name ?? 'N/A',
                    $product?->brand ?? 'N/A',
                    $product?->description ?? 'N/A',
                    $batch->batch_number ?? 'N/A',
                    $this->qty($soldQuantities[$batch->id] ?? 0),
                    $this->money($soldSubtotals[$batch->id] ?? 0),
                    $this->qty($remainingStock),
                    $this->money($remainingStock * (float) ($batch->cost_price ?? 0)),
                    $this->money($remainingStock * (float) ($batch->sell_price ?? 0)),
                    $batch->store?->name ?? 'N/A',
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $this->csvHeaders($filename));
    }

    /**
     * GET /api/reporting/csv/booking
     */
    public function exportBookingCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'store_id' => 'nullable|exists:stores,id',
            'status' => 'nullable|string',
            'customer_id' => 'nullable|exists:customers,id',
            'product_id' => 'nullable|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = OrderItem::query()
            ->with(['order.customer', 'order.store', 'order.payments.paymentMethod', 'order.payments.paymentSplits.paymentMethod', 'product' => fn ($q) => $q->withTrashed()->with(['category' => fn ($cq) => $cq->withTrashed()]), 'batch', 'barcode.batch', 'store'])
            ->whereHas('order', function ($q) use ($request) {
                $this->applyOrderScope($q, $request, 'orders');
                if ($request->filled('customer_id')) $q->where('customer_id', $request->customer_id);
            });

        if ($request->filled('store_id')) {
            $storeId = $request->store_id;
            $query->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)
                    ->orWhereHas('order', fn ($oq) => $oq->where('store_id', $storeId));
            });
        }
        if ($request->filled('product_id')) $query->where('product_id', $request->product_id);

        $orderItems = $query->orderBy('created_at', 'desc')->get();

        $barcodeCounts = $orderItems
            ->filter(fn ($item) => !empty($item->barcode?->barcode))
            ->groupBy(fn ($item) => (string) $item->barcode->barcode)
            ->map(fn ($items) => $items->pluck('order_id')->unique()->count());

        $filename = 'booking-report-' . now()->format('Y-m-d-His') . '.csv';

        $callback = function () use ($orderItems, $barcodeCounts) {
            $file = fopen('php://output', 'w');
            $this->writeBom($file);
            fputcsv($file, [
                'Order Number', 'Order Date', 'Order Status', 'Order Type', 'Store', 'Customer Name', 'Customer Phone', 'Customer Code',
                'Product Name', 'Product Code (SKU)', 'Category', 'Product Barcode', 'Barcode Report Check', 'Batch Number', 'Quantity',
                'Selling Price', 'Cost Price', 'Item Subtotal', 'Item Discount', 'Item Net Amount',
                'Payable (Order Total)', 'Actual Paid Amount', 'Internal Credit Amount', 'Due Amount', 'Overpaid Amount',
            ]);

            foreach ($orderItems as $item) {
                $order = $item->order;
                $line = $this->resolveLineAmounts($item);
                $batch = $item->batch ?: $item->barcode?->batch;
                $barcode = (string) ($item->barcode?->barcode ?? '');
                $barcodeCheck = 'OK';
                if ($barcode === '') {
                    $barcodeCheck = 'Missing barcode on order item';
                } elseif (($barcodeCounts[$barcode] ?? 0) > 1) {
                    $barcodeCheck = 'Duplicate barcode across multiple orders - check return/exchange/history';
                }

                [$actualPaid, $internalCredit] = $order ? $this->summarizeOrderPayments($order->payments ?? collect()) : [0, 0, []];
                $orderTotal = (float) ($order?->total_amount ?? 0);
                $balance = $orderTotal - $actualPaid - $internalCredit;
                $due = max(0, $balance);
                $overpaid = max(0, -$balance);

                fputcsv($file, [
                    $order?->order_number ?? 'N/A',
                    $order?->order_date ? $order->order_date->format('Y-m-d H:i:s') : '',
                    $order?->status ?? '',
                    $order?->order_type ?? '',
                    $item->store?->name ?? $order?->store?->name ?? '',
                    $order?->customer?->name ?? 'N/A',
                    $order?->customer?->phone ?? 'N/A',
                    $order?->customer?->customer_code ?? 'N/A',
                    $item->product_name ?? $item->product?->name ?? 'N/A',
                    $item->product_sku ?? $item->product?->sku ?? 'N/A',
                    $item->product?->category?->title ?? 'Uncategorized',
                    $this->formatBarcodeForCsv($barcode),
                    $barcodeCheck,
                    $batch?->batch_number ?? 'N/A',
                    $this->qty($line['quantity']),
                    $this->money($line['unit']),
                    $this->money($line['cost_unit']),
                    $this->money($line['gross']),
                    $this->money($line['discount']),
                    $this->money($line['net']),
                    $this->money($orderTotal),
                    $this->money($actualPaid),
                    $this->money($internalCredit),
                    $this->money($due),
                    $this->money($overpaid),
                ]);
            }
            fclose($file);
        };

        return Response::stream($callback, 200, $this->csvHeaders($filename));
    }

    /**
     * GET /api/reporting/csv/payment-breakdown
     */
    public function exportPaymentBreakdownCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'today' => 'nullable|boolean',
            'store_id' => 'nullable|exists:stores,id',
            'order_type' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $from = $request->boolean('today') ? now()->startOfDay() : ($this->dateStart($request->date_from) ?? now()->startOfMonth());
        $to = $request->boolean('today') ? now()->endOfDay() : ($this->dateEnd($request->date_to) ?? now()->endOfDay());

        $payments = OrderPayment::query()
            ->with(['order.customer', 'order.store', 'paymentMethod', 'paymentSplits.paymentMethod', 'store'])
            ->whereIn('status', $this->completedPaymentStatuses())
            ->whereBetween(DB::raw('COALESCE(completed_at, payment_received_date, created_at)'), [$from, $to])
            ->when($request->filled('store_id'), function ($q) use ($request) {
                $q->where(function ($sq) use ($request) {
                    $sq->where('store_id', $request->store_id)
                        ->orWhereHas('order', fn ($oq) => $oq->where('store_id', $request->store_id));
                });
            })
            ->when($request->filled('order_type'), fn ($q) => $q->whereHas('order', fn ($oq) => $oq->where('order_type', $request->order_type)))
            ->when($request->filled('status'), fn ($q) => $q->whereHas('order', fn ($oq) => $oq->where('status', $request->status)))
            ->orderByDesc(DB::raw('COALESCE(completed_at, payment_received_date, created_at)'))
            ->get();

        $filename = 'payment-breakdown-' . now()->format('Y-m-d-His') . '.csv';
        $callback = function () use ($payments) {
            $file = fopen('php://output', 'w');
            $this->writeBom($file);
            fputcsv($file, [
                'Payment Date', 'Order Number', 'Order Date', 'Customer Name', 'Customer Phone', 'Store',
                'Order Type', 'Order Status', 'Report Scope', 'Payment Status', 'Payment Method', 'Payment Type',
                'Amount', 'Fee', 'Net Amount', 'Refunded Amount', 'Cash Ledger Impact', 'Sales Report Impact', 'Is Internal Credit',
                'Transaction Reference', 'External Reference', 'Notes',
            ]);

            $totalAmount = $cashImpact = $salesImpact = 0.0;
            foreach ($payments as $payment) {
                $rows = [];
                if ($payment->paymentSplits && $payment->paymentSplits->count() > 0) {
                    foreach ($payment->paymentSplits as $split) {
                        if (!in_array($split->status, $this->completedPaymentStatuses(), true)) continue;
                        $rows[] = [
                            'method' => $split->paymentMethod?->name ?? 'Split Payment',
                            'amount' => (float) $split->amount,
                            'fee' => (float) $split->fee_amount,
                            'net' => (float) $split->net_amount,
                            'refunded' => (float) ($split->refunded_amount ?? 0),
                            'tx' => $split->transaction_reference,
                            'ext' => $split->external_reference,
                        ];
                    }
                } else {
                    $rows[] = [
                        'method' => $payment->paymentMethod?->name ?? $payment->payment_type ?? 'N/A',
                        'amount' => (float) $payment->amount,
                        'fee' => (float) $payment->fee_amount,
                        'net' => (float) $payment->net_amount,
                        'refunded' => (float) ($payment->refunded_amount ?? 0),
                        'tx' => $payment->transaction_reference,
                        'ext' => $payment->external_reference,
                    ];
                }

                $orderStatus = (string) ($payment->order?->status ?? 'no_order');
                $countsAsSale = $payment->order && $this->isRealSaleStatus($orderStatus);
                $reportScope = $countsAsSale ? 'Sales + cash ledger' : 'Cash ledger only - excluded from sales totals';
                $isInternal = in_array((string) $payment->payment_type, $this->internalPaymentTypes, true);

                foreach ($rows as $row) {
                    $effective = max(0, $row['amount'] - $row['refunded']);
                    $cash = $isInternal ? 0 : $effective;
                    $saleImpact = ($countsAsSale && !$isInternal) ? $effective : 0;
                    $totalAmount += $effective;
                    $cashImpact += $cash;
                    $salesImpact += $saleImpact;
                    fputcsv($file, [
                        optional($payment->completed_at ?? $payment->payment_received_date ?? $payment->created_at)->format('Y-m-d H:i:s'),
                        $payment->order?->order_number ?? '',
                        $payment->order?->order_date ? $payment->order->order_date->format('Y-m-d H:i:s') : '',
                        $payment->order?->customer?->name ?? $payment->customer?->name ?? '',
                        $payment->order?->customer?->phone ?? $payment->customer?->phone ?? '',
                        $payment->store?->name ?? $payment->order?->store?->name ?? '',
                        $payment->order?->order_type ?? '',
                        $orderStatus,
                        $reportScope,
                        $payment->status,
                        $row['method'],
                        $payment->payment_type ?? 'full',
                        $this->money($row['amount']),
                        $this->money($row['fee']),
                        $this->money($row['net']),
                        $this->money($row['refunded']),
                        $this->money($cash),
                        $this->money($saleImpact),
                        $isInternal ? 'Yes' : 'No',
                        $row['tx'],
                        $row['ext'],
                        $payment->notes,
                    ]);
                }
            }

            fputcsv($file, []);
            fputcsv($file, ['TOTAL', '', '', '', '', '', '', '', '', '', '', '', $this->money($totalAmount), '', '', '', $this->money($cashImpact), $this->money($salesImpact), '', '', '', '']);
            fclose($file);
        };

        return Response::stream($callback, 200, $this->csvHeaders($filename));
    }

    /**
     * GET /api/reporting/csv/installments
     */
    public function exportInstallmentsCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'store_id' => 'nullable|exists:stores,id',
            'customer_id' => 'nullable|exists:customers,id',
            'payment_status' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = Order::query()
            ->with(['customer', 'store', 'payments.paymentMethod', 'payments.paymentSplits.paymentMethod'])
            ->where(function ($q) {
                $q->where('is_installment_payment', true)
                    ->orWhereHas('payments', fn ($pq) => $pq->where(function ($pqq) {
                        $pqq->where('is_partial_payment', true)
                            ->orWhereIn('payment_type', ['installment', 'partial', 'final']);
                    }));
            });

        $this->applyOrderScope($query, $request, 'orders');

        if ($request->filled('store_id')) $query->where('store_id', $request->store_id);
        if ($request->filled('customer_id')) $query->where('customer_id', $request->customer_id);
        if ($request->filled('payment_status')) $query->where('payment_status', $request->payment_status);

        $orders = $query->orderByDesc('order_date')->get();
        $filename = 'installments-report-' . now()->format('Y-m-d-His') . '.csv';

        $callback = function () use ($orders) {
            $file = fopen('php://output', 'w');
            $this->writeBom($file);
            fputcsv($file, [
                'Order Number', 'Order Date', 'Customer Name', 'Customer Phone', 'Store', 'Plan Type', 'Order Total',
                'Actual Paid Amount', 'Internal Credit Amount', 'Due Amount', 'Overpaid Amount', 'Expected Collection Amount',
                'Planned Installments', 'Collections Completed', 'Next Payment Due', 'Payment Status',
                'Completed Collection Payments', 'Latest Collection Payment Date', 'Payment Methods', 'Report Note',
            ]);

            foreach ($orders as $order) {
                [$actualPaid, $internalCredit, $methods] = $this->summarizeOrderPayments($order->payments ?? collect());
                $collectionPayments = ($order->payments ?? collect())->filter(fn ($p) =>
                    in_array($p->status, $this->completedPaymentStatuses(), true)
                    && ((bool) $p->is_partial_payment || in_array($p->payment_type, ['installment', 'partial', 'final'], true))
                );

                $latest = $collectionPayments->max(fn ($p) => $p->completed_at ?? $p->payment_received_date ?? $p->created_at);
                $completedCollectionAmount = (float) $collectionPayments->sum(fn ($p) => max(0, (float) $p->amount - (float) ($p->refunded_amount ?? 0)));

                $balance = (float) $order->total_amount - $actualPaid - $internalCredit;
                $due = max(0, $balance);
                $overpaid = max(0, -$balance);

                $isFormalInstallment = (bool) $order->is_installment_payment;
                $planType = $isFormalInstallment ? 'Formal installment plan' : 'Partial / advance collection';
                $expectedAmount = (float) ($order->installment_amount ?? 0);
                if ($expectedAmount <= 0) {
                    $expectedAmount = (float) ($order->minimum_payment_amount ?? 0);
                }
                if ($expectedAmount <= 0) {
                    $expectedAmount = (float) ($collectionPayments->first()?->expected_installment_amount ?? 0);
                }

                $plannedInstallments = (int) ($order->total_installments ?? 0);
                if ($plannedInstallments <= 0 && is_array($order->payment_schedule)) {
                    $plannedInstallments = count($order->payment_schedule);
                }

                $collectionsCompleted = (int) ($order->paid_installments ?? 0);
                if ($collectionsCompleted <= 0) {
                    if ($isFormalInstallment && $expectedAmount > 0) {
                        $collectionsCompleted = (int) floor($completedCollectionAmount / $expectedAmount);
                    } else {
                        $collectionsCompleted = $collectionPayments->count();
                    }
                }

                $reportNote = $isFormalInstallment
                    ? 'Plan fields are from order installment setup; completed count is recalculated if stored value is missing.'
                    : 'This order has partial/advance payments but no formal installment plan; plan fields are intentionally blank unless inferable.';

                fputcsv($file, [
                    $order->order_number,
                    $order->order_date ? $order->order_date->format('Y-m-d H:i:s') : '',
                    $order->customer?->name ?? '',
                    $order->customer?->phone ?? '',
                    $order->store?->name ?? '',
                    $planType,
                    $this->money($order->total_amount),
                    $this->money($actualPaid),
                    $this->money($internalCredit),
                    $this->money($due),
                    $this->money($overpaid),
                    $expectedAmount > 0 ? $this->money($expectedAmount) : '',
                    $plannedInstallments > 0 ? $plannedInstallments : '',
                    $collectionsCompleted,
                    $order->next_payment_due ? Carbon::parse($order->next_payment_due)->format('Y-m-d') : '',
                    $order->payment_status,
                    $this->money($completedCollectionAmount),
                    $latest ? Carbon::parse($latest)->format('Y-m-d H:i:s') : '',
                    implode(', ', array_unique($methods)),
                    $reportNote,
                ]);
            }
            fclose($file);
        };

        return Response::stream($callback, 200, $this->csvHeaders($filename));
    }

    /**
     * GET /api/reporting/daily-sales
     */
    public function getDailySalesReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $date = Carbon::parse($request->get('date', now()->format('Y-m-d')));
        $storeId = (int) $request->store_id;
        $store = \App\Models\Store::findOrFail($storeId);

        $payments = OrderPayment::query()
            ->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->orWhereHas('order', fn ($oq) => $oq->where('store_id', $storeId));
            })
            ->whereIn('status', ['completed', 'partially_refunded'])
            ->whereBetween(DB::raw('COALESCE(completed_at, payment_received_date, created_at)'), [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->with(['paymentMethod', 'paymentSplits.paymentMethod'])
            ->get();

        $totals = ['cash' => 0.0, 'card' => 0.0, 'bkash' => 0.0, 'nagad' => 0.0, 'other' => 0.0, 'internal_credit' => 0.0];

        foreach ($payments as $payment) {
            $isInternal = in_array((string) $payment->payment_type, $this->internalPaymentTypes, true);
            $rows = $payment->paymentSplits && $payment->paymentSplits->count() > 0 ? $payment->paymentSplits : collect([$payment]);
            foreach ($rows as $row) {
                $method = strtolower((string) ($row->paymentMethod?->name ?? $payment->paymentMethod?->name ?? $payment->payment_type ?? 'other'));
                $amount = max(0, (float) $row->amount - (float) ($row->refunded_amount ?? 0));
                if ($isInternal) {
                    $totals['internal_credit'] += $amount;
                } elseif (str_contains($method, 'cash')) {
                    $totals['cash'] += $amount;
                } elseif (str_contains($method, 'card')) {
                    $totals['card'] += $amount;
                } elseif (str_contains($method, 'bkash') || str_contains($method, 'bikash')) {
                    $totals['bkash'] += $amount;
                } elseif (str_contains($method, 'nagad') || str_contains($method, 'bank transfer')) {
                    $totals['nagad'] += $amount;
                } else {
                    $totals['other'] += $amount;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $date->format('Y-m-d'),
                'branch' => $store->name,
                'total_sales' => round($totals['cash'] + $totals['card'] + $totals['bkash'] + $totals['nagad'] + $totals['other'], 2),
                'cash' => round($totals['cash'], 2),
                'card' => round($totals['card'], 2),
                'bkash' => round($totals['bkash'], 2),
                'nagad' => round($totals['nagad'], 2),
                'other' => round($totals['other'], 2),
                'internal_credit' => round($totals['internal_credit'], 2),
            ],
        ]);
    }
}
