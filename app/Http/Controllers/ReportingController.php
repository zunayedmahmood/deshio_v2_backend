<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductReturn;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;

class ReportingController extends Controller
{
    /**
     * Export category-wise sales report as CSV
     * 
     * GET /api/reporting/csv/category-sales
     * 
     * Query Parameters:
     * - date_from: Start date (YYYY-MM-DD) - optional
     * - date_to: End date (YYYY-MM-DD) - optional
     * - store_id: Filter by specific store - optional
     * - status: Filter by order status (confirmed, pending_assignment, pending) - optional
     *           Default: includes confirmed and pending_assignment orders
     * 
     * Response: CSV file download with columns:
     * - Category
     * - Sold Qty
     * - SUB Total (quantity × unit_price)
     * - Discount Amount (total discounts applied)
     * - Exchange Amount (value of exchanged items)
     * - Return Amount (value of returned items)
     * - Net Sales (without VAT) = Subtotal - Discount - Returns - Exchanges
     * - VAT Amount (actual tax from orders)
     * - Net Amount (final revenue including VAT)
     * 
     * Accounting Formula:
     * - Subtotal = SUM(quantity × unit_price) for all items in category
     * - Net Sales = Subtotal - Discounts - Returns - Exchanges
     * - Net Amount = Net Sales + VAT
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
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Build query for order items joined with products and categories
        // NOTE: Using DB::table instead of Eloquent to avoid model cast issues with aggregations
        $query = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereNull('orders.deleted_at')
            ->whereNull('products.deleted_at')
            ->whereNull('categories.deleted_at');

        // Filter by order status (default: confirmed orders only)
        if ($request->filled('status')) {
            $query->where('orders.status', $request->status);
        } else {
            // Default: include confirmed and completed orders (real statuses from enum)
            $query->whereIn('orders.status', ['confirmed', 'processing', 'ready_for_pickup', 'shipped', 'delivered']);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('orders.order_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('orders.order_date', '<=', $request->date_to);
        }

        // Store filter
        if ($request->filled('store_id')) {
            $query->where('orders.store_id', $request->store_id);
        }

        // Group by category and aggregate sales data
        $categorySales = $query->select(
            'categories.id as category_id',
            'categories.title as category_name',
            DB::raw('SUM(order_items.quantity) as total_quantity'),
            DB::raw('SUM(CAST(order_items.quantity AS DECIMAL(10,2)) * CAST(order_items.unit_price AS DECIMAL(10,2))) as subtotal'),
            DB::raw('SUM(CAST(order_items.discount_amount AS DECIMAL(10,2))) as total_discount'),
            DB::raw('SUM(CAST(order_items.tax_amount AS DECIMAL(10,2))) as total_tax')
        )
        ->groupBy('categories.id', 'categories.title')
        ->get();

        // Calculate returns and refunds per category
        $categoryReturns = ProductReturn::query()
            ->join('orders', 'product_returns.order_id', '=', 'orders.id')
            ->whereIn('product_returns.status', ['approved', 'processed', 'completed'])
            ->when($request->filled('date_from'), function($q) use ($request) {
                $q->whereDate('product_returns.return_date', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function($q) use ($request) {
                $q->whereDate('product_returns.return_date', '<=', $request->date_to);
            })
            ->when($request->filled('store_id'), function($q) use ($request) {
                $q->where(function ($sq) use ($request) {
                    $sq->where('product_returns.store_id', $request->store_id)
                       ->orWhere('product_returns.received_at_store_id', $request->store_id);
                });
            })
            ->select(
                'product_returns.id',
                'product_returns.return_items',
                'product_returns.total_return_value'
            )
            ->get();

        // Process returns per category
        $returnsByCategory = [];
        foreach ($categoryReturns as $return) {
            $returnItems = is_string($return->return_items) 
                ? json_decode($return->return_items, true) 
                : $return->return_items;
            
            if (is_array($returnItems)) {
                foreach ($returnItems as $item) {
                    if (isset($item['product_id'])) {
                        $product = \App\Models\Product::find($item['product_id']);
                        if ($product && $product->category_id) {
                            if (!isset($returnsByCategory[$product->category_id])) {
                                $returnsByCategory[$product->category_id] = 0;
                            }
                            $returnsByCategory[$product->category_id] += ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
                        }
                    }
                }
            }
        }

        // Calculate refunds per category (for exchanges)
        $categoryRefunds = Refund::query()
            ->join('product_returns', 'refunds.return_id', '=', 'product_returns.id')
            ->join('orders', 'refunds.order_id', '=', 'orders.id')
            ->whereIn('refunds.status', ['completed', 'processed'])
            ->where(function ($q) {
                $q->where('refunds.refund_method', 'exchange')
                  ->orWhere('refunds.refund_type', 'exchange_refund');
            }) // Exchange transactions, including the current exchange_refund flow
            ->when($request->filled('date_from'), function($q) use ($request) {
                $q->whereDate('refunds.completed_at', '>=', $request->date_from);
            })
            ->when($request->filled('date_to'), function($q) use ($request) {
                $q->whereDate('refunds.completed_at', '<=', $request->date_to);
            })
            ->when($request->filled('store_id'), function($q) use ($request) {
                $q->where('orders.store_id', $request->store_id);
            })
            ->select(
                'refunds.id',
                'product_returns.return_items',
                'refunds.refund_amount'
            )
            ->get();

        // Process exchanges per category
        $exchangesByCategory = [];
        foreach ($categoryRefunds as $refund) {
            $returnItems = is_string($refund->return_items) 
                ? json_decode($refund->return_items, true) 
                : $refund->return_items;
            
            if (is_array($returnItems)) {
                foreach ($returnItems as $item) {
                    if (isset($item['product_id'])) {
                        $product = \App\Models\Product::find($item['product_id']);
                        if ($product && $product->category_id) {
                            if (!isset($exchangesByCategory[$product->category_id])) {
                                $exchangesByCategory[$product->category_id] = 0;
                            }
                            // Proportional exchange amount
                            $itemTotal = ($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0);
                            $exchangesByCategory[$product->category_id] += $itemTotal;
                        }
                    }
                }
            }
        }

        // Generate CSV
        $filename = 'category-sales-report-' . now()->format('Y-m-d-His') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($categorySales, $returnsByCategory, $exchangesByCategory) {
            $file = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 support
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // CSV Headers
            fputcsv($file, [
                'Category',
                'Sold Qty',
                'SUB Total',
                'Discount Amount',
                'Exchange Amount',
                'Return Amount',
                'Net Sales (without VAT)',
                'VAT Amount (7.5)',
                'Net Amount'
            ]);

            // CSV Rows
            foreach ($categorySales as $sale) {
                $categoryId = $sale->category_id;
                $subtotal = floatval($sale->subtotal);
                $discount = floatval($sale->total_discount);
                $taxAmount = floatval($sale->total_tax);
                
                $returnAmount = $returnsByCategory[$categoryId] ?? 0;
                $exchangeAmount = $exchangesByCategory[$categoryId] ?? 0;
                
                // Accounting Logic:
                // Net Sales (without VAT) = Subtotal - Discount - Returns - Exchanges
                $netSalesWithoutVAT = $subtotal - $discount - $returnAmount - $exchangeAmount;
                
                // VAT Amount: Use actual tax from order items
                $vatAmount = $taxAmount;
                
                // Net Amount = Net Sales + VAT (total revenue after all deductions)
                $netAmount = $netSalesWithoutVAT + $vatAmount;
                
                fputcsv($file, [
                    $sale->category_name,
                    number_format($sale->total_quantity, 0),
                    number_format($subtotal, 2),
                    number_format($discount, 2),
                    number_format($exchangeAmount, 2),
                    number_format($returnAmount, 2),
                    number_format($netSalesWithoutVAT, 2),
                    number_format($vatAmount, 2),
                    number_format($netAmount, 2),
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    /**
     * Export detailed sales report as CSV
     * 
     * GET /api/reporting/csv/sales
     * 
     * Query Parameters:
     * - date_from: Start date (YYYY-MM-DD) - optional
     * - date_to: End date (YYYY-MM-DD) - optional
     * - store_id: Filter by specific store - optional
     * - status: Filter by order status - optional
     * - customer_id: Filter by customer - optional
     * 
     * Response: CSV file download with order-level details
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
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Build query for orders with related data
        $query = Order::query()
            ->with(['customer', 'items.product', 'payments.paymentMethod', 'shipments'])
            ->whereNull('deleted_at');

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', $request->date_to);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $orders = $query->orderBy('order_date', 'desc')->get();

        // Generate CSV
        $filename = 'sales-report-' . now()->format('Y-m-d-His') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($orders) {
            $file = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 support
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // CSV Headers
            fputcsv($file, [
                'Creation Date',
                'Invoice Number',
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
                'Total Price',
                'Paid Amount',
                'Vat Amount',
                'Total Without Vat',
                'Due Amount',
                'Delivery Partner',
                'Delivery Area',
                'Payment Method',
                'Order Status'
            ]);

            // CSV Rows - One row per order
            foreach ($orders as $order) {
                // Customer info
                $customerName = $order->customer ? $order->customer->name : 'N/A';
                $customerPhone = $order->customer ? $order->customer->phone : 'N/A';
                
                // Customer address (from order's shipping_address or customer's address)
                $customerAddress = '';
                if ($order->shipping_address && is_array($order->shipping_address)) {
                    $addressParts = array_filter([
                        $order->shipping_address['street'] ?? $order->shipping_address['address_line_1'] ?? '',
                        $order->shipping_address['area'] ?? $order->shipping_address['address_line_2'] ?? '',
                        $order->shipping_address['city'] ?? '',
                    ]);
                    $customerAddress = implode(', ', $addressParts);
                } elseif ($order->customer) {
                    $customerAddress = $order->customer->address ?? '';
                }
                
                // Product details - concatenate all items
                $productNames = [];
                $productSpecs = [];
                $productAttrs = [];
                
                foreach ($order->items as $item) {
                    $productNames[] = ($item->product_name ?? 'Unknown') . ' (x' . $item->quantity . ')';
                    
                    // Product specification (custom fields)
                    $specs = [];
                    if ($item->product_options) {
                        $options = is_string($item->product_options) 
                            ? json_decode($item->product_options, true) 
                            : $item->product_options;
                        if (is_array($options)) {
                            foreach ($options as $key => $value) {
                                $specs[] = "$key: $value";
                            }
                        }
                    }
                    $productSpecs[] = !empty($specs) ? implode('; ', $specs) : 'N/A';
                    
                    // Product attributes (SKU, batch info, etc.)
                    $attrs = [];
                    if ($item->product_sku) {
                        $attrs[] = "SKU: {$item->product_sku}";
                    }
                    $productAttrs[] = !empty($attrs) ? implode('; ', $attrs) : 'N/A';
                }
                
                $productNameQty = implode(' | ', $productNames);
                $productSpec = implode(' | ', $productSpecs);
                $productAttr = implode(' | ', $productAttrs);
                
                // Financial calculations
                $subtotal = floatval($order->subtotal);
                $discount = floatval($order->discount_amount);
                $priceAfterDiscount = $subtotal - $discount;
                $deliveryCharge = floatval($order->shipping_amount);
                $totalPrice = floatval($order->total_amount);
                $paidAmount = floatval($order->paid_amount);
                $vatAmount = floatval($order->tax_amount);
                $totalWithoutVat = $totalPrice - $vatAmount;
                $dueAmount = floatval($order->outstanding_amount);
                
                // Delivery partner (from shipments)
                $deliveryPartner = 'N/A';
                $deliveryArea = '';
                
                if ($order->shipments && $order->shipments->count() > 0) {
                    $shipment = $order->shipments->first();
                    $deliveryPartner = $shipment->carrier_name ?? 'N/A';
                    
                    // Delivery area from shipping address
                    if ($order->shipping_address && is_array($order->shipping_address)) {
                        $deliveryArea = $order->shipping_address['area'] ?? $order->shipping_address['city'] ?? '';
                    }
                } elseif ($order->shipping_address && is_array($order->shipping_address)) {
                    $deliveryArea = $order->shipping_address['area'] ?? $order->shipping_address['city'] ?? '';
                }
                
                // Payment method (from payments)
                $paymentMethods = [];
                if ($order->payments && $order->payments->count() > 0) {
                    foreach ($order->payments as $payment) {
                        if ($payment->paymentMethod) {
                            $paymentMethods[] = $payment->paymentMethod->name;
                        } elseif ($payment->payment_method) {
                            $paymentMethods[] = $payment->payment_method;
                        }
                    }
                }
                $paymentMethod = !empty($paymentMethods) ? implode(', ', array_unique($paymentMethods)) : 'N/A';
                
                // Write row
                fputcsv($file, [
                    $order->order_date ? $order->order_date->format('Y-m-d H:i:s') : 'N/A',
                    $order->order_number ?? 'N/A',
                    $customerName,
                    $customerPhone,
                    $customerAddress,
                    $productNameQty,
                    $productSpec,
                    $productAttr,
                    number_format($subtotal, 2),
                    number_format($discount, 2),
                    number_format($priceAfterDiscount, 2),
                    number_format($deliveryCharge, 2),
                    number_format($totalPrice, 2),
                    number_format($paidAmount, 2),
                    number_format($vatAmount, 2),
                    number_format($totalWithoutVat, 2),
                    number_format($dueAmount, 2),
                    $deliveryPartner,
                    $deliveryArea,
                    $paymentMethod,
                    ucfirst(str_replace('_', ' ', $order->status ?? 'N/A')),
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    /**
     * Export stock report as CSV
     * 
     * GET /api/reporting/csv/stock
     * 
     * Query Parameters:
     * - store_id: Filter by specific store - optional
     * - category_id: Filter by category - optional
     * - product_id: Filter by product - optional
     * - include_inactive: Include inactive batches (default: false) - optional
     * 
     * Response: CSV file download with product stock details including:
     * - Category, Product Code (SKU), Product Name, Product Brand, Product Description
     * - Sold Quantity (total sold from this batch)
     * - Sub Total (total sales revenue from this batch)
     * - Remaining Stock Quantity
     * - Stock Volume (remaining quantity × sell price)
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
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Build query for product batches with relationships
        $query = ProductBatch::query()
            ->with(['product.category', 'store']);

        // Join products to access category and product details
        $query->join('products', 'product_batches.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at');

        // Filters
        if ($request->filled('store_id')) {
            $query->where('product_batches.store_id', $request->store_id);
        }

        if ($request->filled('category_id')) {
            $query->where('products.category_id', $request->category_id);
        }

        if ($request->filled('product_id')) {
            $query->where('product_batches.product_id', $request->product_id);
        }

        // By default, only show active batches
        if (!$request->boolean('include_inactive')) {
            $query->where('product_batches.is_active', true);
        }

        // Select batch fields
        $query->select('product_batches.*');

        $batches = $query->orderBy('products.category_id')
            ->orderBy('products.sku')
            ->orderBy('product_batches.batch_number')
            ->get();

        // Calculate sold quantities for each batch
        $batchIds = $batches->pluck('id')->toArray();
        
        $soldQuantities = [];
        $soldSubtotals = [];
        
        if (!empty($batchIds)) {
            $orderItemsData = OrderItem::query()
                ->whereIn('product_batch_id', $batchIds)
                ->whereHas('order', function($q) {
                    $q->whereNull('deleted_at');
                })
                ->selectRaw('product_batch_id, SUM(quantity) as total_sold, SUM(total_amount) as total_revenue')
                ->groupBy('product_batch_id')
                ->get()
                ->keyBy('product_batch_id');
            
            foreach ($orderItemsData as $batchId => $data) {
                $soldQuantities[$batchId] = $data->total_sold;
                $soldSubtotals[$batchId] = $data->total_revenue;
            }
        }

        // Generate CSV
        $filename = 'stock-report-' . now()->format('Y-m-d-His') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($batches, $soldQuantities, $soldSubtotals) {
            $file = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 support
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // CSV Headers
            fputcsv($file, [
                'Category',
                'Product Code',
                'Product Name',
                'Product Brand',
                'Product Description',
                'Batch Number',
                'Sold Quantity',
                'Sub Total',
                'Remaining Stock Quantity',
                'Stock Volume',
                'Store',
            ]);

            // CSV Rows - One row per batch
            foreach ($batches as $batch) {
                $product = $batch->product;
                
                // Category
                $categoryName = $product && $product->category ? $product->category->title : 'N/A';
                
                // Product identification
                $productCode = $product ? $product->sku : 'N/A';
                $productName = $product ? $product->name : 'N/A';
                $productBrand = $product && $product->brand ? $product->brand : 'N/A';
                $productDescription = $product && $product->description ? $product->description : 'N/A';
                
                // Batch number
                $batchNumber = $batch->batch_number ?? 'N/A';
                
                // Sold quantity and subtotal
                $soldQty = $soldQuantities[$batch->id] ?? 0;
                $soldSubtotal = $soldSubtotals[$batch->id] ?? 0;
                
                // Remaining stock
                $remainingStock = floatval($batch->quantity);
                
                // Stock volume = remaining quantity × sell price
                $sellPrice = floatval($batch->sell_price);
                $stockVolume = $remainingStock * $sellPrice;
                
                // Store name
                $storeName = $batch->store ? $batch->store->name : 'N/A';
                
                // Write row
                fputcsv($file, [
                    $categoryName,
                    $productCode,
                    $productName,
                    $productBrand,
                    $productDescription,
                    $batchNumber,
                    number_format($soldQty, 0),
                    number_format($soldSubtotal, 2),
                    number_format($remainingStock, 0),
                    number_format($stockVolume, 2),
                    $storeName,
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    /**
     * Export booking (order items) report as CSV
     * 
     * GET /api/reporting/csv/booking
     * 
     * Query Parameters:
     * - date_from: Start date (YYYY-MM-DD) - optional
     * - date_to: End date (YYYY-MM-DD) - optional
     * - store_id: Filter by specific store - optional
     * - status: Filter by order status - optional
     * - customer_id: Filter by customer - optional
     * - product_id: Filter by product - optional
     * 
     * Response: CSV file download with booking details including:
     * - Order Number, Customer Name, Customer Phone, Customer Code
     * - Product Name, Product Code (SKU), Product Barcode, Quantity
     * - Selling Price, Cost Price (from batch)
     * - Payable (order total), Paid Amount, Due Amount
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
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Build query for order items with related data
        $query = OrderItem::query()
            ->with(['order.customer', 'product', 'batch', 'barcode'])
            ->whereHas('order', function($q) use ($request) {
                $q->whereNull('deleted_at');
                
                // Filters on order
                if ($request->filled('status')) {
                    $q->where('status', $request->status);
                }
                
                if ($request->filled('date_from')) {
                    $q->whereDate('order_date', '>=', $request->date_from);
                }
                
                if ($request->filled('date_to')) {
                    $q->whereDate('order_date', '<=', $request->date_to);
                }
                
                if ($request->filled('customer_id')) {
                    $q->where('customer_id', $request->customer_id);
                }
            });

        // Filters on order items
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $orderItems = $query->orderBy('created_at', 'desc')->get();

        // Generate CSV
        $filename = 'booking-report-' . now()->format('Y-m-d-His') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($orderItems) {
            $file = fopen('php://output', 'w');
            
            // Add BOM for Excel UTF-8 support
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // CSV Headers
            fputcsv($file, [
                'Order Number',
                'Order Date',
                'Customer Name',
                'Customer Phone',
                'Customer Code',
                'Product Name',
                'Product Code (SKU)',
                'Product Barcode',
                'Batch Number',
                'Quantity',
                'Selling Price',
                'Cost Price',
                'Item Subtotal',
                'Payable (Order Total)',
                'Paid Amount',
                'Due Amount',
            ]);

            // CSV Rows - One row per order item
            foreach ($orderItems as $item) {
                $order = $item->order;
                $customer = $order ? $order->customer : null;
                $product = $item->product;
                $batch = $item->batch;
                $barcode = $item->barcode;
                
                // Customer info
                $orderNumber = $order ? $order->order_number : 'N/A';
                $orderDate = $order && $order->order_date ? $order->order_date->format('Y-m-d H:i:s') : 'N/A';
                $customerName = $customer ? $customer->name : 'N/A';
                $customerPhone = $customer ? $customer->phone : 'N/A';
                $customerCode = $customer ? ($customer->customer_code ?? 'N/A') : 'N/A';
                
                // Product info
                $productName = $item->product_name ?? 'N/A';
                $productSku = $item->product_sku ?? 'N/A';
                $productBarcode = $barcode ? $barcode->barcode : 'N/A';
                $batchNumber = $batch ? $batch->batch_number : 'N/A';
                
                // Quantity
                $quantity = floatval($item->quantity);
                
                // Pricing from batch
                $sellingPrice = $batch ? floatval($batch->sell_price) : 0;
                $costPrice = $batch ? floatval($batch->cost_price) : 0;
                
                // Item subtotal
                $itemSubtotal = floatval($item->total_amount);
                
                // Order financial data
                $payable = $order ? floatval($order->total_amount) : 0;
                $paid = $order ? floatval($order->paid_amount) : 0;
                $due = $order ? floatval($order->outstanding_amount) : 0;
                
                // Write row
                fputcsv($file, [
                    $orderNumber,
                    $orderDate,
                    $customerName,
                    $customerPhone,
                    $customerCode,
                    $productName,
                    $productSku,
                    $productBarcode,
                    $batchNumber,
                    number_format($quantity, 0),
                    number_format($sellingPrice, 2),
                    number_format($costPrice, 2),
                    number_format($itemSubtotal, 2),
                    number_format($payable, 2),
                    number_format($paid, 2),
                    number_format($due, 2),
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    /**
     * Export payment breakdown report as CSV.
     *
     * The old report queried orders by order_date, so a later installment collection
     * for an older order could be missed. This current-aware version uses completed
     * payment/refund dates for cash-flow tracking while keeping the legacy columns.
     */
    public function exportPaymentBreakdownCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'today' => 'nullable|boolean',
            'store_id' => 'nullable|exists:stores,id',
            'order_type' => 'nullable|in:counter,ecommerce,social_commerce,service',
            'status' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $from = $request->boolean('today') ? today()->toDateString() : $request->date_from;
        $to = $request->boolean('today') ? today()->toDateString() : $request->date_to;

        $paymentsQuery = \App\Models\OrderPayment::query()
            ->with(['order.customer', 'order.items.product', 'order.store', 'paymentMethod'])
            ->where('status', 'completed')
            ->whereNull('deleted_at');

        if ($from) {
            $paymentsQuery->whereDate(DB::raw('COALESCE(completed_at, payment_received_date, processed_at, created_at)'), '>=', $from);
        }
        if ($to) {
            $paymentsQuery->whereDate(DB::raw('COALESCE(completed_at, payment_received_date, processed_at, created_at)'), '<=', $to);
        }
        if ($request->filled('store_id')) {
            $paymentsQuery->where('store_id', $request->store_id);
        }
        if ($request->filled('order_type')) {
            $paymentsQuery->whereHas('order', fn($q) => $q->where('order_type', $request->order_type));
        }
        if ($request->filled('status')) {
            $paymentsQuery->whereHas('order', fn($q) => $q->where('status', $request->status));
        }

        $payments = $paymentsQuery
            ->orderBy(DB::raw('COALESCE(completed_at, payment_received_date, processed_at, created_at)'), 'desc')
            ->get();

        $refundsQuery = Refund::query()
            ->with(['order.customer', 'order.items.product', 'order.store', 'returnRequest'])
            ->where('status', 'completed');

        if ($from) {
            $refundsQuery->whereDate(DB::raw('COALESCE(completed_at, updated_at, created_at)'), '>=', $from);
        }
        if ($to) {
            $refundsQuery->whereDate(DB::raw('COALESCE(completed_at, updated_at, created_at)'), '<=', $to);
        }
        if ($request->filled('store_id')) {
            $storeId = $request->store_id;
            $refundsQuery->where(function ($q) use ($storeId) {
                $q->whereHas('order', fn($oq) => $oq->where('store_id', $storeId))
                  ->orWhereHas('returnRequest', fn($rq) => $rq->where('received_at_store_id', $storeId));
            });
        }
        if ($request->filled('order_type')) {
            $refundsQuery->whereHas('order', fn($q) => $q->where('order_type', $request->order_type));
        }
        if ($request->filled('status')) {
            $refundsQuery->whereHas('order', fn($q) => $q->where('status', $request->status));
        }

        $refunds = $refundsQuery
            ->orderBy(DB::raw('COALESCE(completed_at, updated_at, created_at)'), 'desc')
            ->get();

        $filename = 'payment-breakdown-' . now()->format('Y-m-d-His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($payments, $refunds) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($file, [
                'Date',
                'Invoice Number',
                'Store/Branch',
                'Customer Name',
                'Customer Phone',
                'Customer Address',
                'Product Name',
                'Quantity',
                'Cash Paid',
                'Bkash/Mobile Banking Paid',
                'Bank Paid',
                'Refund / Cash Out',
                'Due',
                'System (Online/POS)',
                'Order Status',
                'Cash Flow Type',
                'Payment Type',
                'Reference',
            ]);

            foreach ($payments as $payment) {
                $order = $payment->order;
                if (!$order) {
                    continue;
                }

                $paymentType = $payment->payment_type ?? 'payment';
                $isInternalExchangeCredit = in_array($paymentType, ['exchange_balance'], true)
                    || (($payment->paymentMethod?->code ?? null) === 'exchange_balance');

                $cashPaid = 0;
                $mobileBankingPaid = 0;
                $bankPaid = 0;

                if (!$isInternalExchangeCredit) {
                    $amount = (float) $payment->amount;
                    $methodType = $payment->paymentMethod->type ?? '';
                    $methodCode = strtolower($payment->paymentMethod->code ?? '');
                    $methodName = strtolower($payment->paymentMethod->name ?? '');

                    if (!$payment->paymentMethod || !$payment->payment_method_id || $methodType === 'cash' || $methodCode === 'cash') {
                        $cashPaid += $amount;
                    } elseif ($methodType === 'mobile_banking' || str_contains($methodName, 'bkash') || str_contains($methodName, 'nagad') || str_contains($methodName, 'rocket') || str_contains($methodName, 'upay')) {
                        $mobileBankingPaid += $amount;
                    } elseif (in_array($methodType, ['card', 'bank_transfer', 'online_banking', 'digital_wallet'], true)) {
                        $bankPaid += $amount;
                    } else {
                        $cashPaid += $amount;
                    }
                }

                $customerName = $order->customer ? $order->customer->name : 'Walk-in Customer';
                $customerPhone = $order->customer ? $order->customer->phone : 'N/A';
                $customerAddress = '';
                if ($order->shipping_address && is_array($order->shipping_address)) {
                    $customerAddress = implode(', ', array_filter([
                        $order->shipping_address['street'] ?? $order->shipping_address['address_line_1'] ?? '',
                        $order->shipping_address['area'] ?? $order->shipping_address['address_line_2'] ?? '',
                        $order->shipping_address['city'] ?? '',
                    ]));
                } elseif ($order->customer && $order->customer->address) {
                    $customerAddress = $order->customer->address;
                }

                $productNames = [];
                foreach ($order->items as $item) {
                    $productNames[] = ($item->product_name ?? $item->product->name ?? 'Unknown') . ' (x' . $item->quantity . ')';
                }

                $systemType = match($order->order_type) {
                    'counter' => 'POS',
                    'ecommerce' => 'Online (E-commerce)',
                    'social_commerce' => 'Online (Social)',
                    'service' => 'Service Order',
                    default => ucfirst($order->order_type ?? 'N/A'),
                };

                fputcsv($file, [
                    optional($payment->completed_at ?? $payment->payment_received_date ?? $payment->processed_at ?? $payment->created_at)->format('Y-m-d H:i:s') ?: 'N/A',
                    $order->order_number ?? 'N/A',
                    $order->store->name ?? 'N/A',
                    $customerName,
                    $customerPhone,
                    $customerAddress,
                    implode(', ', $productNames),
                    $order->items->sum('quantity'),
                    number_format($cashPaid, 2),
                    number_format($mobileBankingPaid, 2),
                    number_format($bankPaid, 2),
                    number_format(0, 2),
                    number_format((float) $order->outstanding_amount, 2),
                    $systemType,
                    ucfirst(str_replace('_', ' ', $order->status ?? 'N/A')),
                    $isInternalExchangeCredit ? 'Internal Exchange Credit' : 'Cash In',
                    ucfirst(str_replace('_', ' ', $paymentType)),
                    $payment->transaction_reference ?? $payment->external_reference ?? '',
                ]);
            }

            foreach ($refunds as $refund) {
                $order = $refund->order;
                if (!$order) {
                    continue;
                }

                $customer = $order->customer;
                $return = $refund->returnRequest;
                $productNames = [];
                foreach ($order->items as $item) {
                    $productNames[] = ($item->product_name ?? $item->product->name ?? 'Unknown') . ' (x' . $item->quantity . ')';
                }

                $systemType = match($order->order_type) {
                    'counter' => 'POS',
                    'ecommerce' => 'Online (E-commerce)',
                    'social_commerce' => 'Online (Social)',
                    'service' => 'Service Order',
                    default => ucfirst($order->order_type ?? 'N/A'),
                };

                fputcsv($file, [
                    optional($refund->completed_at ?? $refund->updated_at ?? $refund->created_at)->format('Y-m-d H:i:s') ?: 'N/A',
                    $order->order_number ?? 'N/A',
                    $order->store->name ?? 'N/A',
                    $customer->name ?? 'Walk-in Customer',
                    $customer->phone ?? 'N/A',
                    $customer->address ?? '',
                    implode(', ', $productNames),
                    $order->items->sum('quantity'),
                    number_format(0, 2),
                    number_format(0, 2),
                    number_format(0, 2),
                    number_format((float) $refund->refund_amount, 2),
                    number_format((float) $order->outstanding_amount, 2),
                    $systemType,
                    ucfirst(str_replace('_', ' ', $order->status ?? 'N/A')),
                    $refund->refund_type === 'exchange_refund' ? 'Exchange Refund / Cash Out' : 'Return Refund / Cash Out',
                    ucfirst(str_replace('_', ' ', $refund->refund_method ?? 'refund')),
                    $refund->payment_reference ?? ($return->return_number ?? ''),
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }


    /**
     * Export customer installment/partial payment report as CSV
     * 
     * GET /api/reporting/csv/installments
     * 
     * Generates detailed report of all orders with installment/partial payments.
     * Shows customer information, order details, payment history, and outstanding balances.
     * Each row represents one payment made by a customer.
     * 
     * Filters:
     * - date_from, date_to: Order date range (optional)
     * - customer_id: Specific customer (optional)
     * - store_id: Specific store (optional)
     * - payment_status: Filter by payment status (optional: unpaid, partial, paid, overdue)
     * 
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function exportInstallmentsCsv(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'customer_id' => 'nullable|exists:customers,id',
            'store_id' => 'nullable|exists:stores,id',
            'payment_status' => 'nullable|in:unpaid,partial,paid,overdue',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Query orders with partial payments or installments
        $query = Order::with([
            'customer',
            'store',
            'items.product',
            'payments' => function($q) {
                $q->whereNull('deleted_at')->orderBy('payment_received_date', 'asc');
            }
        ])
        ->where(function($q) {
            $q->where('allow_partial_payments', true)
              ->orWhere('is_installment_payment', true)
              ->orWhere('payment_status', 'partial');
        });

        // Apply filters
        if ($request->filled('date_from')) {
            $query->whereDate('order_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('order_date', '<=', $request->date_to);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        $orders = $query->orderBy('order_date', 'desc')->get();

        $filename = "Installment-Report-" . now()->format('Y-m-d') . ".csv";

        return response()->stream(function() use ($orders) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // CSV Header
            fputcsv($file, [
                'Order Number',
                'Order Date',
                'Store',
                'Customer Name',
                'Customer Phone',
                'Customer Email',
                'Customer Address',
                'Products',
                'Total Items',
                'Order Total',
                'Total Paid',
                'Outstanding',
                'Payment Status',
                'Next Payment Due',
                'Payment Number',
                'Payment Date',
                'Payment Amount',
                'Payment Method',
                'Payment Type',
                'Installment Number',
                'Balance Before',
                'Balance After',
                'Processed By',
                'Payment Notes',
            ]);

            // Process each order
            foreach ($orders as $order) {
                $customer = $order->customer;
                $store = $order->store;
                
                // Product summary
                $products = [];
                $totalItems = 0;
                foreach ($order->items as $item) {
                    $products[] = ($item->product->name ?? 'N/A') . ' (x' . $item->quantity . ')';
                    $totalItems += $item->quantity;
                }
                $productsSummary = implode(', ', $products);

                // Payment status display
                $paymentStatus = ucfirst($order->payment_status ?? 'N/A');
                
                // If order has no payments yet, show order info with empty payment fields
                if ($order->payments->isEmpty()) {
                    fputcsv($file, [
                        $order->order_number,
                        $order->order_date ? $order->order_date->format('Y-m-d H:i') : '',
                        $store->name ?? '',
                        $customer->name ?? '',
                        $customer->phone ?? '',
                        $customer->email ?? '',
                        $customer->address ?? '',
                        $productsSummary,
                        $totalItems,
                        number_format($order->total_amount, 2),
                        number_format($order->paid_amount, 2),
                        number_format($order->outstanding_amount, 2),
                        $paymentStatus,
                        $order->next_payment_due ? $order->next_payment_due->format('Y-m-d') : '',
                        'NO PAYMENTS YET',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                    ]);
                } else {
                    // One row per payment
                    foreach ($order->payments as $payment) {
                        fputcsv($file, [
                            $order->order_number,
                            $order->order_date ? $order->order_date->format('Y-m-d H:i') : '',
                            $store->name ?? '',
                            $customer->name ?? '',
                            $customer->phone ?? '',
                            $customer->email ?? '',
                            $customer->address ?? '',
                            $productsSummary,
                            $totalItems,
                            number_format($order->total_amount, 2),
                            number_format($order->paid_amount, 2),
                            number_format($order->outstanding_amount, 2),
                            $paymentStatus,
                            $order->next_payment_due ? $order->next_payment_due->format('Y-m-d') : '',
                            $payment->payment_number ?? '',
                            $payment->completed_at ? $payment->completed_at->format('Y-m-d H:i') : ($payment->processed_at ? $payment->processed_at->format('Y-m-d H:i') : ''),
                            number_format($payment->amount ?? 0, 2),
                            $payment->paymentMethod->name ?? 'N/A',
                            ucfirst($payment->payment_type ?? 'N/A'),
                            $payment->installment_number ?? '',
                            number_format($payment->order_balance_before ?? 0, 2),
                            number_format($payment->order_balance_after ?? 0, 2),
                            $payment->processedBy->name ?? '',
                            $payment->notes ?? '',
                        ]);
                    }
                }
            }

            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export Order Details CSV with Date Range
     * Report 7.1: Order details with products and customer information
     * Supports date range filtering and optional store filter
     */
    public function exportOrderDetailsCsv(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'store_id' => 'nullable|exists:stores,id',
        ]);

        $startDate = $request->start_date;
        $endDate = $request->end_date;
        $storeId = $request->store_id;

        // Build orders query with relationships
        $ordersQuery = Order::with([
            'customer',
            'store',
            'items.product',
            'items.batch',  // Fixed: correct relationship name
            'payments.paymentMethod',
            'createdBy',
            'processedBy'
        ])
        ->whereBetween('order_date', [$startDate, $endDate])
        ->whereIn('status', ['confirmed', 'processing', 'ready_for_pickup', 'shipped', 'delivered']);

        // Apply store filter if specified
        if ($storeId) {
            $ordersQuery->where('store_id', $storeId);
        }

        $orders = $ordersQuery->orderBy('order_date', 'desc')->get();

        // Generate filename
        $dateLabel = date('Ymd', strtotime($startDate)) . '_' . date('Ymd', strtotime($endDate));
        $storeLabel = $storeId ? "_store{$storeId}" : '_all_stores';
        $filename = "order_details_{$dateLabel}{$storeLabel}.csv";

        return response()->stream(function () use ($orders) {
            $file = fopen('php://output', 'w');

            // CSV Header
            fputcsv($file, [
                'Order Number',
                'Order Date',
                'Store Name',
                'Store Type',
                'Customer Name',
                'Customer Email',
                'Customer Phone',
                'Order Type',
                'Status',
                'Payment Status',
                'Payment Method',
                'Product Name',
                'Product SKU',
                'Batch Number',
                'Quantity',
                'Unit Price',
                'Discount',
                'Line Total',
                'Order Subtotal',
                'Order Tax',
                'Order Discount',
                'Order Shipping',
                'Order Total',
                'Paid Amount',
                'Outstanding Amount',
                'Is Installment',
                'Created By',
                'Processed By',
                'Tracking Number',
                'Notes',
            ]);

            // Data rows - one row per order item
            foreach ($orders as $order) {
                $storeName = $order->store->name ?? 'N/A';
                $storeType = $order->store 
                    ? ($order->store->is_online ? 'Online/Social' : 'Physical Store') 
                    : 'N/A';
                $customerName = $order->customer->name ?? 'Guest';
                $customerEmail = $order->customer->email ?? 'N/A';
                $customerPhone = $order->customer->phone ?? 'N/A';

                if ($order->items->isEmpty()) {
                    // Order with no items - output one row with order info
                    fputcsv($file, [
                        $order->order_number,
                        $order->order_date ? $order->order_date->format('Y-m-d H:i:s') : '',
                        $storeName,
                        $storeType,
                        $customerName,
                        $customerEmail,
                        $customerPhone,
                        ucfirst($order->order_type ?? 'standard'),
                        ucfirst($order->status),
                        ucfirst($order->payment_status ?? 'unpaid'),
                        $order->payment_method ?? 'N/A',
                        'No Items',
                        'N/A',
                        'N/A',
                        0,
                        0,
                        0,
                        0,
                        number_format($order->subtotal ?? 0, 2),
                        number_format($order->tax_amount ?? 0, 2),
                        number_format($order->discount_amount ?? 0, 2),
                        number_format($order->shipping_amount ?? 0, 2),
                        number_format($order->total_amount ?? 0, 2),
                        number_format($order->paid_amount ?? 0, 2),
                        number_format($order->outstanding_amount ?? 0, 2),
                        $order->is_installment_payment ? 'Yes' : 'No',
                        $order->createdBy->name ?? 'System',
                        $order->processedBy->name ?? 'N/A',
                        $order->tracking_number ?? '',
                        $order->notes ?? '',
                    ]);
                } else {
                    // Output one row per order item
                    foreach ($order->items as $item) {
                        $productName = $item->product->name ?? 'N/A';
                        $productSku = $item->product->sku ?? 'N/A';
                        $batchNumber = $item->batch->batch_number ?? 'N/A';  // Fixed: correct relationship

                        fputcsv($file, [
                            $order->order_number,
                            $order->order_date ? $order->order_date->format('Y-m-d H:i:s') : '',
                            $storeName,
                            $storeType,
                            $customerName,
                            $customerEmail,
                            $customerPhone,
                            ucfirst($order->order_type ?? 'standard'),
                            ucfirst($order->status),
                            ucfirst($order->payment_status ?? 'unpaid'),
                            $order->payment_method ?? 'N/A',
                            $productName,
                            $productSku,
                            $batchNumber,
                            $item->quantity,
                            number_format($item->unit_price ?? 0, 2),
                            number_format($item->discount_amount ?? 0, 2),
                            number_format(($item->unit_price * $item->quantity) - ($item->discount_amount ?? 0), 2),
                            number_format($order->subtotal ?? 0, 2),
                            number_format($order->tax_amount ?? 0, 2),
                            number_format($order->discount_amount ?? 0, 2),
                            number_format($order->shipping_amount ?? 0, 2),
                            number_format($order->total_amount ?? 0, 2),
                            number_format($order->paid_amount ?? 0, 2),
                            number_format($order->outstanding_amount ?? 0, 2),
                            $order->is_installment_payment ? 'Yes' : 'No',
                            $order->createdBy->name ?? 'System',
                            $order->processedBy->name ?? 'N/A',
                            $order->tracking_number ?? '',
                            $order->notes ?? '',
                        ]);
                    }
                }
            }

            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export Customer Purchase History CSV
     * Report 7.2: List of customers with complete purchase history
     * Shows all orders from any store/channel grouped by customer
     */
    public function exportCustomerHistoryCsv(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'customer_id' => 'nullable|exists:customers,id',
        ]);

        // Build customers query
        $customersQuery = Customer::with([
            'orders' => function ($query) use ($request) {
                $query->with(['store', 'items.product', 'payments'])
                    ->whereIn('status', ['confirmed', 'processing', 'ready_for_pickup', 'shipped', 'delivered'])
                    ->orderBy('order_date', 'desc');

                // Apply date filter if specified
                if ($request->start_date && $request->end_date) {
                    $query->whereBetween('order_date', [$request->start_date, $request->end_date]);
                }
            }
        ]);

        // Filter by specific customer if requested
        if ($request->customer_id) {
            $customersQuery->where('id', $request->customer_id);
        }

        $customers = $customersQuery->has('orders')->get();

        // Generate filename
        $dateLabel = ($request->start_date && $request->end_date) 
            ? date('Ymd', strtotime($request->start_date)) . '_' . date('Ymd', strtotime($request->end_date))
            : 'all_time';
        $customerLabel = $request->customer_id ? "_customer{$request->customer_id}" : '_all_customers';
        $filename = "customer_purchase_history_{$dateLabel}{$customerLabel}.csv";

        return response()->stream(function () use ($customers) {
            $file = fopen('php://output', 'w');

            // CSV Header
            fputcsv($file, [
                'Customer ID',
                'Customer Name',
                'Customer Email',
                'Customer Phone',
                'Order Number',
                'Order Date',
                'Store Name',
                'Store Type',
                'Order Type',
                'Status',
                'Payment Status',
                'Payment Method',
                'Product Count',
                'Total Items Qty',
                'Order Total',
                'Paid Amount',
                'Outstanding Amount',
                'Is Installment',
                'Tracking Number',
            ]);

            // Data rows - one row per order, grouped by customer
            foreach ($customers as $customer) {
                foreach ($customer->orders as $order) {
                    $storeName = $order->store->name ?? 'N/A';
                    $storeType = $order->store 
                        ? ($order->store->is_online ? 'Online/Social' : 'Physical Store') 
                        : 'N/A';
                    
                    $productCount = $order->items->count();
                    $totalQty = $order->items->sum('quantity');

                    fputcsv($file, [
                        $customer->id,
                        $customer->name,
                        $customer->email ?? 'N/A',
                        $customer->phone ?? 'N/A',
                        $order->order_number,
                        $order->order_date ? $order->order_date->format('Y-m-d H:i:s') : '',
                        $storeName,
                        $storeType,
                        ucfirst($order->order_type ?? 'standard'),
                        ucfirst($order->status),
                        ucfirst($order->payment_status ?? 'unpaid'),
                        $order->payment_method ?? 'N/A',
                        $productCount,
                        $totalQty,
                        number_format($order->total_amount ?? 0, 2),
                        number_format($order->paid_amount ?? 0, 2),
                        number_format($order->outstanding_amount ?? 0, 2),
                        $order->is_installment_payment ? 'Yes' : 'No',
                        $order->tracking_number ?? '',
                    ]);
                }
            }

            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Export Customer Purchase Summary CSV
     * Report 7.3: Customer summary with purchase count and total spending
     * Aggregated statistics per customer
     */
    public function exportCustomerSummaryCsv(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'min_orders' => 'nullable|integer|min:0',
            'min_amount' => 'nullable|numeric|min:0',
        ]);

        // Build base query with order aggregations
        $customersQuery = Customer::select([
            'customers.id',
            'customers.name',
            'customers.email',
            'customers.phone',
            'customers.created_at',
        ])
        ->leftJoin('orders', function ($join) use ($request) {
            $join->on('customers.id', '=', 'orders.customer_id')
                ->whereIn('orders.status', ['confirmed', 'processing', 'ready_for_pickup', 'shipped', 'delivered']);
            
            // Apply date filter if specified
            if ($request->start_date && $request->end_date) {
                $join->whereBetween('orders.order_date', [$request->start_date, $request->end_date]);
            }
        })
        ->groupBy('customers.id', 'customers.name', 'customers.email', 'customers.phone', 'customers.created_at')
        ->selectRaw('COUNT(DISTINCT orders.id) as total_orders')
        ->selectRaw('COALESCE(SUM(orders.total_amount), 0) as total_spent')
        ->selectRaw('COALESCE(SUM(orders.paid_amount), 0) as total_paid')
        ->selectRaw('COALESCE(AVG(orders.total_amount), 0) as average_order_value')
        ->selectRaw('MIN(orders.order_date) as first_order_date')
        ->selectRaw('MAX(orders.order_date) as last_order_date');

        // Apply filters
        if ($request->min_orders) {
            $customersQuery->having('total_orders', '>=', $request->min_orders);
        }

        if ($request->min_amount) {
            $customersQuery->having('total_spent', '>=', $request->min_amount);
        }

        $customers = $customersQuery->orderBy('total_spent', 'desc')->get();

        // Generate filename
        $dateLabel = ($request->start_date && $request->end_date) 
            ? date('Ymd', strtotime($request->start_date)) . '_' . date('Ymd', strtotime($request->end_date))
            : 'all_time';
        $filename = "customer_purchase_summary_{$dateLabel}.csv";

        return response()->stream(function () use ($customers) {
            $file = fopen('php://output', 'w');

            // CSV Header
            fputcsv($file, [
                'Customer ID',
                'Customer Name',
                'Email',
                'Phone',
                'Customer Since',
                'Total Orders',
                'Total Spent',
                'Total Paid',
                'Outstanding Balance',
                'Average Order Value',
                'First Order Date',
                'Last Order Date',
                'Days Since First Order',
                'Days Since Last Order',
            ]);

            // Data rows - one row per customer
            $today = now();
            foreach ($customers as $customer) {
                $outstandingBalance = ($customer->total_spent ?? 0) - ($customer->total_paid ?? 0);
                
                $firstOrderDate = $customer->first_order_date ? \Carbon\Carbon::parse($customer->first_order_date) : null;
                $lastOrderDate = $customer->last_order_date ? \Carbon\Carbon::parse($customer->last_order_date) : null;
                
                $daysSinceFirst = $firstOrderDate ? $today->diffInDays($firstOrderDate) : 'N/A';
                $daysSinceLast = $lastOrderDate ? $today->diffInDays($lastOrderDate) : 'N/A';

                fputcsv($file, [
                    $customer->id,
                    $customer->name,
                    $customer->email ?? 'N/A',
                    $customer->phone ?? 'N/A',
                    $customer->created_at ? \Carbon\Carbon::parse($customer->created_at)->format('Y-m-d') : 'N/A',
                    $customer->total_orders ?? 0,
                    number_format($customer->total_spent ?? 0, 2),
                    number_format($customer->total_paid ?? 0, 2),
                    number_format($outstandingBalance, 2),
                    number_format($customer->average_order_value ?? 0, 2),
                    $firstOrderDate ? $firstOrderDate->format('Y-m-d') : 'N/A',
                    $lastOrderDate ? $lastOrderDate->format('Y-m-d') : 'N/A',
                    $daysSinceFirst,
                    $daysSinceLast,
                ]);
            }

            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

/**
     * Get Daily Sales Report data for POS
     * 
     * GET /api/reporting/daily-sales
     * 
     * Query Parameters:
     * - store_id: Filter by specific store - required
     * - date: Report date (YYYY-MM-DD) - optional, default: today
     */
    public function getDailySalesReport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $dateStr = $request->get('date', now()->format('Y-m-d'));
        $startDate = $dateStr . ' 00:00:00';
        $endDate = $dateStr . ' 23:59:59';

        $storeId = $request->store_id;
        $store = \App\Models\Store::findOrFail($storeId);

        // Fetch all completed payments for this store on the selected date
        $payments = \App\Models\OrderPayment::query()
            ->where('store_id', $storeId)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$startDate, $endDate])
            ->with('paymentMethod')
            ->get();

        $totalSales = 0;
        $cash = 0;
        $card = 0;
        $bkash = 0;
        $nagad = 0;

        foreach ($payments as $payment) {
            $amount = floatval($payment->amount);
            $totalSales += $amount;

            if ($payment->paymentMethod) {
                $methodName = strtolower($payment->paymentMethod->name);
                
                if (str_contains($methodName, 'cash')) {
                    $cash += $amount;
                } elseif (str_contains($methodName, 'card')) {
                    $card += $amount;
                } elseif (str_contains($methodName, 'bkash')) {
                    $bkash += $amount;
                } elseif (str_contains($methodName, 'nagad') || str_contains($methodName, 'bank transfer')) {
                    $nagad += $amount;
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $dateStr,
                'branch' => $store->name,
                'total_sales' => $totalSales,
                'cash' => $cash,
                'card' => $card,
                'bkash' => $bkash,
                'nagad' => $nagad,
            ]
        ]);
    }
}
