<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeSalesTarget;
use App\Models\EmployeeSalesTargetHistory;
use App\Models\EmployeeDailySale;
use App\Services\SalesTargetAggregationService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesTargetController extends Controller
{
    private const TIMEZONE = 'Asia/Dhaka';
    protected $aggregationService;

    public function __construct(SalesTargetAggregationService $aggregationService)
    {
        $this->aggregationService = $aggregationService;
    }

    public function index(Request $request)
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'month' => 'required|date_format:Y-m',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $month = Carbon::createFromFormat('Y-m', $validated['month'], self::TIMEZONE)->startOfMonth()->toDateString();

        $targets = EmployeeSalesTarget::query()
            ->where('store_id', $storeId)
            ->where('target_month', $month)
            ->with(['employee:id,name,employee_code'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $targets,
        ]);
    }

    public function setTarget(Request $request)
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'store_id' => 'required|exists:stores,id',
            'target_month' => 'required|date_format:Y-m',
            'target_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        $employee = Employee::findOrFail((int) $validated['employee_id']);
        if ((int) $employee->store_id !== $storeId) {
            return response()->json(['success' => false, 'message' => 'Employee mismatch'], 422);
        }

        $month = Carbon::createFromFormat('Y-m', $validated['target_month'], self::TIMEZONE)->startOfMonth()->toDateString();
        $targetAmount = round((float) $validated['target_amount'], 2);

        $target = DB::transaction(function () use ($employee, $storeId, $month, $targetAmount, $actor, $validated) {
            $existing = EmployeeSalesTarget::query()
                ->where('employee_id', $employee->id)
                ->where('target_month', $month)
                ->first();

            $oldAmount = $existing ? $existing->target_amount : 0;
            
            $target = EmployeeSalesTarget::updateOrCreate(
                ['employee_id' => $employee->id, 'target_month' => $month],
                [
                    'store_id' => $storeId,
                    'target_amount' => $targetAmount,
                    'notes' => $validated['notes'] ?? null,
                    'set_by' => $actor->id,
                ]
            );

            EmployeeSalesTargetHistory::create([
                'sales_target_id' => $target->id,
                'old_target_amount' => $oldAmount,
                'new_target_amount' => $targetAmount,
                'changed_by' => $actor->id,
                'reason' => $existing ? 'Manual Update' : 'Initial Set',
                'action' => $existing ? 'updated' : 'created',
                'changed_at' => now(),
            ]);

            return $target;
        });

        return response()->json([
            'success' => true,
            'message' => 'Sales target set successfully',
            'data' => $target,
        ]);
    }

    public function copyLastMonthTargets(Request $request)
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'target_month' => 'required|date_format:Y-m',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        $currentMonth = Carbon::createFromFormat('Y-m', $validated['target_month'], self::TIMEZONE)->startOfMonth();
        $lastMonth = $currentMonth->copy()->subMonth()->toDateString();
        $currentMonthStr = $currentMonth->toDateString();

        $lastMonthTargets = EmployeeSalesTarget::query()
            ->where('store_id', $storeId)
            ->where('target_month', $lastMonth)
            ->get();

        if ($lastMonthTargets->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No targets found for the previous month to copy.',
            ], 404);
        }

        $copiedCount = 0;

        DB::transaction(function () use ($lastMonthTargets, $currentMonthStr, $storeId, $actor, &$copiedCount) {
            foreach ($lastMonthTargets as $oldTarget) {
                $employee = Employee::find($oldTarget->employee_id);
                if (!$employee || !$employee->is_active || (int) $employee->store_id !== $storeId) {
                    continue;
                }

                $existing = EmployeeSalesTarget::query()
                    ->where('employee_id', $employee->id)
                    ->where('target_month', $currentMonthStr)
                    ->exists();

                if (!$existing) {
                    $newTarget = EmployeeSalesTarget::create([
                        'employee_id' => $employee->id,
                        'store_id' => $storeId,
                        'target_month' => $currentMonthStr,
                        'target_amount' => $oldTarget->target_amount,
                        'notes' => 'Copied from previous month',
                        'set_by' => $actor->id,
                    ]);

                    EmployeeSalesTargetHistory::create([
                        'sales_target_id' => $newTarget->id,
                        'old_target_amount' => 0,
                        'new_target_amount' => $newTarget->target_amount,
                        'changed_by' => $actor->id,
                        'reason' => 'Copied from previous month',
                        'action' => 'created',
                        'changed_at' => now(),
                    ]);

                    $copiedCount++;
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => "Successfully copied $copiedCount targets from previous month.",
            'copied_count' => $copiedCount,
        ]);
    }

    public function getDailyPerformance(Request $request)
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $query = EmployeeDailySale::query()
            ->with('employee:id,name,employee_code')
            ->where('store_id', $storeId)
            ->whereBetween('sales_date', [$validated['from'], $validated['to']]);

        if (!empty($validated['employee_id'])) {
            $query->where('employee_id', $validated['employee_id']);
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('sales_date')->get(),
        ]);
    }

    public function getTargetReport(Request $request)
    {
        $actor = $this->actor($request);
        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'month' => 'required|date_format:Y-m',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $monthStr = $validated['month'];
        $monthStart = Carbon::createFromFormat('Y-m', $monthStr, self::TIMEZONE)->startOfMonth()->toDateString();
        $monthEnd = Carbon::createFromFormat('Y-m', $monthStr, self::TIMEZONE)->endOfMonth()->toDateString();

        $employees = Employee::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $items = [];
        $branchTarget = 0;
        $totalSales = 0;

        foreach ($employees as $emp) {
            $empSales = (float) EmployeeDailySale::query()
                ->where('employee_id', $emp->id)
                ->whereBetween('sales_date', [$monthStart, $monthEnd])
                ->sum('total_sales_amount');
            
            $target = EmployeeSalesTarget::query()
                ->where('employee_id', $emp->id)
                ->where('target_month', $monthStart)
                ->first();

            $empTargetAmount = $target ? (float) $target->target_amount : 0;
            
            $branchTarget += $empTargetAmount;
            $totalSales += $empSales;

            $items[] = [
                'employee' => $emp->only(['id', 'name', 'employee_code']),
                'target_amount' => $empTargetAmount,
                'achieved_amount' => $empSales,
                'achievement_percentage' => ($empTargetAmount > 0)
                    ? round($empSales / $empTargetAmount * 100, 2)
                    : 0,
            ];
        }

        // Sort items by achievement_percentage descending for leaderboard
        usort($items, function($a, $b) {
            return $b['achievement_percentage'] <=> $a['achievement_percentage'];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'branch_target' => $branchTarget,
                'total_sales' => $totalSales,
                'branch_achievement' => ($branchTarget > 0)
                    ? round($totalSales / $branchTarget * 100, 2)
                    : 0,
            ],
        ]);
    }

    public function getTargetHistory(Request $request, int $employeeId)
    {
        $actor = $this->actor($request);
        $employee = Employee::findOrFail($employeeId);
        $this->assertStoreAccess($actor, (int) $employee->store_id, true);

        $history = EmployeeSalesTargetHistory::query()
            ->whereHas('salesTarget', function($q) use ($employeeId) {
                $q->where('employee_id', $employeeId);
            })
            ->with(['changedBy:id,name,employee_code', 'salesTarget'])
            ->orderByDesc('changed_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    private function actor(Request $request): Employee
    {
        return $request->user();
    }

    private function isAdmin(Employee $employee): bool
    {
        $slug = $employee->role?->slug;
        return in_array($slug, ['super-admin', 'super_admin', 'superadmin', 'admin'], true);
    }

    private function isManager(Employee $employee): bool
    {
        return $employee->role?->slug === 'manager' || $employee->role?->slug === 'branch-manager';
    }

    private function assertStoreAccess(Employee $actor, int $storeId, bool $allowAdminAny = true): void
    {
        if ($allowAdminAny && $this->isAdmin($actor)) {
            return;
        }

        if ($this->isManager($actor) && (int) $actor->store_id !== $storeId) {
            throw new AuthorizationException('Manager can only access own store data');
        }
    }
}
