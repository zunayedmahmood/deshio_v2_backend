<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * GenerateDailyBranchReport
 *
 * One row = one calendar day × one branch.
 *
 * Columns
 * ───────
 * Date | Branch
 * | POS Sales | Online Sales | Social Commerce Sales | Total Sales
 * | Cash In | Card In | MFS In | Bank In | Total Money In
 * | Daily Expenses
 * | Net Cash Position
 * | Notes
 *
 * Usage
 * ─────
 *   php artisan report:daily-branch                             # yesterday, all branches
 *   php artisan report:daily-branch --date=2026-04-10           # one day
 *   php artisan report:daily-branch --from=2026-04-01 --to=2026-04-10
 *   php artisan report:daily-branch --store=3                   # one branch
 *   php artisan report:daily-branch --combined                  # one file, all branches
 */
class GenerateDailyBranchReport extends Command
{
    protected $signature = 'report:daily-branch
                            {--date=    : Single date YYYY-MM-DD. Defaults to yesterday.}
                            {--from=    : Start of date range.}
                            {--to=      : End of date range (inclusive).}
                            {--store=   : Limit to one branch by store ID.}
                            {--combined : All branches in one CSV instead of per-branch files.}
                            {--out=     : Output directory. Defaults to storage/app/reports/}';

    protected $description = 'Generate daily branch sales & expense CSV report(s)';

    // payment_methods.type  ->  CSV bucket
    private const PM_BUCKET = [
        'cash'           => 'cash_in',
        'card'           => 'card_in',
        'mobile_banking' => 'mfs_in',
        'digital_wallet' => 'mfs_in',   // bKash, Nagad app wallets
        'bank_transfer'  => 'bank_in',
        'online_banking' => 'bank_in',
        'online_pay'     => 'bank_in',
    ];

    public function handle(): int
    {
        [$dateFrom, $dateTo] = $this->resolveDateRange();
        $storeFilter = $this->option('store') ? (int) $this->option('store') : null;
        $combined    = (bool) $this->option('combined');
        $outDir      = rtrim($this->option('out') ?: storage_path('app/reports'), '/');

        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $this->info("Generating daily branch report: {$dateFrom} to {$dateTo}");

        $stores = DB::table('stores')
            ->when($storeFilter, fn($q) => $q->where('id', $storeFilter))
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($stores->isEmpty()) {
            $this->error('No active stores found' . ($storeFilter ? " for store_id={$storeFilter}" : '') . '.');
            return self::FAILURE;
        }

        $storeIds = $stores->pluck('id')->toArray();
        $dates    = collect(CarbonPeriod::create($dateFrom, $dateTo))
                        ->map(fn($d) => $d->toDateString());

        // Three bulk queries — no per-row DB hits
        $salesData   = $this->loadSalesData($storeIds, $dateFrom, $dateTo);
        $paymentData = $this->loadPaymentData($storeIds, $dateFrom, $dateTo);
        $expenseData = $this->loadExpenseData($storeIds, $dateFrom, $dateTo);

        // Build all rows
        $allRows = [];
        foreach ($stores as $store) {
            foreach ($dates as $date) {
                $allRows[] = $this->buildRow($date, $store, $salesData, $paymentData, $expenseData);
            }
        }

        // Write file(s)
        $headers = $this->csvHeaders();
        $files   = [];

        if ($combined) {
            $path    = "{$outDir}/daily_branch_report_{$dateFrom}_to_{$dateTo}.csv";
            $this->writeCsv($path, $headers, $allRows);
            $files[] = $path;
        } else {
            foreach ($stores as $store) {
                $branchRows = array_values(
                    array_filter($allRows, fn($r) => $r['_store_id'] === $store->id)
                );
                $safeName = preg_replace('/[^a-z0-9]+/i', '_', strtolower($store->name));
                $path     = "{$outDir}/branch_{$safeName}_{$dateFrom}_to_{$dateTo}.csv";
                $this->writeCsv($path, $headers, $branchRows);
                $files[]  = $path;
            }
        }

        foreach ($files as $f) {
            $this->line("  <info>OK</info> {$f}");
        }

        $this->info('Done.');
        return self::SUCCESS;
    }

    // ─── Data loaders ────────────────────────────────────────────────────────
    // All return [store_id][date][...] so buildRow() is pure array lookup.

    /**
     * Sales totals by order_type per store per day.
     *   counter          -> POS Sales
     *   ecommerce        -> Online Sales
     *   social_commerce  -> Social Commerce Sales
     * Cancelled / refunded orders excluded.
     */
    private function loadSalesData(array $storeIds, string $from, string $to): array
    {
        $rows = DB::table('orders')
            ->select(
                'store_id',
                DB::raw('DATE(created_at) as day'),
                'order_type',
                DB::raw('SUM(total_amount) as total')
            )
            ->whereIn('store_id', $storeIds)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereIn('order_type', ['counter', 'ecommerce', 'social_commerce'])
            ->groupBy('store_id', 'day', 'order_type')
            ->get();

        // [store_id][date][order_type] = float
        $out = [];
        foreach ($rows as $r) {
            $out[$r->store_id][$r->day][$r->order_type] = (float) $r->total;
        }
        return $out;
    }

    /**
     * Completed order payments bucketed by payment method type.
     * Joins payment_methods to get type, maps via PM_BUCKET to four columns.
     * Virtual types (exchange_balance, store_credit, balance_carryover) excluded.
     */
    private function loadPaymentData(array $storeIds, string $from, string $to): array
    {
        $rows = DB::table('order_payments as op')
            ->join('payment_methods as pm', 'pm.id', '=', 'op.payment_method_id')
            ->select(
                'op.store_id',
                DB::raw('DATE(op.completed_at) as day'),
                'pm.type as method_type',
                DB::raw('SUM(op.amount) as total')
            )
            ->whereIn('op.store_id', $storeIds)
            ->whereDate('op.completed_at', '>=', $from)
            ->whereDate('op.completed_at', '<=', $to)
            ->where('op.status', 'completed')
            ->whereNotIn('op.payment_type', ['exchange_balance', 'store_credit', 'balance_carryover'])
            ->whereNotNull('op.completed_at')
            ->groupBy('op.store_id', 'day', 'pm.type')
            ->get();

        // [store_id][date][bucket] = float  (buckets accumulate across multiple method types)
        $out = [];
        foreach ($rows as $r) {
            $bucket = self::PM_BUCKET[$r->method_type] ?? 'bank_in';
            $out[$r->store_id][$r->day][$bucket] =
                ($out[$r->store_id][$r->day][$bucket] ?? 0) + (float) $r->total;
        }
        return $out;
    }

    /**
     * Approved / paid expenses per store per day.
     * This is the only cost column — rent, salary, utilities, etc.
     * Keyed by expense_date (when the expense occurred, not when it was approved).
     */
    private function loadExpenseData(array $storeIds, string $from, string $to): array
    {
        $rows = DB::table('expenses')
            ->select(
                'store_id',
                DB::raw('DATE(expense_date) as day'),
                DB::raw('SUM(total_amount) as total')
            )
            ->whereIn('store_id', $storeIds)
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->whereIn('status', ['approved', 'paid'])
            ->groupBy('store_id', 'day')
            ->get();

        // [store_id][date] = float
        $out = [];
        foreach ($rows as $r) {
            $out[$r->store_id][$r->day] = (float) $r->total;
        }
        return $out;
    }

    // ─── Row builder ─────────────────────────────────────────────────────────

    private function buildRow(
        string $date,
        object $store,
        array  $salesData,
        array  $paymentData,
        array  $expenseData
    ): array {
        $sid = $store->id;

        // Sales
        $posSales     = $salesData[$sid][$date]['counter']          ?? 0;
        $onlineSales  = $salesData[$sid][$date]['ecommerce']        ?? 0;
        $socialSales  = $salesData[$sid][$date]['social_commerce']  ?? 0;
        $totalSales   = $posSales + $onlineSales + $socialSales;

        // Money in by payment method bucket
        $cashIn       = $paymentData[$sid][$date]['cash_in'] ?? 0;
        $cardIn       = $paymentData[$sid][$date]['card_in'] ?? 0;
        $mfsIn        = $paymentData[$sid][$date]['mfs_in']  ?? 0;
        $bankIn       = $paymentData[$sid][$date]['bank_in'] ?? 0;
        $totalIn      = $cashIn + $cardIn + $mfsIn + $bankIn;

        // Daily cost = approved/paid expenses only
        $expenses     = $expenseData[$sid][$date] ?? 0;

        // Net = money actually received minus what was spent that day
        $netCash      = $totalIn - $expenses;

        return [
            '_store_id'                    => $sid,  // stripped before CSV write
            'Date'                         => $date,
            'Branch'                       => $store->name,
            'POS Sales (BDT)'              => $this->fmt($posSales),
            'Online Sales (BDT)'           => $this->fmt($onlineSales),
            'Social Commerce Sales (BDT)'  => $this->fmt($socialSales),
            'Total Sales (BDT)'            => $this->fmt($totalSales),
            'Cash In (BDT)'                => $this->fmt($cashIn),
            'Card In (BDT)'                => $this->fmt($cardIn),
            'MFS In (BDT)'                 => $this->fmt($mfsIn),
            'Bank In (BDT)'                => $this->fmt($bankIn),
            'Total Money In (BDT)'         => $this->fmt($totalIn),
            'Daily Expenses (BDT)'         => $this->fmt($expenses),
            'Net Cash Position (BDT)'      => $this->fmt($netCash),
            'Notes'                        => '',
        ];
    }

    // ─── CSV helpers ─────────────────────────────────────────────────────────

    private function csvHeaders(): array
    {
        return [
            'Date',
            'Branch',
            'POS Sales (BDT)',
            'Online Sales (BDT)',
            'Social Commerce Sales (BDT)',
            'Total Sales (BDT)',
            'Cash In (BDT)',
            'Card In (BDT)',
            'MFS In (BDT)',
            'Bank In (BDT)',
            'Total Money In (BDT)',
            'Daily Expenses (BDT)',
            'Net Cash Position (BDT)',
            'Notes',
        ];
    }

    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $fh = fopen($path, 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM — Excel opens without import wizard
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            $clean = array_filter($row, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);
            fputcsv($fh, array_values($clean));
        }
        fclose($fh);
    }

    // ─── Date range resolver ─────────────────────────────────────────────────

    private function resolveDateRange(): array
    {
        if ($single = $this->option('date')) {
            $d = Carbon::parse($single)->toDateString();
            return [$d, $d];
        }

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->toDateString()
            : Carbon::yesterday()->toDateString();

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->toDateString()
            : ($this->option('from') ? Carbon::today()->toDateString() : $from);

        return [$from, $to];
    }

    private function fmt(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
