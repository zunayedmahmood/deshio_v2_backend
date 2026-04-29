<?php

namespace App\Http\Controllers;

use App\Models\BranchCostEntry;
use App\Models\AdminEntry;
use App\Models\OwnerEntry;
use App\Models\Store;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * CashSheetController
 *
 * GET  /api/cash-sheet               → full monthly sheet
 * GET  /api/cash-sheet/entries       → raw entries for a date (detail panel)
 * POST /api/cash-sheet/branch-cost   → branch manager adds a cost entry
 * DELETE /api/cash-sheet/branch-cost/{id}
 * POST /api/cash-sheet/admin         → admin: salary_setaside | cash_to_bank | sslzc | pathao
 * DELETE /api/cash-sheet/admin/{id}
 * POST /api/cash-sheet/owner         → owner: cash_invest | bank_invest | cash_cost | bank_cost
 * DELETE /api/cash-sheet/owner/{id}
 *
 * ── Displayed cash / bank per branch ────────────────────────────────────────
 *   raw_cash      = order_payments cash methods (counter orders)
 *   raw_bank      = order_payments bank/card/mfs (counter orders)
 *   salary        = SUM admin_entries salary_setaside for this store+date
 *   cash_to_bank  = SUM admin_entries cash_to_bank for this store+date
 *   displayed_cash = raw_cash − salary − cash_to_bank
 *   displayed_bank = raw_bank + cash_to_bank
 *
 * ── Grand totals ─────────────────────────────────────────────────────────────
 *   cash       = SUM displayed_cash (branches only)
 *   bank       = SUM displayed_bank (branches) + online advance
 *   final_bank = bank + sslzc_received + pathao_received
 *
 * ── Owner ────────────────────────────────────────────────────────────────────
 *   total_cash      = cash + cash_invest
 *   total_bank      = final_bank + bank_invest
 *   cash_after_cost = total_cash − cash_cost
 *   bank_after_cost = total_bank − bank_cost
 */
class CashSheetController extends Controller
{
    private const CASH_TYPES = ['cash'];

    public function index(Request $request)
    {
        $request->validate(['month' => 'nullable|date_format:Y-m']);

        $month    = $request->input('month', now()->format('Y-m'));
        $dateFrom = Carbon::parse($month . '-01')->startOfMonth()->toDateString();
        $dateTo   = Carbon::parse($month . '-01')->endOfMonth()->toDateString();

        $stores = Store::where('is_active', true)
            ->orderBy('is_warehouse')
            ->orderBy('id')
            ->get(['id', 'name', 'is_warehouse']);

        $storeIds = $stores->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        $dates    = collect(CarbonPeriod::create($dateFrom, $dateTo))
            ->map(fn ($d) => $d->toDateString());

        $rawSales      = $this->loadBranchSales($storeIds, $dateFrom, $dateTo);
        $rawPayments   = $this->loadBranchPayments($storeIds, $dateFrom, $dateTo);
        $branchReturns = $this->loadBranchReturns($storeIds, $dateFrom, $dateTo);
        $branchCosts   = $this->loadBranchCosts($storeIds, $dateFrom, $dateTo);
        $adminData     = $this->loadAdminEntries($dateFrom, $dateTo);
        $onlineData    = $this->loadOnlineData($dateFrom, $dateTo);
        $ownerData     = $this->loadOwnerEntries($dateFrom, $dateTo);

        $rows = [];

        foreach ($dates as $date) {
            $branches  = [];
            $totalCash = 0;
            $totalBank = 0;
            $totalSale = 0;

            foreach ($stores as $store) {
                $sid = (int) $store->id;
                $storeKey = (string) $sid;

                $raw_cash     = (float) ($rawPayments[$storeKey][$date]['cash'] ?? 0);
                $raw_bank     = (float) ($rawPayments[$storeKey][$date]['bank'] ?? 0);
                $sale         = (float) ($rawSales[$storeKey][$date] ?? 0);
                $ex_on        = (float) ($branchReturns[$storeKey][$date] ?? 0);
                $daily_cost   = (float) ($branchCosts[$storeKey][$date] ?? 0);
                $salary       = (float) ($adminData[$storeKey][$date]['salary_setaside'] ?? 0);
                $cash_to_bank = (float) ($adminData[$storeKey][$date]['cash_to_bank'] ?? 0);

                $disp_cash = max(0, $raw_cash - $salary - $cash_to_bank);
                $disp_bank = $raw_bank + $cash_to_bank;

                $branches[] = [
                    'store_id'     => $sid,
                    'store_name'   => $store->name,
                    'is_warehouse' => (bool) $store->is_warehouse,
                    'daily_sale'   => round($sale, 2),
                    'raw_cash'     => round($raw_cash, 2),
                    'cash'         => round($disp_cash, 2),
                    'bank'         => round($disp_bank, 2),
                    'ex_on'        => round($ex_on, 2),
                    'salary'       => round($salary, 2),
                    'cash_to_bank' => round($cash_to_bank, 2),
                    'daily_cost'   => round($daily_cost, 2),
                ];

                $totalSale += $sale;
                $totalCash += $disp_cash;
                $totalBank += $disp_bank;
            }

            $online     = $onlineData[$date] ?? [];
            $ol_sales   = (float) ($online['daily_sales'] ?? 0);
            $ol_advance = (float) ($online['advance'] ?? 0);
            $ol_payment = (float) ($online['online_payment'] ?? 0);
            $ol_cod     = (float) ($online['cod'] ?? 0);

            $totalBank += $ol_advance; // online advance → bank

            $sslzc_recv  = (float) ($adminData['_global'][$date]['sslzc'] ?? 0);
            $pathao_recv = (float) ($adminData['_global'][$date]['pathao'] ?? 0);

            $owner       = $ownerData[$date] ?? [];
            $cash_invest = (float) ($owner['cash_invest'] ?? 0);
            $bank_invest = (float) ($owner['bank_invest'] ?? 0);
            $cash_cost   = (float) ($owner['cash_cost'] ?? 0);
            $bank_cost   = (float) ($owner['bank_cost'] ?? 0);

            $final_bank      = $totalBank + $sslzc_recv + $pathao_recv;
            $total_cash      = $totalCash + $cash_invest;
            $total_bank      = $final_bank + $bank_invest;
            $cash_after_cost = $total_cash - $cash_cost;
            $bank_after_cost = $total_bank - $bank_cost;

            $rows[] = [
                'date'     => $date,
                'branches' => $branches,
                'online'   => [
                    'daily_sales'    => round($ol_sales, 2),
                    'advance'        => round($ol_advance, 2),
                    'online_payment' => round($ol_payment, 2),
                    'cod'            => round($ol_cod, 2),
                ],
                'disbursements' => [
                    'sslzc_received'  => round($sslzc_recv, 2),
                    'pathao_received' => round($pathao_recv, 2),
                ],
                'totals' => [
                    'total_sale' => round($totalSale + $ol_sales, 2),
                    'cash'       => round($totalCash, 2),
                    'bank'       => round($totalBank, 2),
                    'final_bank' => round($final_bank, 2),
                ],
                'owner' => [
                    'cash_invest'     => round($cash_invest, 2),
                    'bank_invest'     => round($bank_invest, 2),
                    'total_cash'      => round($total_cash, 2),
                    'total_bank'      => round($total_bank, 2),
                    'cash_cost'       => round($cash_cost, 2),
                    'bank_cost'       => round($bank_cost, 2),
                    'cash_after_cost' => round($cash_after_cost, 2),
                    'bank_after_cost' => round($bank_after_cost, 2),
                ],
            ];
        }

        return response()->json([
            'success' => true,
            'month'   => $month,
            'stores'  => $stores->map(fn ($s) => [
                'id'           => (int) $s->id,
                'name'         => $s->name,
                'is_warehouse' => (bool) $s->is_warehouse,
            ]),
            'data'    => $rows,
            'summary' => $this->buildSummary($rows, $stores),
        ]);
    }

    public function entries(Request $request)
    {
        $request->validate(['date' => 'required|date']);
        $date = Carbon::parse($request->date)->toDateString();

        return response()->json([
            'success'       => true,
            'date'          => $date,
            'branch_costs'  => BranchCostEntry::with(['store:id,name,is_warehouse', 'createdBy:id,name'])
                ->whereDate('entry_date', $date)
                ->orderByDesc('created_at')
                ->get(),
            'admin_entries' => AdminEntry::with(['store:id,name,is_warehouse', 'createdBy:id,name'])
                ->whereDate('entry_date', $date)
                ->orderByDesc('created_at')
                ->get(),
            'owner_entries' => OwnerEntry::with(['createdBy:id,name'])
                ->whereDate('entry_date', $date)
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function storeBranchCost(Request $request)
    {
        $v = $request->validate([
            'entry_date' => 'required|date',
            'store_id'   => 'required|integer|exists:stores,id',
            'amount'     => 'required|numeric|min:0.01',
            'details'    => 'nullable|string|max:500',
        ]);

        $entry = BranchCostEntry::create([
            ...$v,
            'created_by' => Auth::guard('api')->id(),
        ]);

        return response()->json([
            'success' => true,
            'entry'   => $entry->load(['store:id,name,is_warehouse', 'createdBy:id,name']),
        ], 201);
    }

    public function destroyBranchCost(int $id)
    {
        BranchCostEntry::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    public function storeAdmin(Request $request)
    {
        $v = $request->validate([
            'entry_date' => 'required|date',
            'type'       => 'required|in:salary_setaside,cash_to_bank,sslzc,pathao',
            'store_id'   => 'nullable|integer|exists:stores,id',
            'amount'     => 'required|numeric|min:0.01',
            'details'    => 'nullable|string|max:500',
        ]);

        if (in_array($v['type'], ['salary_setaside', 'cash_to_bank'], true) && empty($v['store_id'])) {
            return response()->json(['message' => 'store_id required for this type.'], 422);
        }

        $entry = AdminEntry::create([
            ...$v,
            'store_id'   => in_array($v['type'], ['sslzc', 'pathao'], true) ? null : $v['store_id'],
            'created_by' => Auth::guard('api')->id(),
        ]);

        return response()->json([
            'success' => true,
            'entry'   => $entry->load(['store:id,name,is_warehouse', 'createdBy:id,name']),
        ], 201);
    }

    public function destroyAdmin(int $id)
    {
        AdminEntry::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    public function storeOwner(Request $request)
    {
        $v = $request->validate([
            'entry_date' => 'required|date',
            'type'       => 'required|in:cash_invest,bank_invest,cash_cost,bank_cost',
            'amount'     => 'required|numeric|min:0.01',
            'details'    => 'nullable|string|max:500',
        ]);

        $entry = OwnerEntry::create([
            ...$v,
            'created_by' => Auth::guard('api')->id(),
        ]);

        return response()->json([
            'success' => true,
            'entry'   => $entry->load(['createdBy:id,name']),
        ], 201);
    }

    public function destroyOwner(int $id)
    {
        OwnerEntry::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    private function loadBranchSales(array $ids, string $from, string $to): array
    {
        $out = [];

        $rows = DB::table('orders as o')
            ->join('order_payments as op', 'op.order_id', '=', 'o.id')
            ->select(
                'op.store_id',
                DB::raw('DATE(COALESCE(op.completed_at, o.created_at)) as day'),
                'o.id as order_id',
                'o.total_amount'
            )
            ->whereIn('op.store_id', $ids)
            ->where('o.order_type', 'counter')
            ->whereNotIn('o.status', ['cancelled', 'refunded'])
            ->whereDate(DB::raw('COALESCE(op.completed_at, o.created_at)'), '>=', $from)
            ->whereDate(DB::raw('COALESCE(op.completed_at, o.created_at)'), '<=', $to)
            ->groupBy('op.store_id', 'day', 'o.id', 'o.total_amount')
            ->get();

        $grouped = [];
        foreach ($rows as $r) {
            $storeKey = (string) $r->store_id;
            $day = $r->day;
            $grouped[$storeKey][$day] = ($grouped[$storeKey][$day] ?? 0) + (float) $r->total_amount;
        }

        foreach ($grouped as $storeId => $days) {
            foreach ($days as $day => $total) {
                $out[$storeId][$day] = round($total, 2);
            }
        }

        return $out;
    }

    private function loadBranchPayments(array $ids, string $from, string $to): array
    {
        $out = [];

        DB::table('order_payments as op')
            ->join('payment_methods as pm', 'pm.id', '=', 'op.payment_method_id')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->select(
                'op.store_id',
                DB::raw('DATE(op.completed_at) as day'),
                'pm.type as mt',
                DB::raw('SUM(op.amount) as total')
            )
            ->whereIn('op.store_id', $ids)
            ->where('o.order_type', 'counter')
            ->whereDate('op.completed_at', '>=', $from)
            ->whereDate('op.completed_at', '<=', $to)
            ->where('op.status', 'completed')
            ->whereNotIn('op.payment_type', ['exchange_balance', 'store_credit', 'balance_carryover'])
            ->whereNotNull('op.completed_at')
            ->groupBy('op.store_id', 'day', 'pm.type')
            ->get()
            ->each(function ($r) use (&$out) {
                $bucket = in_array($r->mt, self::CASH_TYPES, true) ? 'cash' : 'bank';
                $storeKey = (string) $r->store_id;
                $out[$storeKey][$r->day][$bucket] = ($out[$storeKey][$r->day][$bucket] ?? 0) + (float) $r->total;
            });

        return $out;
    }

    private function loadBranchReturns(array $ids, string $from, string $to): array
    {
        $out = [];

        DB::table('product_returns as pr')
            ->join('orders as o', 'o.id', '=', 'pr.order_id')
            ->select(
                'o.store_id',
                DB::raw('DATE(pr.created_at) as day'),
                DB::raw('SUM(pr.total_return_value) as total')
            )
            ->whereIn('o.store_id', $ids)
            ->where('o.order_type', 'counter')
            ->whereIn('pr.status', ['approved', 'completed'])
            ->whereDate('pr.created_at', '>=', $from)
            ->whereDate('pr.created_at', '<=', $to)
            ->groupBy('o.store_id', 'day')
            ->get()
            ->each(function ($r) use (&$out) {
                $storeKey = (string) $r->store_id;
                $out[$storeKey][$r->day] = (float) $r->total;
            });

        return $out;
    }

    private function loadBranchCosts(array $ids, string $from, string $to): array
    {
        $out = [];

        BranchCostEntry::select('store_id', DB::raw('DATE(entry_date) as day'), DB::raw('SUM(amount) as total'))
            ->whereIn('store_id', $ids)
            ->whereBetween('entry_date', [$from, $to])
            ->groupBy('store_id', 'day')
            ->get()
            ->each(function ($r) use (&$out) {
                $storeKey = (string) $r->store_id;
                $out[$storeKey][$r->day] = (float) $r->total;
            });

        return $out;
    }

    /** Returns [store_id|'_global'][date][type] = sum */
    private function loadAdminEntries(string $from, string $to): array
    {
        $out = [];

        AdminEntry::select('store_id', 'type', DB::raw('DATE(entry_date) as day'), DB::raw('SUM(amount) as total'))
            ->whereBetween('entry_date', [$from, $to])
            ->groupBy('store_id', 'type', 'day')
            ->get()
            ->each(function ($r) use (&$out) {
                $key = in_array($r->type, ['sslzc', 'pathao'], true) ? '_global' : (string) $r->store_id;
                $out[$key][$r->day][$r->type] = ($out[$key][$r->day][$r->type] ?? 0) + (float) $r->total;
            });

        return $out;
    }

    private function loadOnlineData(string $from, string $to): array
    {
        $out = [];

        DB::table('orders')
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(total_amount) as ts'),
                DB::raw('SUM(paid_amount) as adv'),
                DB::raw('SUM(outstanding_amount) as cod')
            )
            ->where('order_type', 'social_commerce')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->groupBy('day')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[$r->day]['daily_sales'] = ($out[$r->day]['daily_sales'] ?? 0) + (float) $r->ts;
                $out[$r->day]['advance']     = ($out[$r->day]['advance'] ?? 0) + (float) $r->adv;
                $out[$r->day]['cod']         = ($out[$r->day]['cod'] ?? 0) + (float) $r->cod;
            });

        DB::table('orders')
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(total_amount) as ts'),
                DB::raw('SUM(paid_amount) as op')
            )
            ->where('order_type', 'ecommerce')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->groupBy('day')
            ->get()
            ->each(function ($r) use (&$out) {
                $out[$r->day]['daily_sales']    = ($out[$r->day]['daily_sales'] ?? 0) + (float) $r->ts;
                $out[$r->day]['online_payment'] = ($out[$r->day]['online_payment'] ?? 0) + (float) $r->op;
            });

        return $out;
    }

    private function loadOwnerEntries(string $from, string $to): array
    {
        $out = [];

        DB::table('owner_entries')
            ->selectRaw('DATE(entry_date) as day, type, SUM(amount) as total')
            ->whereDate('entry_date', '>=', $from)
            ->whereDate('entry_date', '<=', $to)
            ->groupBy('day', 'type')
            ->get()
            ->each(function ($r) use (&$out) {
                $day = Carbon::parse($r->day)->toDateString();
                $out[$day][$r->type] = ($out[$day][$r->type] ?? 0) + (float) $r->total;
            });

        return $out;
    }

    private function buildSummary(array $rows, $stores): array
    {
        $summary = [
            'branches'      => [],
            'online'        => ['daily_sales' => 0, 'advance' => 0, 'online_payment' => 0, 'cod' => 0],
            'disbursements' => ['sslzc_received' => 0, 'pathao_received' => 0],
            'totals'        => ['total_sale' => 0, 'cash' => 0, 'bank' => 0, 'final_bank' => 0],
            'owner'         => [
                'cash_invest' => 0,
                'bank_invest' => 0,
                'total_cash' => 0,
                'total_bank' => 0,
                'cash_cost' => 0,
                'bank_cost' => 0,
                'cash_after_cost' => 0,
                'bank_after_cost' => 0,
            ],
        ];

        foreach ($stores as $st) {
            $summary['branches'][$st->id] = [
                'store_id'     => (int) $st->id,
                'store_name'   => $st->name,
                'is_warehouse' => (bool) $st->is_warehouse,
                'daily_sale'   => 0,
                'cash'         => 0,
                'bank'         => 0,
                'ex_on'        => 0,
                'salary'       => 0,
                'cash_to_bank' => 0,
                'daily_cost'   => 0,
            ];
        }

        foreach ($rows as $row) {
            foreach ($row['branches'] as $b) {
                foreach (['daily_sale', 'cash', 'bank', 'ex_on', 'salary', 'cash_to_bank', 'daily_cost'] as $field) {
                    $summary['branches'][$b['store_id']][$field] += (float) $b[$field];
                }
            }

            foreach (['daily_sales', 'advance', 'online_payment', 'cod'] as $field) {
                $summary['online'][$field] += (float) $row['online'][$field];
            }

            $summary['disbursements']['sslzc_received'] += (float) $row['disbursements']['sslzc_received'];
            $summary['disbursements']['pathao_received'] += (float) $row['disbursements']['pathao_received'];

            foreach (['total_sale', 'cash', 'bank', 'final_bank'] as $field) {
                $summary['totals'][$field] += (float) $row['totals'][$field];
            }

            foreach (['cash_invest', 'bank_invest', 'total_cash', 'total_bank', 'cash_cost', 'bank_cost', 'cash_after_cost', 'bank_after_cost'] as $field) {
                $summary['owner'][$field] += (float) $row['owner'][$field];
            }
        }

        array_walk_recursive($summary, function (&$value) {
            if (is_float($value) || is_int($value)) {
                $value = round((float) $value, 2);
            }
        });

        $summary['branches'] = array_values($summary['branches']);

        return $summary;
    }
}
