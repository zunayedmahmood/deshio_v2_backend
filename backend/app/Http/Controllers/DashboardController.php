<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductBatch;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\Expense;
use App\Models\Store;
use App\Models\MasterInventory;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use DatabaseAgnosticSearch;
    
    /**
     * Get comprehensive summary for all stores
     * 
     * GET /api/dashboard/stores-summary?period=today|week|month|year&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
     * 
     * Returns comprehensive metrics for each store including:
     * - Sales performance (total, orders, avg order value)
     * - Inventory levels (total value, low stock count)
     * - Order status breakdown
     * - Payment status breakdown
     * - Top products per store
     * - Returns & refunds
     * - Profit margins
     */
    public function allStoresSummary(Request $request)
    {
        try {
            // Determine date range
            $period = $request->input('period', 'today');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');

            if ($dateFrom && $dateTo) {
                $startDate = Carbon::parse($dateFrom)->startOfDay();
                $endDate = Carbon::parse($dateTo)->endOfDay();
            } else {
                switch ($period) {
                    case 'week':
                        $startDate = Carbon::now()->startOfWeek();
                        $endDate = Carbon::now()->endOfWeek();
                        break;
                    case 'month':
                        $startDate = Carbon::now()->startOfMonth();
                        $endDate = Carbon::now()->endOfMonth();
                        break;
                    case 'year':
                        $startDate = Carbon::now()->startOfYear();
                        $endDate = Carbon::now()->endOfYear();
                        break;
                    case 'today':
                    default:
                        $startDate = Carbon::today();
                        $endDate = Carbon::now()->endOfDay();
                        break;
                }
            }

            // Get all stores
            $stores = Store::all();
            
            $storesSummary = [];
            $overallTotals = [
                'total_sales' => 0,
                'total_orders' => 0,
                'total_inventory_value' => 0,
                'total_profit' => 0,
                'total_returns' => 0,
            ];

            foreach ($stores as $store) {
                $summary = $this->getStoreSummary($store, $startDate, $endDate);
                $storesSummary[] = $summary;
                
                // Accumulate overall totals
                $overallTotals['total_sales'] += $summary['sales']['total_sales'];
                $overallTotals['total_orders'] += $summary['sales']['total_orders'];
                $overallTotals['total_inventory_value'] += $summary['inventory']['total_value'];
                $overallTotals['total_profit'] += $summary['performance']['gross_profit'];
                $overallTotals['total_returns'] += $summary['returns']['total_returns'];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'type' => $period,
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date' => $endDate->format('Y-m-d'),
                    ],
                    'overall_totals' => $overallTotals,
                    'stores' => $storesSummary,
                    'store_count' => count($storesSummary),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching stores summary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper: Get comprehensive summary for a single store
     */
    private function getStoreSummary($store, $startDate, $endDate)
    {
        // 1. SALES METRICS
        $orders = Order::where('store_id', $store->id)
            ->whereBetween('order_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        $totalSales = $orders->sum('total_amount');
        $totalOrders = $orders->count();
        $avgOrderValue = $totalOrders > 0 ? $totalSales / $totalOrders : 0;
        $paidAmount = $orders->sum('paid_amount');
        $outstandingAmount = $orders->sum('outstanding_amount');

        // Orders by status
        $ordersByStatus = $orders->groupBy('status')->map->count();
        
        // Orders by payment status
        $ordersByPaymentStatus = $orders->groupBy('payment_status')->map->count();
        
        // Orders by type (counter, ecommerce, social_commerce)
        $ordersByType = $orders->groupBy('order_type')->map->count();

        // 2. PROFIT CALCULATIONS
        $orderIds = $orders->pluck('id');
        $orderItems = OrderItem::whereIn('order_id', $orderIds)
            ->with('batch')
            ->get();

        $cogs = $orderItems->sum(function ($item) {
            if (!is_null($item->cogs)) {
                return (float) $item->cogs;
            }
            return ($item->batch ? ($item->batch->cost_price ?? 0) : 0) * $item->quantity;
        });

        $grossProfit = $totalSales - $cogs;
        $grossMarginPercentage = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0;

        // Expenses for this store
        $expenses = Expense::where('store_id', $store->id)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->sum('total_amount');

        $netProfit = $grossProfit - $expenses;
        $netMarginPercentage = $totalSales > 0 ? ($netProfit / $totalSales) * 100 : 0;

        // 3. INVENTORY METRICS
        $inventorySummary = MasterInventory::getStoreInventorySummary($store->id);

$totalInventoryValue = (float) ($inventorySummary['total_value'] ?? 0);
$lowStockCount = (int) ($inventorySummary['low_stock_items'] ?? 0);
$outOfStockCount = (int) ($inventorySummary['out_of_stock_items'] ?? 0);
$totalProducts = (int) ($inventorySummary['total_products'] ?? 0);

        // 4. TOP PRODUCTS (by quantity sold)
        $topProducts = OrderItem::whereIn('order_id', $orderIds)
            ->select('product_id', DB::raw('SUM(quantity) as total_quantity'), DB::raw('SUM(total_amount) as total_revenue'))
            ->groupBy('product_id')
            ->orderBy('total_quantity', 'desc')
            ->limit(5)
            ->with('product:id,name,sku')
            ->get()
            ->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name ?? 'Unknown',
                    'sku' => $item->product->sku ?? 'N/A',
                    'quantity_sold' => (int) $item->total_quantity,
                    'revenue' => (float) $item->total_revenue,
                ];
            });

        // 5. RETURNS & REFUNDS
        $returns = ProductReturn::whereHas('order', function ($q) use ($store) {
                $q->where('store_id', $store->id);
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->get();

        $totalReturns = $returns->count();
        $returnRate = $totalOrders > 0 ? ($totalReturns / $totalOrders) * 100 : 0;

        // 6. CUSTOMER METRICS
        $uniqueCustomers = $orders->pluck('customer_id')->unique()->count();
        $repeatCustomers = $orders->groupBy('customer_id')
            ->filter(function ($customerOrders) {
                return $customerOrders->count() > 1;
            })
            ->count();

        return [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'store_code' => $store->store_code,
                'store_type' => $store->store_type,
                'address' => $store->address,
            ],
            'sales' => [
                'total_sales' => (float) $totalSales,
                'total_orders' => $totalOrders,
                'avg_order_value' => (float) number_format($avgOrderValue, 2, '.', ''),
                'paid_amount' => (float) $paidAmount,
                'outstanding_amount' => (float) $outstandingAmount,
                'orders_by_status' => $ordersByStatus,
                'orders_by_payment_status' => $ordersByPaymentStatus,
                'orders_by_type' => $ordersByType,
            ],
            'performance' => [
                'gross_profit' => (float) $grossProfit,
                'gross_margin_percentage' => (float) number_format($grossMarginPercentage, 2, '.', ''),
                'expenses' => (float) $expenses,
                'net_profit' => (float) $netProfit,
                'net_margin_percentage' => (float) number_format($netMarginPercentage, 2, '.', ''),
                'cogs' => (float) $cogs,
            ],
            'inventory' => [
                'total_value' => (float) $totalInventoryValue,
                'total_products' => $totalProducts,
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
            ],
            'top_products' => $topProducts,
            'returns' => [
                'total_returns' => $totalReturns,
                'return_rate' => (float) number_format($returnRate, 2, '.', ''),
            ],
            'customers' => [
                'unique_customers' => $uniqueCustomers,
                'repeat_customers' => $repeatCustomers,
            ],
        ];
    }
    
    /**
     * Get today's key metrics
     * 
     * Returns: total sales, order count, gross margin, net profit, cash snapshot (payable/receivable)
     */
    public function todayMetrics(Request $request)
    {
        try {
            $storeId = $request->query('store_id');
            $today = Carbon::today();

            // Get today's orders
            $ordersQuery = Order::whereDate('order_date', $today)
                ->whereNotIn('status', ['cancelled']);

            if ($storeId) {
                $ordersQuery->where('store_id', $storeId);
            }

            $todayOrders = $ordersQuery->get();
            
            // Total sales and order count
            $totalSales = $todayOrders->sum('total_amount');
            $orderCount = $todayOrders->count();
            $paidAmount = $todayOrders->sum('paid_amount');

            // Calculate Cost of Goods Sold (COGS) for gross margin
            $todayOrderIds = $todayOrders->pluck('id');
            $orderItems = OrderItem::whereIn('order_id', $todayOrderIds)
                ->with('batch')
                ->get();

            // Prefer stored COGS if available; otherwise fallback to batch cost_price
            $cogs = $orderItems->sum(function ($item) {
                if (!is_null($item->cogs)) {
                    return (float) $item->cogs;
                }

                return ($item->batch ? ($item->batch->cost_price ?? 0) : 0) * $item->quantity;
            });

            $grossMargin = $totalSales - $cogs;
            $grossMarginPercentage = $totalSales > 0 ? ($grossMargin / $totalSales) * 100 : 0;

            // Get today's expenses for net profit calculation
            $expensesQuery = Expense::whereDate('expense_date', $today)
                ->whereNotIn('status', ['cancelled', 'rejected']);

            if ($storeId) {
                $expensesQuery->where('store_id', $storeId);
            }

            $todayExpenses = $expensesQuery->sum('total_amount');
            $netProfit = $grossMargin - $todayExpenses;
            $netProfitPercentage = $totalSales > 0 ? ($netProfit / $totalSales) * 100 : 0;

            // Cash snapshot: Accounts Receivable & Payable
            $accountsReceivableQuery = Order::whereNotIn('status', ['cancelled'])
                ->where('payment_status', '!=', 'paid')
                ->where('outstanding_amount', '>', 0);

            if ($storeId) {
                $accountsReceivableQuery->where('store_id', $storeId);
            }

            $accountsReceivable = $accountsReceivableQuery->sum('outstanding_amount');

            $accountsPayableQuery = Expense::whereNotIn('status', ['cancelled', 'rejected'])
                ->where('payment_status', '!=', 'paid')
                ->where('outstanding_amount', '>', 0);

            if ($storeId) {
                $accountsPayableQuery->where('store_id', $storeId);
            }

            $accountsPayable = $accountsPayableQuery->sum('outstanding_amount');

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $today->format('Y-m-d'),
                    'total_sales' => round($totalSales, 2),
                    'paid_sales' => round($paidAmount, 2),
                    'order_count' => $orderCount,
                    'average_order_value' => $orderCount > 0 ? round($totalSales / $orderCount, 2) : 0,
                    'cost_of_goods_sold' => round($cogs, 2),
                    'gross_margin' => round($grossMargin, 2),
                    'gross_margin_percentage' => round($grossMarginPercentage, 2),
                    'total_expenses' => round($todayExpenses, 2),
                    'net_profit' => round($netProfit, 2),
                    'net_profit_percentage' => round($netProfitPercentage, 2),
                    'cash_snapshot' => [
                        'accounts_receivable' => round($accountsReceivable, 2),
                        'accounts_payable' => round($accountsPayable, 2),
                        'net_position' => round($accountsReceivable - $accountsPayable, 2),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching today\'s metrics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sales for the last 30 days
     * 
     * Returns: Array of daily sales with date and value
     */
    public function last30DaysSales(Request $request)
    {
        try {
            $storeId = $request->query('store_id');
            $endDate = Carbon::today();
            $startDate = $endDate->copy()->subDays(29);

            $dateCastSql = $this->getDateCastSql('order_date');
            $salesQuery = Order::select(
                DB::raw("{$dateCastSql} as date"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('SUM(paid_amount) as paid_amount')
            )
                ->whereDate('order_date', '>=', $startDate)
                ->whereDate('order_date', '<=', $endDate)
                ->whereNotIn('status', ['cancelled'])
                ->groupBy(DB::raw($dateCastSql))
                ->orderBy('date', 'asc');

            if ($storeId) {
                $salesQuery->where('store_id', $storeId);
            }

            $sales = $salesQuery->get();

            // Fill in missing dates with zero values
            $salesData = [];
            $currentDate = $startDate->copy();

            while ($currentDate <= $endDate) {
                $dateStr = $currentDate->format('Y-m-d');
                $dayData = $sales->firstWhere('date', $dateStr);

                $salesData[] = [
                    'date' => $dateStr,
                    'day_name' => $currentDate->format('D'),
                    'total_sales' => $dayData ? round($dayData->total_sales, 2) : 0,
                    'paid_amount' => $dayData ? round($dayData->paid_amount, 2) : 0,
                    'order_count' => $dayData ? $dayData->order_count : 0,
                ];

                $currentDate->addDay();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date' => $endDate->format('Y-m-d'),
                    ],
                    'total_sales' => round($sales->sum('total_sales'), 2),
                    'total_orders' => $sales->sum('order_count'),
                    'daily_sales' => $salesData,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching last 30 days sales',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get sales breakdown by channel
     * 
     * Returns: Sales by channel (store/counter, ecommerce, social_commerce)
     */
    public function salesByChannel(Request $request)
    {
        try {
            $storeId = $request->query('store_id');
            $period = $request->query('period', 'today'); // today, week, month, year

            $query = Order::whereNotIn('status', ['cancelled']);

            if ($storeId) {
                $query->where('store_id', $storeId);
            }

            // Apply period filter
            switch ($period) {
                case 'week':
                    $query->whereBetween('order_date', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'month':
                    $query->whereMonth('order_date', now()->month)
                        ->whereYear('order_date', now()->year);
                    break;
                case 'year':
                    $query->whereYear('order_date', now()->year);
                    break;
                case 'today':
                default:
                    $query->whereDate('order_date', today());
                    break;
            }

            $channelSales = $query->select(
                'order_type',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('SUM(paid_amount) as paid_amount')
            )
                ->groupBy('order_type')
                ->get();

            $totalSales = $channelSales->sum('total_sales');

            $channels = [];
            $channelTypes = ['counter', 'ecommerce', 'social_commerce'];

            foreach ($channelTypes as $type) {
                $channelData = $channelSales->firstWhere('order_type', $type);
                $sales = $channelData ? $channelData->total_sales : 0;

                $channels[] = [
                    'channel' => $type,
                    'channel_label' => $this->getChannelLabel($type),
                    'total_sales' => round($sales, 2),
                    'paid_amount' => $channelData ? round($channelData->paid_amount, 2) : 0,
                    'order_count' => $channelData ? $channelData->order_count : 0,
                    'percentage' => $totalSales > 0 ? round(($sales / $totalSales) * 100, 2) : 0,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => $period,
                    'total_sales' => round($totalSales, 2),
                    'total_orders' => $channelSales->sum('order_count'),
                    'channels' => $channels,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching sales by channel',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get top performing stores by sales
     * 
     * Returns: Array of stores with sales data
     */
    public function topStoresBySales(Request $request)
    {
        try {
            $limit = $request->query('limit', 10);
            $period = $request->query('period', 'today'); // today, week, month, year

            $query = Order::whereNotIn('status', ['cancelled']);

            // Apply period filter
            switch ($period) {
                case 'week':
                    $query->whereBetween('order_date', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'month':
                    $query->whereMonth('order_date', now()->month)
                        ->whereYear('order_date', now()->year);
                    break;
                case 'year':
                    $query->whereYear('order_date', now()->year);
                    break;
                case 'today':
                default:
                    $query->whereDate('order_date', today());
                    break;
            }

            $storeSales = $query->select(
                'store_id',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(total_amount) as total_sales'),
                DB::raw('SUM(paid_amount) as paid_amount')
            )
                ->groupBy('store_id')
                ->orderBy('total_sales', 'desc')
                ->limit($limit)
                ->get();

            // Handle empty results
            if ($storeSales->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'period' => $period,
                    'total_sales_all_stores' => 0,
                    'top_stores' => [],
                ]);
            }

            // Load store data separately for grouped results
            $storeIds = $storeSales->pluck('store_id')->toArray();
            $storesData = Store::whereIn('id', $storeIds)
                ->select('id', 'name', 'address', 'is_warehouse', 'is_online')
                ->get()
                ->keyBy('id');

            $totalSales = Order::whereNotIn('status', ['cancelled'])
                ->when($period === 'week', fn($q) => $q->whereBetween('order_date', [now()->startOfWeek(), now()->endOfWeek()]))
                ->when($period === 'month', fn($q) => $q->whereMonth('order_date', now()->month)->whereYear('order_date', now()->year))
                ->when($period === 'year', fn($q) => $q->whereYear('order_date', now()->year))
                ->when($period === 'today', fn($q) => $q->whereDate('order_date', today()))
                ->sum('total_amount');

            $stores = $storeSales->map(function ($sale, $index) use ($totalSales, $storesData) {
                $store = $storesData->get($sale->store_id);
                
                // Derive store type from boolean flags
                $storeType = 'N/A';
                if ($store) {
                    if ($store->is_warehouse) {
                        $storeType = 'warehouse';
                    } elseif ($store->is_online) {
                        $storeType = 'online';
                    } else {
                        $storeType = 'physical';
                    }
                }
                
                return [
                    'rank' => $index + 1,
                    'store_id' => $sale->store_id,
                    'store_name' => $store ? $store->name : 'Unknown Store',
                    'store_location' => $store ? $store->address : 'N/A',
                    'store_type' => $storeType,
                    'total_sales' => round($sale->total_sales, 2),
                    'paid_amount' => round($sale->paid_amount, 2),
                    'order_count' => $sale->order_count,
                    'average_order_value' => $sale->order_count > 0 ? round($sale->total_sales / $sale->order_count, 2) : 0,
                    'contribution_percentage' => $totalSales > 0 ? round(($sale->total_sales / $totalSales) * 100, 2) : 0,
                ];
            });

            return response()->json([
                'success' => true,
                'period' => $period,
                'total_sales_all_stores' => round($totalSales, 2),
                'top_stores' => $stores,
            ]);
        } catch (\Exception $e) {
            \Log::error('Dashboard topStoresBySales error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error fetching top stores by sales',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Get today's top selling products
     * 
     * Returns: Array of products with sales data
     */
    public function todayTopProducts(Request $request)
    {
        try {
            $storeId = $request->query('store_id');
            $limit = $request->query('limit', 10);
            $today = Carbon::today();

            $ordersQuery = Order::whereDate('order_date', $today)
                ->whereNotIn('status', ['cancelled']);

            if ($storeId) {
                $ordersQuery->where('store_id', $storeId);
            }

            $todayOrderIds = $ordersQuery->pluck('id');

            $topProducts = OrderItem::whereIn('order_id', $todayOrderIds)
                ->select(
                    'product_id',
                    'product_name',
                    DB::raw('SUM(quantity) as total_quantity'),
                    DB::raw('SUM(total_amount) as total_revenue'),
                    DB::raw('COUNT(DISTINCT order_id) as order_count')
                )
                ->groupBy('product_id', 'product_name')
                ->orderBy('total_revenue', 'desc')
                ->limit($limit)
                ->get();

            // Load product data separately for grouped results
            $productIds = $topProducts->pluck('product_id')->toArray();
            $productsData = Product::whereIn('id', $productIds)
                ->select('id', 'name', 'sku', 'category_id')
                ->get()
                ->keyBy('id');

            $products = $topProducts->map(function ($item, $index) use ($productsData) {
                $product = $productsData->get($item->product_id);
                return [
                    'rank' => $index + 1,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_sku' => $product ? $product->sku : 'N/A',
                    'total_quantity_sold' => $item->total_quantity,
                    'total_revenue' => round($item->total_revenue, 2),
                    'order_count' => $item->order_count,
                    'average_price' => $item->total_quantity > 0 ? round($item->total_revenue / $item->total_quantity, 2) : 0,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $today->format('Y-m-d'),
                    'top_products' => $products,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching today\'s top products',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get slow-moving products
     * 
     * Returns: Products with low turnover rate in the last 90 days
     */
    public function slowMovingProducts(Request $request)
    {
        try {
            $storeId = $request->query('store_id');
            $limit = $request->query('limit', 10);
            $days = $request->query('days', 90); // Default 90 days lookback

            $startDate = Carbon::now()->subDays($days);

            // Get products with inventory but low/no sales
            $inventoryQuery = ProductBatch::select(
                'product_id',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(quantity * cost_price) as total_value')
            )
                ->where('quantity', '>', 0)
                ->where('is_active', true);

            if ($storeId) {
                $inventoryQuery->where('store_id', $storeId);
            }

            $inventory = $inventoryQuery->groupBy('product_id')->get();

            // Handle empty inventory
            if ($inventory->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'period_days' => $days,
                    'slow_moving_products' => [],
                ]);
            }

            // Get sales for these products in the period
            $productIds = $inventory->pluck('product_id');

            $salesQuery = OrderItem::whereIn('product_id', $productIds)
                ->whereHas('order', function ($query) use ($startDate, $storeId) {
                    $query->whereDate('order_date', '>=', $startDate)
                        ->whereNotIn('status', ['cancelled']);
                    if ($storeId) {
                        $query->where('store_id', $storeId);
                    }
                })
                ->select(
                    'product_id',
                    DB::raw('SUM(quantity) as quantity_sold'),
                    DB::raw('COUNT(DISTINCT order_id) as order_count')
                )
                ->groupBy('product_id')
                ->get()
                ->keyBy('product_id');

            // Calculate turnover rate
            $slowMoving = $inventory->map(function ($inv) use ($salesQuery, $days) {
                $sales = $salesQuery->get($inv->product_id);
                $quantitySold = $sales ? $sales->quantity_sold : 0;
                $orderCount = $sales ? $sales->order_count : 0;

                // Turnover rate: (quantity sold / average inventory) / days * 100
                $turnoverRate = $inv->total_quantity > 0 
                    ? ($quantitySold / $inv->total_quantity) * 100 
                    : 0;

                return [
                    'product_id' => $inv->product_id,
                    'current_stock' => $inv->total_quantity,
                    'stock_value' => round($inv->total_value, 2),
                    'quantity_sold' => $quantitySold,
                    'order_count' => $orderCount,
                    'turnover_rate' => round($turnoverRate, 2),
                    'days_of_supply' => $quantitySold > 0 
                        ? round(($inv->total_quantity / $quantitySold) * $days, 0)
                        : 999,
                ];
            })
                ->sortBy('turnover_rate')
                ->take($limit)
                ->values();

            // Enrich with product details
            $productIds = $slowMoving->pluck('product_id');
            $products = Product::whereIn('id', $productIds)
                ->select('id', 'name', 'sku', 'category_id')
                ->with('category:id,name')
                ->get()
                ->keyBy('id');

            $slowMovingProducts = $slowMoving->map(function ($item, $index) use ($products) {
                $product = $products->get($item['product_id']);
                return array_merge([
                    'rank' => $index + 1,
                    'product_name' => $product ? $product->name : 'Unknown Product',
                    'product_sku' => $product ? $product->sku : 'N/A',
                    'category' => $product && $product->category ? $product->category->name : 'N/A',
                ], $item);
            });

            return response()->json([
                'success' => true,
                'period_days' => $days,
                'slow_moving_products' => $slowMovingProducts,
            ]);
        } catch (\Exception $e) {
            \Log::error('Dashboard slowMovingProducts error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error fetching slow-moving products',
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Get low stock and out of stock products
     * 
     * Returns: Products that are low in stock or completely out of stock
     */
    public function lowStockProducts(Request $request)
    {
        try {
            $storeId = $request->query('store_id');
            $lowStockThreshold = $request->query('threshold', 10);

            $query = ProductBatch::select(
                'product_id',
                'store_id',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('MIN(quantity) as min_batch_quantity'),
                DB::raw('COUNT(*) as batch_count')
            )
                ->where('is_active', true)
                ->groupBy('product_id', 'store_id')
                ->having('total_quantity', '<=', $lowStockThreshold);

            if ($storeId) {
                $query->where('store_id', $storeId);
            }

            $lowStock = $query->orderBy('total_quantity', 'asc')
                ->with(['product:id,name,sku,category_id', 'store:id,name'])
                ->get();

            $outOfStock = $lowStock->where('total_quantity', 0);
            $lowStockItems = $lowStock->where('total_quantity', '>', 0);

            $formatItems = function ($items) {
                return $items->map(function ($item, $index) {
                    return [
                        'rank' => $index + 1,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product ? $item->product->name : 'Unknown Product',
                        'product_sku' => $item->product ? $item->product->sku : 'N/A',
                        'store_id' => $item->store_id,
                        'store_name' => $item->store ? $item->store->name : 'Unknown Store',
                        'current_stock' => $item->total_quantity,
                        'batch_count' => $item->batch_count,
                        'status' => $item->total_quantity === 0 ? 'out_of_stock' : 'low_stock',
                    ];
                })->values();
            };

            return response()->json([
                'success' => true,
                'data' => [
                    'low_stock_threshold' => $lowStockThreshold,
                    'summary' => [
                        'out_of_stock_count' => $outOfStock->count(),
                        'low_stock_count' => $lowStockItems->count(),
                        'total_items' => $lowStock->count(),
                    ],
                    'out_of_stock' => $formatItems($outOfStock),
                    'low_stock' => $formatItems($lowStockItems),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching low stock products',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get inventory age analysis by value
     * 
     * Returns: Inventory categorized by age (0-30, 31-60, 61-90, 90+ days)
     */
    public function inventoryAgeByValue(Request $request)
    {
        try {
            $storeId = $request->query('store_id');
            $today = Carbon::today();

            $dateCastSql = $this->getDateCastSql('created_at');
            $dateDiffSql = $this->getDateDiffDaysSql($dateCastSql);
            $query = ProductBatch::where('quantity', '>', 0)
                ->where('is_active', true)
                ->select(
                    'id',
                    'product_id',
                    'batch_number',
                    'quantity',
                    'cost_price',
                    'created_at',
                    'store_id',
                    DB::raw("{$dateDiffSql} as age_days"),
                    DB::raw('quantity * cost_price as inventory_value')
                );

            if ($storeId) {
                $query->where('store_id', $storeId);
            }

            $batches = $query->get();

            // Categorize by age
            $ageCategories = [
                '0-30' => ['label' => '0-30 days', 'min' => 0, 'max' => 30, 'value' => 0, 'quantity' => 0, 'batches' => []],
                '31-60' => ['label' => '31-60 days', 'min' => 31, 'max' => 60, 'value' => 0, 'quantity' => 0, 'batches' => []],
                '61-90' => ['label' => '61-90 days', 'min' => 61, 'max' => 90, 'value' => 0, 'quantity' => 0, 'batches' => []],
                '90+' => ['label' => '90+ days', 'min' => 91, 'max' => 9999, 'value' => 0, 'quantity' => 0, 'batches' => []],
            ];

            foreach ($batches as $batch) {
                $age = $batch->age_days;
                $value = $batch->inventory_value;

                if ($age <= 30) {
                    $ageCategories['0-30']['value'] += $value;
                    $ageCategories['0-30']['quantity'] += $batch->quantity;
                    $ageCategories['0-30']['batches'][] = $batch->id;
                } elseif ($age <= 60) {
                    $ageCategories['31-60']['value'] += $value;
                    $ageCategories['31-60']['quantity'] += $batch->quantity;
                    $ageCategories['31-60']['batches'][] = $batch->id;
                } elseif ($age <= 90) {
                    $ageCategories['61-90']['value'] += $value;
                    $ageCategories['61-90']['quantity'] += $batch->quantity;
                    $ageCategories['61-90']['batches'][] = $batch->id;
                } else {
                    $ageCategories['90+']['value'] += $value;
                    $ageCategories['90+']['quantity'] += $batch->quantity;
                    $ageCategories['90+']['batches'][] = $batch->id;
                }
            }

            $totalValue = $batches->sum('inventory_value');
            $totalQuantity = $batches->sum('quantity');

            $categories = collect($ageCategories)->map(function ($category) use ($totalValue) {
                return [
                    'label' => $category['label'],
                    'age_range' => $category['label'],
                    'inventory_value' => round($category['value'], 2),
                    'quantity' => $category['quantity'],
                    'batch_count' => count($category['batches']),
                    'percentage_of_total' => $totalValue > 0 ? round(($category['value'] / $totalValue) * 100, 2) : 0,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_inventory_value' => round($totalValue, 2),
                    'total_quantity' => $totalQuantity,
                    'total_batches' => $batches->count(),
                    'age_categories' => $categories,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching inventory age analysis',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get today's operations metrics
     * 
     * Returns: Operations status (pending, processing, ready to ship, delivered, returned, return rate)
     */
    public function operationsToday(Request $request)
    {
        try {
            $storeId = $request->query('store_id');
            $today = Carbon::today();

            // Get today's orders by status
            $ordersQuery = Order::whereDate('order_date', $today);

            if ($storeId) {
                $ordersQuery->where('store_id', $storeId);
            }

            $ordersByStatus = $ordersQuery->select(
                'status',
                DB::raw('COUNT(*) as count')
            )
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            // Get returns for today
            $returnsQuery = ProductReturn::whereDate('created_at', $today);

            if ($storeId) {
                $returnsQuery->whereHas('order', function ($q) use ($storeId) {
                    $q->where('store_id', $storeId);
                });
            }

            $returnsCount = $returnsQuery->count();
            $totalOrdersToday = $ordersQuery->count();

            // Calculate return rate
            $returnRate = $totalOrdersToday > 0 
                ? round(($returnsCount / $totalOrdersToday) * 100, 2) 
                : 0;

            // Get orders requiring action
            $requiresAction = Order::whereIn('status', ['pending', 'confirmed', 'processing'])
                ->whereDate('order_date', '<=', $today);

            if ($storeId) {
                $requiresAction->where('store_id', $storeId);
            }

            $overdueOrders = $requiresAction->where('order_date', '<', $today)->count();

            $operations = [
                'pending' => [
                    'label' => 'Pending',
                    'count' => $ordersByStatus->get('pending')->count ?? 0,
                    'description' => 'Orders awaiting confirmation',
                ],
                'confirmed' => [
                    'label' => 'Confirmed',
                    'count' => $ordersByStatus->get('confirmed')->count ?? 0,
                    'description' => 'Orders confirmed, awaiting processing',
                ],
                'processing' => [
                    'label' => 'Processing',
                    'count' => $ordersByStatus->get('processing')->count ?? 0,
                    'description' => 'Orders being prepared',
                ],
                'ready_for_pickup' => [
                    'label' => 'Ready for Pickup',
                    'count' => $ordersByStatus->get('ready_for_pickup')->count ?? 0,
                    'description' => 'Orders ready for customer pickup',
                ],
                'shipped' => [
                    'label' => 'Shipped',
                    'count' => $ordersByStatus->get('shipped')->count ?? 0,
                    'description' => 'Orders in transit',
                ],
                'delivered' => [
                    'label' => 'Delivered',
                    'count' => $ordersByStatus->get('delivered')->count ?? 0,
                    'description' => 'Successfully delivered orders',
                ],
                'cancelled' => [
                    'label' => 'Cancelled',
                    'count' => $ordersByStatus->get('cancelled')->count ?? 0,
                    'description' => 'Cancelled orders',
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $today->format('Y-m-d'),
                    'total_orders' => $totalOrdersToday,
                    'operations_status' => $operations,
                    'returns' => [
                        'count' => $returnsCount,
                        'return_rate' => $returnRate,
                        'description' => 'Product returns initiated today',
                    ],
                    'alerts' => [
                        'overdue_orders' => $overdueOrders,
                        'requires_immediate_action' => $ordersByStatus->get('pending')->count ?? 0,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching operations data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper function to get channel label
     */
    private function getChannelLabel($type)
    {
        return match ($type) {
            'counter' => 'Store/Counter',
            'ecommerce' => 'E-commerce',
            'social_commerce' => 'Social Commerce',
            default => 'Unknown',
        };
    }
}
