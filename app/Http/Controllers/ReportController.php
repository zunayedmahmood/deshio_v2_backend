<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use DatabaseAgnosticSearch;
    public function dashboard(Request $request)
    {
        $period = $request->get('period', 'today'); // today, week, month, year

        $dateRange = $this->getDateRange($period);

        $dashboard = [
            'sales_summary' => $this->getSalesSummary($dateRange),
            'inventory_summary' => $this->getInventorySummary(),
            'customer_summary' => $this->getCustomerSummary($dateRange),
            'top_products' => $this->getTopProducts($dateRange, 5),
            'recent_orders' => Order::with(['customer', 'items'])
                ->whereBetween('created_at', $dateRange)
                ->latest()
                ->limit(10)
                ->get(),
            'alerts' => $this->getAlerts(),
        ];

        return response()->json(['success' => true, 'data' => $dashboard]);
    }

    public function salesSummary(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth());
        $dateTo = $request->get('date_to', now()->endOfMonth());
        $groupBy = $request->get('group_by', 'day'); // day, week, month

        $query = Order::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'completed');

        $dateFormatSql = $this->getDateFormatSql('created_at', $groupBy);

        $salesData = $query->selectRaw("
                {$dateFormatSql} as period,
                COUNT(*) as total_orders,
                SUM(total_amount) as total_sales,
                SUM(paid_amount) as total_paid,
                AVG(total_amount) as average_order_value
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get();

        $summary = [
            'total_orders' => $query->count(),
            'total_sales' => (float) $query->sum('total_amount'),
            'total_paid' => (float) $query->sum('paid_amount'),
            'average_order_value' => (float) $query->avg('total_amount'),
            'sales_data' => $salesData,
        ];

        return response()->json(['success' => true, 'data' => $summary]);
    }

    public function bestSellers(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth());
        $dateTo = $request->get('date_to', now()->endOfMonth());
        $limit = $request->get('limit', 20);

        $bestSellers = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->where('orders.status', 'completed')
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.subtotal) as total_revenue'),
                DB::raw('COUNT(DISTINCT orders.id) as order_count'),
                DB::raw('AVG(order_items.unit_price) as average_price')
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get();

        return response()->json(['success' => true, 'data' => $bestSellers]);
    }

    public function slowMoving(Request $request)
    {
        $days = $request->get('days', 30);
        $limit = $request->get('limit', 20);

        $slowMoving = Product::whereHas('batches', function($q) {
                $q->where('current_stock', '>', 0);
            })
            ->whereDoesntHave('orderItems', function($q) use ($days) {
                $q->whereHas('order', function($q) use ($days) {
                    $q->where('created_at', '>=', now()->subDays($days));
                });
            })
            ->with(['batches' => function($q) {
                $q->where('current_stock', '>', 0);
            }])
            ->limit($limit)
            ->get()
            ->map(function($product) {
                $product->total_stock = $product->batches->sum('current_stock');
                return $product;
            });

        return response()->json(['success' => true, 'data' => $slowMoving]);
    }

    public function staffPerformance(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth());
        $dateTo = $request->get('date_to', now()->endOfMonth());

        $performance = Employee::with('role')
            ->leftJoin('orders', 'employees.id', '=', 'orders.employee_id')
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->where('orders.status', 'completed')
            ->select(
                'employees.id',
                'employees.name',
                'employees.employee_code',
                DB::raw('COUNT(orders.id) as total_orders'),
                DB::raw('SUM(orders.total_amount) as total_sales'),
                DB::raw('AVG(orders.total_amount) as average_order_value')
            )
            ->groupBy('employees.id', 'employees.name', 'employees.employee_code')
            ->orderByDesc('total_sales')
            ->get();

        return response()->json(['success' => true, 'data' => $performance]);
    }

    public function profitMargins(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth());
        $dateTo = $request->get('date_to', now()->endOfMonth());

        $margins = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->where('orders.status', 'completed')
            ->select(
                'products.id',
                'products.name',
                'products.cost_price',
                DB::raw('SUM(order_items.quantity) as units_sold'),
                DB::raw('SUM(order_items.subtotal) as revenue'),
                DB::raw('SUM(order_items.quantity * products.cost_price) as cost'),
                DB::raw('SUM(order_items.subtotal - (order_items.quantity * products.cost_price)) as profit'),
                DB::raw('((SUM(order_items.subtotal - (order_items.quantity * products.cost_price)) / SUM(order_items.subtotal)) * 100) as margin_percent')
            )
            ->groupBy('products.id', 'products.name', 'products.cost_price')
            ->orderByDesc('profit')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'data' => $margins]);
    }

    public function customerAcquisition(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfYear());
        $dateTo = $request->get('date_to', now()->endOfYear());

        $dateFormatSql = $this->getDateFormatSql('created_at', 'month');

        $newCustomers = Customer::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw("{$dateFormatSql} as month, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $returningCustomers = Order::whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereIn('customer_id', function($query) use ($dateFrom) {
                $query->select('customer_id')
                    ->from('orders')
                    ->where('created_at', '<', $dateFrom)
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
            ]
        ]);
    }

    public function inventoryValue(Request $request)
    {
        $storeId = $request->get('store_id');

        $query = DB::table('product_batches')
            ->join('products', 'product_batches.product_id', '=', 'products.id')
            ->where('product_batches.current_stock', '>', 0);

        if ($storeId) {
            $query->where('product_batches.store_id', $storeId);
        }

        $inventoryValue = $query->select(
                DB::raw('SUM(product_batches.current_stock) as total_units'),
                DB::raw('SUM(product_batches.current_stock * products.cost_price) as cost_value'),
                DB::raw('SUM(product_batches.current_stock * products.selling_price) as retail_value')
            )
            ->first();

        return response()->json(['success' => true, 'data' => $inventoryValue]);
    }

    public function expenseSummary(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth());
        $dateTo = $request->get('date_to', now()->endOfMonth());

        $summary = Expense::whereBetween('expense_date', [$dateFrom, $dateTo])
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
        return [
            'total_orders' => Order::whereBetween('created_at', $dateRange)->count(),
            'completed_orders' => Order::whereBetween('created_at', $dateRange)->where('status', 'completed')->count(),
            'total_revenue' => (float) Order::whereBetween('created_at', $dateRange)->sum('total_amount'),
            'total_paid' => (float) Order::whereBetween('created_at', $dateRange)->sum('paid_amount'),
        ];
    }

    private function getInventorySummary()
    {
        return [
            'total_products' => Product::count(),
            'low_stock_products' => Product::whereHas('batches', function($q) {
                $q->whereRaw('current_stock <= reorder_point');
            })->count(),
            'out_of_stock' => Product::whereDoesntHave('batches', function($q) {
                $q->where('current_stock', '>', 0);
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
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('orders.created_at', $dateRange)
            ->where('orders.status', 'completed')
            ->select(
                'products.name',
                DB::raw('SUM(order_items.quantity) as quantity'),
                DB::raw('SUM(order_items.subtotal) as revenue')
            )
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get();
    }

    private function getAlerts()
    {
        return [
            'low_stock_count' => Product::whereHas('batches', function($q) {
                $q->whereRaw('current_stock <= reorder_point');
            })->count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'overdue_payments' => Order::where('payment_status', 'unpaid')
                ->where('created_at', '<', now()->subDays(7))
                ->count(),
        ];
    }

    private function getDateRange($period)
    {
        return match($period) {
            'today' => [now()->startOfDay(), now()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }
}

