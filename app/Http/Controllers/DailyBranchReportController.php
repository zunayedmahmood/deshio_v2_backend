<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * DailyBranchReportController
 *
 * GET /api/reports/daily-branch        → streams CSV download
 * GET /api/reports/daily-branch-json   → returns JSON for the frontend dashboard
 */
class DailyBranchReportController extends Controller
{
    // Maps payment_methods.type → column key
    private const PM_BUCKET = [
        'cash'           => 'cash_in',
        'card'           => 'card_in',
        'mobile_banking' => 'mfs_in',
        'digital_wallet' => 'mfs_in',
        'bank_transfer'  => 'bank_in',
        'online_banking' => 'bank_in',
        'online_pay'     => 'bank_in',
    ];

    // ── JSON endpoint (used by frontend dashboard) ────────────────────────

    public function json(Request $request)
    {
        $request->validate([
            'date'     => 'nullable|date',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date|after_or_equal:from',
            'store_id' => 'nullable|integer|exists:stores,id',
        ]);

        [$dateFrom, $dateTo] = $this->resolveDateRange($request);
        $storeFilter = $request->filled('store_id') ? (int) $request->store_id : null;

        $stores = DB::table('stores')
            ->when($storeFilter, fn($q) => $q->where('id', $storeFilter))
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name']);

        $storeIds = $stores->pluck('id')->toArray();

        if (empty($storeIds)) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => [
                    'date_from'    => $dateFrom,
                    'date_to'      => $dateTo,
                    'store_id'     => $storeFilter,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);
        }

        $dates       = collect(CarbonPeriod::create($dateFrom, $dateTo))->map(fn($d) => $d->toDateString());
        $salesData   = $this->loadSalesData($storeIds, $dateFrom, $dateTo);
        $paymentData = $this->loadPaymentData($storeIds, $dateFrom, $dateTo);
        $expenseData = $this->loadExpenseData($storeIds, $dateFrom, $dateTo);

        $rows = [];
        foreach ($stores as $store) {
            foreach ($dates as $date) {
                $sid = $store->id;

                $posSales    = $salesData[$sid][$date]['counter']         ?? 0;
                $onlineSales = $salesData[$sid][$date]['ecommerce']       ?? 0;
                $socialSales = $salesData[$sid][$date]['social_commerce'] ?? 0;
                $totalSales  = $posSales + $onlineSales + $socialSales;

                $cashIn      = $paymentData[$sid][$date]['cash_in'] ?? 0;
                $cardIn      = $paymentData[$sid][$date]['card_in'] ?? 0;
                $mfsIn       = $paymentData[$sid][$date]['mfs_in']  ?? 0;
                $bankIn      = $paymentData[$sid][$date]['bank_in'] ?? 0;
                $totalIn     = $cashIn + $cardIn + $mfsIn + $bankIn;

                $expenses    = $expenseData[$sid][$date] ?? 0;
                $netCash     = $totalIn - $expenses;

                $rows[] = [
                    'date'                   => $date,
                    'branch'                 => $store->name,
                    'pos_sales'              => round($posSales,    2),
                    'online_sales'           => round($onlineSales, 2),
                    'social_commerce_sales'  => round($socialSales, 2),
                    'total_sales'            => round($totalSales,  2),
                    'cash_in'                => round($cashIn,      2),
                    'card_in'                => round($cardIn,      2),
                    'mfs_in'                 => round($mfsIn,       2),
                    'bank_in'                => round($bankIn,      2),
                    'total_money_in'         => round($totalIn,     2),
                    'daily_expenses'         => round($expenses,    2),
                    'net_cash_position'      => round($netCash,     2),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $rows,
            'meta'    => [
                'date_from'    => $dateFrom,
                'date_to'      => $dateTo,
                'store_id'     => $storeFilter,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    // ── CSV download endpoint ─────────────────────────────────────────────

    public function download(Request $request): StreamedResponse
    {
        $request->validate([
            'date'     => 'nullable|date',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date|after_or_equal:from',
            'store_id' => 'nullable|integer|exists:stores,id',
            'combined' => 'nullable|boolean',
        ]);

        [$from, $to] = $this->resolveDateRange($request);

        $tmpDir = storage_path('app/reports/tmp_' . uniqid());
        mkdir($tmpDir, 0755, true);

        $args = ['--from' => $from, '--to' => $to, '--out' => $tmpDir];
        if ($request->filled('store_id')) $args['--store']    = (int) $request->store_id;
        if ($request->boolean('combined') || $request->filled('store_id')) $args['--combined'] = true;

        Artisan::call('report:daily-branch', $args);

        $files = glob("{$tmpDir}/*.csv");

        if (empty($files)) {
            $this->cleanupDir($tmpDir);
            abort(404, 'No data found for the requested period.');
        }

        if (count($files) === 1) {
            $filepath = $files[0];
            $filename = basename($filepath);
            return response()->streamDownload(function () use ($filepath, $tmpDir) {
                readfile($filepath);
                $this->cleanupDir($tmpDir);
            }, $filename, [
                'Content-Type'        => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        $zipName = "daily_branch_reports_{$from}_to_{$to}.zip";
        $zipPath = "{$tmpDir}/{$zipName}";
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        return response()->streamDownload(function () use ($zipPath, $tmpDir) {
            readfile($zipPath);
            $this->cleanupDir($tmpDir);
        }, $zipName, ['Content-Type' => 'application/zip']);
    }

    // ── Shared data loaders ───────────────────────────────────────────────

    private function loadSalesData(array $storeIds, string $from, string $to): array
    {
        $rows = DB::table('orders')
            ->select('store_id', DB::raw('DATE(created_at) as day'), 'order_type', DB::raw('SUM(total_amount) as total'))
            ->whereIn('store_id', $storeIds)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->whereIn('order_type', ['counter', 'ecommerce', 'social_commerce'])
            ->groupBy('store_id', 'day', 'order_type')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->store_id][$r->day][$r->order_type] = (float) $r->total;
        }
        return $out;
    }

    private function loadPaymentData(array $storeIds, string $from, string $to): array
    {
        $rows = DB::table('order_payments as op')
            ->join('payment_methods as pm', 'pm.id', '=', 'op.payment_method_id')
            ->select('op.store_id', DB::raw('DATE(op.completed_at) as day'), 'pm.type as method_type', DB::raw('SUM(op.amount) as total'))
            ->whereIn('op.store_id', $storeIds)
            ->whereDate('op.completed_at', '>=', $from)
            ->whereDate('op.completed_at', '<=', $to)
            ->where('op.status', 'completed')
            ->whereNotIn('op.payment_type', ['exchange_balance', 'store_credit', 'balance_carryover'])
            ->whereNotNull('op.completed_at')
            ->groupBy('op.store_id', 'day', 'pm.type')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $bucket = self::PM_BUCKET[$r->method_type] ?? 'bank_in';
            $out[$r->store_id][$r->day][$bucket] = ($out[$r->store_id][$r->day][$bucket] ?? 0) + (float) $r->total;
        }
        return $out;
    }

    private function loadExpenseData(array $storeIds, string $from, string $to): array
{
    $out = [];

    // A) Expense module entries (exclude only draft/rejected/cancelled)
    $rows = DB::table('expenses')
        ->select('store_id', DB::raw('DATE(expense_date) as day'), DB::raw('SUM(total_amount) as total'))
        ->whereIn('store_id', $storeIds)
        ->whereDate('expense_date', '>=', $from)
        ->whereDate('expense_date', '<=', $to)
        ->whereNotIn('status', ['draft', 'rejected', 'cancelled'])
        ->groupBy('store_id', 'day')
        ->get();

    foreach ($rows as $r) {
        $out[$r->store_id][$r->day] = ($out[$r->store_id][$r->day] ?? 0) + (float) $r->total;
    }

    // B) Manual accounting entries that hit EXPENSE accounts (this is what you’re adding)
    $manual = DB::table('transactions as t')
        ->join('accounts as a', 'a.id', '=', 't.account_id')
        ->select('t.store_id', DB::raw('DATE(t.transaction_date) as day'), DB::raw('SUM(t.amount) as total'))
        ->whereIn('t.store_id', $storeIds)
        ->whereDate('t.transaction_date', '>=', $from)
        ->whereDate('t.transaction_date', '<=', $to)
        ->where('t.status', 'completed')
        ->where('t.reference_type', 'manual')
        ->where('a.type', 'expense')
        ->where('t.type', 'debit')     // expense-side entry
        ->groupBy('t.store_id', 'day')
        ->get();

    foreach ($manual as $r) {
        $out[$r->store_id][$r->day] = ($out[$r->store_id][$r->day] ?? 0) + (float) $r->total;
    }

    return $out;
}

    private function resolveDateRange(Request $request): array
    {
        if ($request->filled('date')) {
            $d = Carbon::parse($request->date)->toDateString();
            return [$d, $d];
        }
        $from = $request->filled('from')
            ? Carbon::parse($request->from)->toDateString()
            : Carbon::yesterday()->toDateString();
        $to = $request->filled('to')
            ? Carbon::parse($request->to)->toDateString()
            : ($request->filled('from') ? Carbon::today()->toDateString() : $from);
        return [$from, $to];
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob("{$dir}/*") as $f) { is_file($f) && unlink($f); }
        rmdir($dir);
    }
}
