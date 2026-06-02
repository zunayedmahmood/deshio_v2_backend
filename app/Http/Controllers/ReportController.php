<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use DatabaseAgnosticSearch;

    /** @var string[] statuses that are not real sales for reporting totals */
    private array $excludedOrderStatuses = ['cancelled', 'pending_assignment', 'draft'];

    /**
     * Net line value used by all legacy reports.
     *
     * Many old rows have total_amount as zero even though quantity and
     * unit_price are correct. This expression fixes zero-total reports by
     * recalculating from item price first, then falling back to stored totals.
     */
    private function lineNetSql(): string
    {
        return "CASE
            WHEN COALESCE(order_items.unit_price, 0) > 0 AND COALESCE(order_items.quantity, 0) > 0
                THEN GREATEST((COALESCE(order_items.quantity, 0) * COALESCE(order_items.unit_price, 0)) - COALESCE(order_items.discount_amount, 0), 0)
            WHEN COALESCE(order_items.total_amount, 0) > 0
                THEN COALESCE(order_items.total_amount, 0)
            ELSE 0
        END";
    }

    private function orderValueSql(): string
    {
        return "CASE
            WHEN COALESCE(total_amount, 0) > 0 THEN COALESCE(total_amount, 0)
            WHEN COALESCE(subtotal, 0) > 0 THEN GREATEST(COALESCE(subtotal, 0) - COALESCE(discount_amount, 0) + COALESCE(tax_amount, 0), 0)
            ELSE 0
        END";
    }

    private function dateRangeFromRequest(Request $request, string $defaultPeriod = 'month'): array
    {
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        if ($dateFrom || $dateTo) {
            return [
                $dateFrom ? Carbon::parse($dateFrom)->startOfDay() : now()->startOfMonth(),
                $dateTo ? Carbon::parse($dateTo)->endOfDay() : now()->endOfDay(),
            ];
        }

        return match ($defaultPeriod) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function applySalesScope($query, string $table = 'orders')
    {
        return $query
            ->whereNull("{$table}.deleted_at")
            ->whereNotIn("{$table}.status", $this->excludedOrderStatuses);
    }

    public function dashboard(Request $request)
    {
        $period = $request->get('period', 'today');
        $dateRange = $this->getDateRange($period);

        $dashboard = [
            'sales_summary' => $this->getSalesSummary($dateRange),
            'inventory_summary' => $this->getInventorySummary(),
            'customer_summary' => $this->getCustomerSummary($dateRange),
            'top_products' => $this->getTopProducts($dateRange, 5),
            'recent_orders' => $this->applySalesScope(Order::with(['customer', 'items'])
                ->whereBetween('created_at', $dateRange))
                ->latest()
                ->limit(10)
                ->get(),
            'alerts' => $this->getAlerts(),
        ];

        return response()->json(['success' => true, 'data' => $dashboard]);
    }

    public function salesSummary(Request $request)
    {
        [$dateFrom, $dateTo] = $this->dateRangeFromRequest($request);
        $groupBy = $request->get('group_by', 'day');

        $base = $this->applySalesScope(Order::query())
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        $dateFormatSql = $this->getDateFormatSql('created_at', $groupBy);
        $orderValueSql = $this->orderValueSql();

        $salesData = (clone $base)->selectRaw("
                {$dateFormatSql} as period,
                COUNT(*) as total_orders,
                SUM({$orderValueSql}) as total_sales,
                SUM(COALESCE(paid_amount, 0)) as total_paid,
                AVG({$orderValueSql}) as average_order_value
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        $summaryRow = (clone $base)->selectRaw("
            COUNT(*) as total_orders,
            SUM({$orderValueSql}) as total_sales,
            SUM(COALESCE(paid_amount, 0)) as total_paid,
            AVG({$orderValueSql}) as average_order_value
        ")->first();

        return response()->json(['success' => true, 'data' => [
            'total_orders' => (int) ($summaryRow->total_orders ?? 0),
            'total_sales' => (float) ($summaryRow->total_sales ?? 0),
            'total_paid' => (float) ($summaryRow->total_paid ?? 0),
            'average_order_value' => (float) ($summaryRow->average_order_value ?? 0),
            'sales_data' => $salesData,
        ]]);
    }

    public function bestSellers(Request $request)
    {
        [$dateFrom, $dateTo] = $this->dateRangeFromRequest($request);
        $limit = (int) $request->get('limit', 20);
        $lineNet = $this->lineNetSql();

        $bestSellers = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', $this->excludedOrderStatuses)
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(COALESCE(order_items.quantity, 0)) as total_quantity'),
                DB::raw("SUM({$lineNet}) as total_revenue"),
                DB::raw('COUNT(DISTINCT orders.id) as order_count'),
                DB::raw('AVG(NULLIF(order_items.unit_price, 0)) as average_price')
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'data' => $bestSellers]);
    }

    public function slowMoving(Request $request)
    {
        $days = (int) $request->get('days', 30);
        $limit = (int) $request->get('limit', 20);

        $slowMoving = Product::whereHas('batches', function ($q) {
                $q->where('quantity', '>', 0);
            })
            ->whereDoesntHave('orderItems', function ($q) use ($days) {
                $q->whereHas('order', function ($q) use ($days) {
                    $q->where('created_at', '>=', now()->subDays($days))
                        ->whereNull('deleted_at')
                        ->whereNotIn('status', $this->excludedOrderStatuses);
                });
            })
            ->with(['batches' => function ($q) {
                $q->where('quantity', '>', 0);
            }])
            ->limit($limit)
            ->get()
            ->map(function ($product) {
                $product->total_stock = $product->batches->sum('quantity');
                return $product;
            });

        return response()->json(['success' => true, 'data' => $slowMoving]);
    }

    public function staffPerformance(Request $request)
    {
        [$dateFrom, $dateTo] = $this->dateRangeFromRequest($request);
        $orderValueSql = $this->orderValueSql();

        $performance = Employee::query()
            ->leftJoin('orders', function ($join) use ($dateFrom, $dateTo) {
                $join->on('employees.id', '=', 'orders.created_by')
                    ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
                    ->whereNull('orders.deleted_at')
                    ->whereNotIn('orders.status', $this->excludedOrderStatuses);
            })
            ->select(
                'employees.id',
                'employees.name',
                'employees.employee_code',
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw("SUM({$orderValueSql}) as total_sales"),
                DB::raw("AVG({$orderValueSql}) as average_order_value")
            )
            ->groupBy('employees.id', 'employees.name', 'employees.employee_code')
            ->orderByDesc('total_sales')
            ->get();

        return response()->json(['success' => true, 'data' => $performance]);
    }

    public function profitMargins(Request $request)
    {
        [$dateFrom, $dateTo] = $this->dateRangeFromRequest($request);
        $lineNet = $this->lineNetSql();

        $margins = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', $this->excludedOrderStatuses)
            ->select(
                'products.id',
                'products.name',
                DB::raw('SUM(COALESCE(order_items.quantity, 0)) as units_sold'),
                DB::raw("SUM({$lineNet}) as revenue"),
                DB::raw('SUM(COALESCE(order_items.cogs, 0)) as cost'),
                DB::raw("SUM({$lineNet}) - SUM(COALESCE(order_items.cogs, 0)) as profit"),
                DB::raw("CASE WHEN SUM({$lineNet}) > 0 THEN ((SUM({$lineNet}) - SUM(COALESCE(order_items.cogs, 0))) / SUM({$lineNet})) * 100 ELSE 0 END as margin_percent")
            )
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('profit')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => $margins]);
    }

    public function customerAcquisition(Request $request)
    {
        [$dateFrom, $dateTo] = $this->dateRangeFromRequest($request, 'year');
        $dateFormatSql = $this->getDateFormatSql('created_at', 'month');

        $newCustomers = Customer::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw("{$dateFormatSql} as month, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $returningCustomers = $this->applySalesScope(Order::query())
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereIn('customer_id', function ($query) use ($dateFrom) {
                $query->select('customer_id')
                    ->from('orders')
                    ->where('created_at', '<', $dateFrom)
                    ->whereNull('deleted_at')
                    ->whereNotIn('status', $this->excludedOrderStatuses)
                    ->distinct();
            })
            ->selectRaw("{$dateFormatSql} as month, COUNT(DISTINCT customer_id) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'new_customers' => $newCustomers,
                'returning_customers' => $returningCustomers,
            ],
        ]);
    }

    public function inventoryValue(Request $request)
    {
        $storeId = $request->get('store_id');

        $query = DB::table('product_batches')
            ->join('products', 'product_batches.product_id', '=', 'products.id')
            ->where('product_batches.quantity', '>', 0);

        if ($storeId) {
            $query->where('product_batches.store_id', $storeId);
        }

        $inventoryValue = $query->select(
                DB::raw('SUM(product_batches.quantity) as total_units'),
                DB::raw('SUM(product_batches.quantity * COALESCE(product_batches.cost_price, 0)) as cost_value'),
                DB::raw('SUM(product_batches.quantity * COALESCE(product_batches.sell_price, 0)) as retail_value')
            )
            ->first();

        return response()->json(['success' => true, 'data' => $inventoryValue]);
    }

    public function expenseSummary(Request $request)
    {
        [$dateFrom, $dateTo] = $this->dateRangeFromRequest($request);

        $summary = Expense::whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->select(
                'expense_categories.name as category',
                'expense_categories.type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(expenses.total_amount) as total')
            )
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.type')
            ->orderByDesc('total')
            ->get();

        return response()->json(['success' => true, 'data' => $summary]);
    }

    private function getSalesSummary($dateRange)
    {
        $orderValueSql = $this->orderValueSql();
        $row = $this->applySalesScope(Order::query())
            ->whereBetween('created_at', $dateRange)
            ->selectRaw("COUNT(*) as total_orders, SUM({$orderValueSql}) as total_revenue, SUM(COALESCE(paid_amount, 0)) as total_paid")
            ->first();

        return [
            'total_orders' => (int) ($row->total_orders ?? 0),
            'completed_orders' => (int) ($row->total_orders ?? 0),
            'total_revenue' => (float) ($row->total_revenue ?? 0),
            'total_paid' => (float) ($row->total_paid ?? 0),
        ];
    }

    private function getInventorySummary()
    {
        return [
            'total_products' => Product::count(),
            'low_stock_products' => Product::whereHas('batches', function ($q) {
                $q->where('quantity', '>', 0)->where('quantity', '<=', 5);
            })->count(),
            'out_of_stock' => Product::whereDoesntHave('batches', function ($q) {
                $q->where('quantity', '>', 0);
            })->count(),
        ];
    }

    private function getCustomerSummary($dateRange)
    {
        return [
            'total_customers' => Customer::count(),
            'new_customers' => Customer::whereBetween('created_at', $dateRange)->count(),
            'active_customers' => Customer::where('status', 'active')->count(),
        ];
    }

    private function getTopProducts($dateRange, $limit)
    {
        $lineNet = $this->lineNetSql();

        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('orders.created_at', $dateRange)
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', $this->excludedOrderStatuses)
            ->select(
                'products.name',
                DB::raw('SUM(COALESCE(order_items.quantity, 0)) as quantity'),
                DB::raw("SUM({$lineNet}) as revenue")
            )
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get();
    }

    private function getAlerts()
    {
        return [
            'low_stock_count' => Product::whereHas('batches', function ($q) {
                $q->where('quantity', '>', 0)->where('quantity', '<=', 5);
            })->count(),
            'pending_orders' => Order::whereIn('status', ['pending', 'pending_assignment'])->count(),
            'overdue_payments' => $this->applySalesScope(Order::query())
                ->whereIn('payment_status', ['unpaid', 'partial', 'partially_paid'])
                ->where('created_at', '<', now()->subDays(7))
                ->count(),
        ];
    }

    private function getDateRange($period)
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }
}
