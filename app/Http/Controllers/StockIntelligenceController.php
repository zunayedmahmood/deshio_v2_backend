<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * StockIntelligenceController
 *
 * Two endpoints:
 *
 * 1. GET /api/inventory/intelligence
 *    Product-level velocity + rebalancing suggestions (existing, fixed)
 *
 * 2. GET /api/inventory/intelligence/batch-report
 *    Granular batch/PO-level report per product:
 *    For each product → for each PO → for each batch from that PO:
 *      - Batch number, PO number, vendor, received date, store
 *      - Units received, remaining stock, units sold from that batch
 *      - Revenue generated, COGS, gross profit from that batch
 *      - Sell-through rate, days since received, velocity since receipt
 *      - Which stores the batch has been dispatched to
 */
class StockIntelligenceController extends Controller
{
    // =========================================================================
    // 1. Product-level intelligence (existing endpoint, fixed)
    // GET /api/inventory/intelligence?days=30&store_id=&min_stock_gap=1
    // =========================================================================
    public function index(Request $request)
    {
        $days        = max(7, (int) $request->query('days', 30));
        $filterStore = $request->query('store_id');
        $minGap      = max(1, (int) $request->query('min_stock_gap', 1));

        $from = Carbon::now()->subDays($days)->startOfDay();
        $to   = Carbon::now()->endOfDay();

        $salesRows = $this->getPerStoreSalesVelocity($from, $to, $filterStore);
        $stockRows = $this->getCurrentStockPerStore($filterStore);

        [$productMap, $storeMap] = $this->buildMaps($salesRows, $stockRows);

        $intelligence  = $this->computeIntelligence($productMap, $storeMap, $days, $minGap);
        $branchSummary = $this->buildBranchSummary($salesRows, $stockRows, $storeMap, $days);

        return response()->json([
            'success' => true,
            'data' => [
                'period_days'       => $days,
                'generated_at'      => Carbon::now()->toIso8601String(),
                'branch_summary'    => $branchSummary,
                'recommendations'   => $intelligence['recommendations'],
                'best_sellers'      => $intelligence['best_sellers'],
                'slow_movers'       => $intelligence['slow_movers'],
                'cross_store_stars' => $intelligence['cross_store_stars'],
                'stats' => [
                    'total_products_tracked' => count($productMap),
                    'total_recommendations'  => count($intelligence['recommendations']),
                    'urgent_count'           => collect($intelligence['recommendations'])->where('urgency', 'urgent')->count(),
                    'high_count'             => collect($intelligence['recommendations'])->where('urgency', 'high')->count(),
                ],
            ],
        ]);
    }

    // =========================================================================
    // 2. Batch/PO-level granular report
    // GET /api/inventory/intelligence/batch-report
    //   ?product_id=   (optional — filter to one product)
    //   ?store_id=     (optional — filter by receiving store)
    //   ?po_id=        (optional — filter to one PO)
    //   ?search=       (optional — search product name/SKU/PO number)
    //   ?sort_by=      (sold_units|remaining|sell_through|revenue|received_date)
    //   ?sort_dir=     (desc|asc)
    //   ?per_page=     (default 20)
    //   ?page=         (default 1)
    // =========================================================================
    public function batchReport(Request $request)
    {
        $filterProduct = $request->query('product_id');
        $filterStore   = $request->query('store_id');
        $filterPO      = $request->query('po_id');
        $search        = $request->query('search');
        $sortBy        = $request->query('sort_by', 'received_date');
        $sortDir       = strtolower($request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage       = min(100, max(5, (int) $request->query('per_page', 20)));
        $page          = max(1, (int) $request->query('page', 1));

        // ── Step 1: Get all batches linked to POs ──────────────────────────
        // The link is: product_batches ← purchase_order_items.product_batch_id
        //              purchase_order_items → purchase_orders
        $batchQuery = DB::table('product_batches as pb')
            ->join('products as p',    'pb.product_id', '=', 'p.id')
            ->join('stores as s',      'pb.store_id',   '=', 's.id')
            // Link batch → PO item → PO
            ->leftJoin('purchase_order_items as poi', 'poi.product_batch_id', '=', 'pb.id')
            ->leftJoin('purchase_orders as po',       'poi.purchase_order_id', '=', 'po.id')
            ->leftJoin('vendors as v',                'po.vendor_id', '=', 'v.id')
            ->whereNull('p.deleted_at')
            ->where('pb.is_active', true)
            ->select([
                'pb.id as batch_id',
                'pb.batch_number',
                'pb.product_id',
                'p.name as product_name',
                'p.sku as product_sku',
                'pb.store_id',
                's.name as store_name',
                'pb.quantity as remaining_stock',
                'pb.cost_price',
                'pb.sell_price',
                'pb.created_at as batch_created_at',
                // PO fields (null if batch wasn't from a PO)
                'po.id as po_id',
                'po.po_number',
                'po.order_date as po_order_date',
                'po.actual_delivery_date as po_received_date',
                'po.status as po_status',
                'v.id as vendor_id',
                'v.name as vendor_name',
                // PO item fields
                'poi.quantity_ordered',
                'poi.quantity_received as po_qty_received',
                'poi.unit_cost as po_unit_cost',
                'poi.unit_sell_price as po_sell_price',
            ]);

        // Filters
        if ($filterProduct) $batchQuery->where('pb.product_id', $filterProduct);
        if ($filterStore)   $batchQuery->where('pb.store_id',   $filterStore);
        if ($filterPO)      $batchQuery->where('po.id',         $filterPO);
        if ($search) {
            $batchQuery->where(function ($q) use ($search) {
                $q->where('p.name',      'like', "%{$search}%")
                  ->orWhere('p.sku',     'like', "%{$search}%")
                  ->orWhere('pb.batch_number', 'like', "%{$search}%")
                  ->orWhere('po.po_number',    'like', "%{$search}%");
            });
        }

        $batches = $batchQuery->get();

        if ($batches->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'items'      => [],
                    'total'      => 0,
                    'page'       => $page,
                    'per_page'   => $perPage,
                    'last_page'  => 1,
                    'summary'    => $this->emptyBatchSummary(),
                ],
            ]);
        }

        // ── Step 2: Get sales per batch ────────────────────────────────────
        // order_items.product_batch_id links each sale line to its source batch
        $batchIds = $batches->pluck('batch_id')->unique()->filter()->values()->toArray();

        $salesPerBatch = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->whereNull('o.deleted_at')
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->whereIn('oi.product_batch_id', $batchIds)
            ->select([
                'oi.product_batch_id as batch_id',
                DB::raw('SUM(oi.quantity) as units_sold'),
                DB::raw('SUM(oi.total_amount) as revenue'),
                DB::raw('SUM(oi.cogs) as total_cogs'),
                DB::raw('COUNT(DISTINCT o.id) as order_count'),
                DB::raw('MIN(o.order_date) as first_sale_date'),
                DB::raw('MAX(o.order_date) as last_sale_date'),
            ])
            ->groupBy('oi.product_batch_id')
            ->get()
            ->keyBy('batch_id');

        // ── Step 3: Get store distribution (where barcodes of this batch went) ──
        $barcodeDistrib = DB::table('product_barcodes as bc')
            ->join('stores as s', 'bc.current_store_id', '=', 's.id')
            ->whereIn('bc.batch_id', $batchIds)
            ->where('bc.is_active', true)
            ->select([
                'bc.batch_id',
                'bc.current_store_id as store_id',
                's.name as store_name',
                'bc.current_status',
                DB::raw('COUNT(*) as barcode_count'),
            ])
            ->groupBy('bc.batch_id', 'bc.current_store_id', 's.name', 'bc.current_status')
            ->get()
            ->groupBy('batch_id');

        // ── Step 4: Enrich each batch row ──────────────────────────────────
        $enriched = $batches->map(function ($batch) use ($salesPerBatch, $barcodeDistrib) {
            $sales    = $salesPerBatch[$batch->batch_id] ?? null;
            $distrib  = $barcodeDistrib[$batch->batch_id] ?? collect();

            $unitsSold     = $sales ? (int) $sales->units_sold    : 0;
            $revenue       = $sales ? round((float) $sales->revenue, 2)    : 0.0;
            $totalCogs     = $sales ? round((float) $sales->total_cogs, 2) : 0.0;
            $grossProfit   = round($revenue - $totalCogs, 2);
            $orderCount    = $sales ? (int) $sales->order_count   : 0;
            $firstSale     = $sales ? $sales->first_sale_date     : null;
            $lastSale      = $sales ? $sales->last_sale_date      : null;

            // Units received from PO (or fall back to remaining + sold if no PO link)
            $poQtyReceived   = $batch->po_qty_received ?? null;
            $originalQty     = $poQtyReceived ?? ($batch->remaining_stock + $unitsSold);
            $remaining       = (int) $batch->remaining_stock;

            // Sell-through rate
            $sellThrough = $originalQty > 0
                ? round(($unitsSold / $originalQty) * 100, 1)
                : 0.0;

            // Days since received / batch created
            $receivedAt  = $batch->po_received_date ?? $batch->batch_created_at;
            $daysSinceReceived = $receivedAt
                ? Carbon::parse($receivedAt)->diffInDays(Carbon::now())
                : null;

            // Velocity since receipt (units/day)
            $velocitySinceReceipt = ($daysSinceReceived && $daysSinceReceived > 0)
                ? round($unitsSold / $daysSinceReceived, 3)
                : 0.0;

            // Days of stock remaining at current velocity
            $daysOfStock = ($velocitySinceReceipt > 0 && $remaining > 0)
                ? round($remaining / $velocitySinceReceipt)
                : ($remaining > 0 ? 999 : 0);

            // Store distribution summary
            $distribSummary = $distrib->groupBy('store_id')->map(function ($rows, $storeId) {
                return [
                    'store_id'   => (int) $storeId,
                    'store_name' => $rows->first()->store_name,
                    'count'      => $rows->sum('barcode_count'),
                    'statuses'   => $rows->pluck('current_status', 'current_status')->keys()->values(),
                ];
            })->values()->toArray();

            return [
                // Batch info
                'batch_id'              => (int) $batch->batch_id,
                'batch_number'          => $batch->batch_number,
                'batch_created_at'      => $batch->batch_created_at,

                // Product info
                'product_id'            => (int) $batch->product_id,
                'product_name'          => $batch->product_name,
                'product_sku'           => $batch->product_sku,

                // Store info (original receiving store)
                'store_id'              => (int) $batch->store_id,
                'store_name'            => $batch->store_name,

                // PO info (null if batch wasn't created from a PO)
                'po_id'                 => $batch->po_id ? (int) $batch->po_id : null,
                'po_number'             => $batch->po_number,
                'po_order_date'         => $batch->po_order_date,
                'po_received_date'      => $batch->po_received_date,
                'po_status'             => $batch->po_status,
                'vendor_id'             => $batch->vendor_id ? (int) $batch->vendor_id : null,
                'vendor_name'           => $batch->vendor_name,

                // Stock
                'original_qty'          => (int) $originalQty,
                'remaining_stock'       => $remaining,
                'cost_price'            => round((float) $batch->cost_price, 2),
                'sell_price'            => round((float) $batch->sell_price, 2),

                // Sales performance
                'units_sold'            => $unitsSold,
                'order_count'           => $orderCount,
                'revenue'               => $revenue,
                'total_cogs'            => $totalCogs,
                'gross_profit'          => $grossProfit,
                'margin_pct'            => $revenue > 0 ? round(($grossProfit / $revenue) * 100, 1) : 0.0,
                'first_sale_date'       => $firstSale,
                'last_sale_date'        => $lastSale,

                // Intelligence
                'sell_through_pct'      => $sellThrough,
                'days_since_received'   => $daysSinceReceived,
                'velocity_per_day'      => $velocitySinceReceipt,
                'days_of_stock'         => $daysOfStock === 999 ? null : $daysOfStock,
                'stock_value'           => round($remaining * (float) $batch->cost_price, 2),
                'potential_revenue'     => round($remaining * (float) $batch->sell_price, 2),

                // Where barcodes of this batch currently are
                'store_distribution'    => $distribSummary,
            ];
        });

        // ── Step 5: Sort ───────────────────────────────────────────────────
        $sortMap = [
            'sold_units'    => 'units_sold',
            'remaining'     => 'remaining_stock',
            'sell_through'  => 'sell_through_pct',
            'revenue'       => 'revenue',
            'received_date' => 'po_received_date',
            'velocity'      => 'velocity_per_day',
            'days_of_stock' => 'days_of_stock',
        ];
        $sortField = $sortMap[$sortBy] ?? 'po_received_date';

        $sorted = $sortDir === 'asc'
            ? $enriched->sortBy($sortField, SORT_REGULAR, false)
            : $enriched->sortByDesc($sortField);

        // ── Step 6: Group by product then by PO ───────────────────────────
        $grouped = $sorted->groupBy('product_id')->map(function ($productBatches, $productId) {
            $first = $productBatches->first();

            // Group batches by PO within each product
            $byPO = $productBatches->groupBy(fn($b) => $b['po_number'] ?? 'no_po')->map(function ($poBatches, $poKey) {
                $firstPO = $poBatches->first();
                return [
                    'po_id'           => $firstPO['po_id'],
                    'po_number'       => $firstPO['po_number'] ?? 'Manual/Direct',
                    'po_order_date'   => $firstPO['po_order_date'],
                    'po_received_date'=> $firstPO['po_received_date'],
                    'po_status'       => $firstPO['po_status'],
                    'vendor_id'       => $firstPO['vendor_id'],
                    'vendor_name'     => $firstPO['vendor_name'] ?? 'Unknown',
                    // PO-level totals
                    'po_original_qty' => $poBatches->sum('original_qty'),
                    'po_remaining'    => $poBatches->sum('remaining_stock'),
                    'po_units_sold'   => $poBatches->sum('units_sold'),
                    'po_revenue'      => round($poBatches->sum('revenue'), 2),
                    'po_gross_profit' => round($poBatches->sum('gross_profit'), 2),
                    'po_sell_through' => $poBatches->sum('original_qty') > 0
                        ? round(($poBatches->sum('units_sold') / $poBatches->sum('original_qty')) * 100, 1)
                        : 0.0,
                    'po_stock_value'  => round($poBatches->sum('stock_value'), 2),
                    // Individual batches under this PO
                    'batches'         => $poBatches->values()->toArray(),
                ];
            })->values();

            // Product-level totals
            return [
                'product_id'       => (int) $productId,
                'product_name'     => $first['product_name'],
                'product_sku'      => $first['product_sku'],
                'total_original'   => $productBatches->sum('original_qty'),
                'total_remaining'  => $productBatches->sum('remaining_stock'),
                'total_sold'       => $productBatches->sum('units_sold'),
                'total_revenue'    => round($productBatches->sum('revenue'), 2),
                'total_profit'     => round($productBatches->sum('gross_profit'), 2),
                'overall_sell_through' => $productBatches->sum('original_qty') > 0
                    ? round(($productBatches->sum('units_sold') / $productBatches->sum('original_qty')) * 100, 1)
                    : 0.0,
                'avg_velocity'     => round($productBatches->avg('velocity_per_day'), 3),
                'total_stock_value'=> round($productBatches->sum('stock_value'), 2),
                'po_count'         => $by_po_count = $productBatches->pluck('po_number')->unique()->filter()->count(),
                'batch_count'      => $productBatches->count(),
                'by_po'            => $byPO->toArray(),
            ];
        })->values();

        // ── Step 7: Paginate ───────────────────────────────────────────────
        $total      = $grouped->count();
        $lastPage   = max(1, (int) ceil($total / $perPage));
        $offset     = ($page - 1) * $perPage;
        $paginated  = $grouped->slice($offset, $perPage)->values();

        // ── Step 8: Overall summary ────────────────────────────────────────
        $allEnriched = $enriched; // full set before pagination
        $summary = [
            'total_products'   => $grouped->count(),
            'total_batches'    => $allEnriched->count(),
            'total_pos'        => $allEnriched->pluck('po_number')->unique()->filter()->count(),
            'total_original'   => $allEnriched->sum('original_qty'),
            'total_remaining'  => $allEnriched->sum('remaining_stock'),
            'total_sold'       => $allEnriched->sum('units_sold'),
            'total_revenue'    => round($allEnriched->sum('revenue'), 2),
            'total_profit'     => round($allEnriched->sum('gross_profit'), 2),
            'total_stock_value'=> round($allEnriched->sum('stock_value'), 2),
            'overall_sell_through' => $allEnriched->sum('original_qty') > 0
                ? round(($allEnriched->sum('units_sold') / $allEnriched->sum('original_qty')) * 100, 1)
                : 0.0,
        ];

        return response()->json([
            'success'  => true,
            'data' => [
                'items'     => $paginated,
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'last_page' => $lastPage,
                'summary'   => $summary,
            ],
        ]);
    }

    // =========================================================================
    // Private helpers for endpoint 1 (product-level)
    // =========================================================================

    private function getPerStoreSalesVelocity(Carbon $from, Carbon $to, $filterStore = null): Collection
    {
        $query = DB::table('order_items')
            ->join('orders',   'order_items.order_id',   '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereNull('orders.deleted_at')
            ->whereNull('products.deleted_at')
            ->whereNotIn('orders.status', ['cancelled', 'refunded'])
            ->whereBetween('orders.order_date', [$from, $to])
            ->select([
                'order_items.product_id',
                'orders.store_id',
                DB::raw('COALESCE(order_items.product_name, products.name) as product_name'),
                DB::raw('COALESCE(order_items.product_sku, products.sku) as sku'),
                DB::raw('SUM(order_items.quantity) as units_sold'),
                DB::raw('SUM(order_items.total_amount) as revenue'),
                DB::raw('SUM(order_items.cogs) as cogs'),
            ])
            ->groupBy('order_items.product_id', 'orders.store_id', 'order_items.product_name', 'order_items.product_sku', 'products.name', 'products.sku');

        if ($filterStore) $query->where('orders.store_id', $filterStore);

        return $query->get();
    }

    private function getCurrentStockPerStore($filterStore = null): Collection
    {
        $query = DB::table('product_batches')
            ->join('products', 'product_batches.product_id', '=', 'products.id')
            ->join('stores',   'product_batches.store_id',   '=', 'stores.id')
            ->whereNull('products.deleted_at')
            ->where('product_batches.is_active', true)
            ->where('product_batches.quantity', '>', 0)
            ->select([
                'product_batches.product_id',
                'product_batches.store_id',
                'products.name as product_name',
                'products.sku as sku',
                'stores.name as store_name',
                DB::raw('SUM(product_batches.quantity) as quantity'),
                DB::raw('MIN(product_batches.id) as batch_id'),
                DB::raw('AVG(product_batches.cost_price) as cost_price'),
                DB::raw('AVG(product_batches.sell_price) as sell_price'),
            ])
            ->groupBy('product_batches.product_id', 'product_batches.store_id', 'products.name', 'products.sku', 'stores.name');

        if ($filterStore) $query->where('product_batches.store_id', $filterStore);

        return $query->get();
    }

    private function buildMaps(Collection $salesRows, Collection $stockRows): array
    {
        $productMap = [];
        $storeMap   = [];

        foreach ($stockRows as $row) $storeMap[$row->store_id] = $row->store_name;
        foreach ($salesRows as $row) { if (!isset($storeMap[$row->store_id])) $storeMap[$row->store_id] = 'Store ' . $row->store_id; }

        foreach ($stockRows as $row) {
            $pid = $row->product_id; $sid = $row->store_id;
            if (!isset($productMap[$pid])) {
                $productMap[$pid] = ['product_id' => $pid, 'product_name' => $row->product_name, 'sku' => $row->sku, 'stores' => []];
            }
            $productMap[$pid]['stores'][$sid] = ['store_id' => $sid, 'store_name' => $row->store_name, 'stock' => (int)$row->quantity, 'batch_id' => (int)$row->batch_id, 'cost_price' => (float)$row->cost_price, 'sell_price' => (float)$row->sell_price, 'units_sold' => 0, 'revenue' => 0.0, 'velocity' => 0.0, 'days_of_stock' => null];
        }

        foreach ($salesRows as $row) {
            $pid = $row->product_id; $sid = $row->store_id;
            if (!isset($productMap[$pid])) $productMap[$pid] = ['product_id' => $pid, 'product_name' => $row->product_name, 'sku' => $row->sku, 'stores' => []];
            if (!isset($productMap[$pid]['stores'][$sid])) $productMap[$pid]['stores'][$sid] = ['store_id' => $sid, 'store_name' => $storeMap[$sid] ?? 'Store '.$sid, 'stock' => 0, 'batch_id' => null, 'cost_price' => 0.0, 'sell_price' => 0.0, 'units_sold' => 0, 'revenue' => 0.0, 'velocity' => 0.0, 'days_of_stock' => null];
            $productMap[$pid]['stores'][$sid]['units_sold'] = (int)$row->units_sold;
            $productMap[$pid]['stores'][$sid]['revenue']    = round((float)$row->revenue, 2);
        }

        return [$productMap, $storeMap];
    }

    private function computeIntelligence(array $productMap, array $storeMap, int $days, int $minGap): array
    {
        $recommendations = []; $bestSellers = []; $slowMovers = []; $crossStoreStars = [];

        foreach ($productMap as $pid => $product) {
            $stores = $product['stores'];
            if (count($stores) < 1) continue;

            foreach ($stores as $sid => &$sd) {
                $vel = $days > 0 ? round($sd['units_sold'] / $days, 4) : 0;
                $sd['velocity'] = $vel;
                $sd['days_of_stock'] = $vel > 0 && $sd['stock'] > 0 ? round($sd['stock'] / $vel) : ($sd['stock'] > 0 ? 999 : 0);
            } unset($sd);

            $totalUnits = array_sum(array_column($stores, 'units_sold'));
            $bestSellers[] = ['product_id' => $pid, 'product_name' => $product['product_name'], 'sku' => $product['sku'], 'total_units' => $totalUnits, 'total_revenue' => round(array_sum(array_column($stores, 'revenue')), 2), 'total_stock' => array_sum(array_column($stores, 'stock')), 'by_store' => array_values($stores)];

            $hasStock = array_filter($stores, fn($s) => $s['stock'] > 0);
            $noSaleStores = array_filter($hasStock, fn($s) => $s['units_sold'] === 0);
            if (count($noSaleStores) > 0 && count($hasStock) > 0) {
                $dead = array_sum(array_column(array_values($noSaleStores), 'stock'));
                if ($dead >= $minGap) $slowMovers[] = ['product_id' => $pid, 'product_name' => $product['product_name'], 'sku' => $product['sku'], 'dead_stock' => $dead, 'affected_stores' => array_values(array_map(fn($s) => ['store_id' => $s['store_id'], 'store_name' => $s['store_name'], 'stock' => $s['stock']], $noSaleStores))];
            }

            if (count($stores) < 2) continue;
            $storeList = array_values($stores);
            usort($storeList, fn($a, $b) => $b['velocity'] <=> $a['velocity']);
            $top = $storeList[0]; $bottom = $storeList[count($storeList) - 1];

            if ($bottom['stock'] < $minGap) continue;
            if ($bottom['stock'] <= $top['stock'] && $top['stock'] > 0 && abs($top['velocity'] - $bottom['velocity']) < 0.01) continue;

            $velGap = $top['velocity'] - $bottom['velocity'];
            if ($velGap > 0.001) {
                $target = max($minGap, (int) round($velGap * min($days, 30) * 0.5));
            } else {
                $avg = array_sum(array_column($storeList, 'stock')) / count($storeList);
                $target = max($minGap, (int) floor(max(0, $bottom['stock'] - $avg) * 0.5));
            }
            $target = min($target, (int) floor($bottom['stock'] * 0.7));
            if ($target < $minGap) continue;

            $dos = $top['days_of_stock'] ?? 999;
            $score = round(($top['velocity'] * 10) + ($dos < 999 ? max(0, 100 - $dos) : 0) + ($bottom['days_of_stock'] === 999 ? 20 : 0) + ($velGap > 0.01 ? 15 : ($totalUnits === 0 ? 10 : 5)), 1);
            $urgency = $score >= 60 ? 'urgent' : ($score >= 35 ? 'high' : ($score >= 15 ? 'medium' : 'low'));

            if ($bottom['units_sold'] === 0 && $top['velocity'] > 0) $crossStoreStars[] = ['product_id' => $pid, 'product_name' => $product['product_name'], 'sku' => $product['sku'], 'hot_store_id' => $top['store_id'], 'hot_store_name' => $top['store_name'], 'hot_store_velocity' => $top['velocity'], 'dead_store_id' => $bottom['store_id'], 'dead_store_name' => $bottom['store_name'], 'dead_store_stock' => $bottom['stock']];

            $recommendations[] = ['product_id' => $pid, 'product_name' => $product['product_name'], 'sku' => $product['sku'], 'urgency' => $urgency, 'urgency_score' => $score, 'from_store_id' => $bottom['store_id'], 'from_store_name' => $bottom['store_name'], 'from_store_stock' => $bottom['stock'], 'from_store_velocity' => $bottom['velocity'], 'from_store_batch_id' => $bottom['batch_id'], 'to_store_id' => $top['store_id'], 'to_store_name' => $top['store_name'], 'to_store_stock' => $top['stock'], 'to_store_velocity' => $top['velocity'], 'to_store_days_remaining' => $dos === 999 ? null : $dos, 'suggested_quantity' => $target, 'reason' => $velGap > 0.001 ? "{$top['store_name']} sells " . number_format($top['velocity'], 3) . " u/day vs {$bottom['store_name']}'s " . number_format($bottom['velocity'], 3) . " u/day." . ($bottom['units_sold'] === 0 ? " {$bottom['store_name']} had zero sales." : '') : "{$bottom['store_name']} has {$bottom['stock']} units idle while {$top['store_name']} has {$top['stock']} units.", 'estimated_value' => round($target * (float)($top['sell_price'] ?: $bottom['sell_price']), 2), 'all_stores' => $storeList];
        }

        usort($recommendations, fn($a, $b) => $b['urgency_score'] <=> $a['urgency_score']);
        usort($bestSellers,     fn($a, $b) => $b['total_units']   <=> $a['total_units']);
        usort($slowMovers,      fn($a, $b) => $b['dead_stock']     <=> $a['dead_stock']);

        return ['recommendations' => array_slice($recommendations, 0, 50), 'best_sellers' => array_slice($bestSellers, 0, 20), 'slow_movers' => array_slice($slowMovers, 0, 20), 'cross_store_stars' => array_slice($crossStoreStars, 0, 20)];
    }

    private function buildBranchSummary(Collection $salesRows, Collection $stockRows, array $storeMap, int $days): array
    {
        $summary = [];
        foreach ($storeMap as $sid => $storeName) {
            $ss = $salesRows->where('store_id', $sid);
            $sr = $stockRows->where('store_id',  $sid);
            $stockSkus = $sr->pluck('product_id')->unique();
            $soldSkus  = $ss->pluck('product_id')->unique();
            $summary[] = ['store_id' => (int)$sid, 'store_name' => $storeName, 'units_sold' => (int)$ss->sum('units_sold'), 'revenue' => round((float)$ss->sum('revenue'), 2), 'velocity_day' => $days > 0 ? round((int)$ss->sum('units_sold') / $days, 2) : 0, 'total_stock' => (int)$sr->sum('quantity'), 'sku_count' => $stockSkus->count(), 'dead_sku_count' => $stockSkus->diff($soldSkus)->count(), 'stock_value' => round((float)$sr->sum(fn($r) => $r->cost_price * $r->quantity), 2)];
        }
        usort($summary, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        return $summary;
    }

    private function emptyBatchSummary(): array
    {
        return ['total_products' => 0, 'total_batches' => 0, 'total_pos' => 0, 'total_original' => 0, 'total_remaining' => 0, 'total_sold' => 0, 'total_revenue' => 0, 'total_profit' => 0, 'total_stock_value' => 0, 'overall_sell_through' => 0.0];
    }
}