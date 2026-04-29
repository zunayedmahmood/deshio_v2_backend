<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeOvertime;
use App\Models\EmployeeRewardFine;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    const TIMEZONE = 'Asia/Dhaka';

    public function getMonthlySalarySheet(Request $request)
    {
        $actor = $this->actor($request);

        $request->validate([
            'month' => 'required|date_format:Y-m',
            'store_id' => 'required|exists:stores,id',
        ]);

        $storeId = (int) $request->query('store_id');
        $this->assertStoreAccess($actor, $storeId);

        $monthStr = $request->query('month');
        $monthStart = Carbon::createFromFormat('Y-m', $monthStr, self::TIMEZONE)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $employees = Employee::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $sheets = [];

        foreach ($employees as $employee) {
            $basicSalary = (float) $employee->salary;

            $fines = (float) EmployeeRewardFine::query()
                ->where('employee_id', $employee->id)
                ->where('store_id', $storeId)
                ->whereBetween('entry_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where('entry_type', 'fine')
                ->where('is_applied', false)
                ->sum('amount');

            $rewards = (float) EmployeeRewardFine::query()
                ->where('employee_id', $employee->id)
                ->where('store_id', $storeId)
                ->whereBetween('entry_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where('entry_type', 'reward')
                ->where('is_applied', false)
                ->sum('amount');

            $lateFees = (float) EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('attendance_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where('is_applied', false)
                ->sum('late_fee');

            $overtimePay = (float) EmployeeOvertime::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('overtime_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where('is_applied', false)
                ->sum('overtime_pay');

            $paidExpense = $this->findPayrollExpense($employee, $storeId, $monthStr);
            $expensePayment = null;
            $paymentTransactions = collect();
            if ($paidExpense) {
                $expensePayment = ExpensePayment::query()
                    ->where('expense_id', $paidExpense->id)
                    ->where('status', 'completed')
                    ->latest('id')
                    ->first();

                if ($expensePayment) {
                    $paymentTransactions = Transaction::query()
                        ->byReference(ExpensePayment::class, $expensePayment->id)
                        ->completed()
                        ->orderBy('id')
                        ->get();
                }
            }

            $totalRewardsAndOvertime = $rewards + $overtimePay;
            $totalFinesAndLate = $fines + $lateFees;
            $netPayable = $basicSalary + $totalRewardsAndOvertime - $totalFinesAndLate;

            $sheets[] = [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'employee_code' => $employee->employee_code,
                ],
                'basic_salary' => round($basicSalary, 2, PHP_ROUND_HALF_UP),
                'rewards' => round($rewards, 2, PHP_ROUND_HALF_UP),
                'fines' => round($fines, 2, PHP_ROUND_HALF_UP),
                'late_fees' => round($lateFees, 2, PHP_ROUND_HALF_UP),
                'overtime_pay' => round($overtimePay, 2, PHP_ROUND_HALF_UP),
                'net_payable' => round($netPayable, 2, PHP_ROUND_HALF_UP),
                'is_paid' => (bool) $paidExpense,
                'paid_info' => $paidExpense ? [
                    'expense_id' => $paidExpense->id,
                    'expense_number' => $paidExpense->expense_number,
                    'payment_id' => $expensePayment?->id,
                    'payment_number' => $expensePayment?->payment_number,
                    'paid_at' => optional($expensePayment?->completed_at)->toISOString(),
                    'accounting_posted' => $paymentTransactions->isNotEmpty(),
                    'transaction_ids' => $paymentTransactions->pluck('id')->values(),
                    'transaction_numbers' => $paymentTransactions->pluck('transaction_number')->filter()->values(),
                ] : null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $monthStr,
                'store_id' => $storeId,
                'sheet' => $sheets,
            ]
        ]);
    }

    public function payMonthlySalary(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'store_id' => 'required|exists:stores,id',
            'month' => 'required|date_format:Y-m',
            'payment_method_id' => 'nullable|exists:payment_methods,id',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        if ((int) $employee->store_id !== $storeId) {
            return response()->json(['success' => false, 'message' => 'Employee does not belong to this store'], 422);
        }

        $monthStr = $validated['month'];
        $monthStart = Carbon::createFromFormat('Y-m', $monthStr, self::TIMEZONE)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $alreadyPaidExpense = $this->findPayrollExpense($employee, $storeId, $monthStr);
        if ($alreadyPaidExpense) {
            return response()->json(['success' => false, 'message' => 'Salary already paid for this month'], 422);
        }

        $result = DB::transaction(function () use ($employee, $storeId, $monthStart, $monthEnd, $monthStr, $actor, $validated) {
            $basicSalary = (float) $employee->salary;

            $fines = EmployeeRewardFine::query()
                ->where('employee_id', $employee->id)
                ->where('store_id', $storeId)
                ->whereBetween('entry_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where('entry_type', 'fine')
                ->where('is_applied', false);
            $totalFines = (float) $fines->sum('amount');

            $rewards = EmployeeRewardFine::query()
                ->where('employee_id', $employee->id)
                ->where('store_id', $storeId)
                ->whereBetween('entry_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where('entry_type', 'reward')
                ->where('is_applied', false);
            $totalRewards = (float) $rewards->sum('amount');

            $lateFees = EmployeeAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('attendance_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where('is_applied', false);
            $totalLateFees = (float) $lateFees->sum('late_fee');

            $overtime = EmployeeOvertime::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('overtime_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->where('is_applied', false);
            $totalOvertimePay = (float) $overtime->sum('overtime_pay');

            $netPayable = round($basicSalary + $totalRewards + $totalOvertimePay - $totalFines - $totalLateFees, 2, PHP_ROUND_HALF_UP);

            $paymentMethod = $this->resolvePaymentMethod($validated['payment_method_id'] ?? null);

            $expense = Expense::createSalaryPayment($employee, $netPayable, [
                'description' => "Salary payment to: {$employee->name} for {$monthStr}",
                'store_id' => $storeId,
                'created_by' => $actor->id,
                'status' => 'completed',
                'payment_status' => 'paid',
                'paid_amount' => $netPayable,
                'outstanding_amount' => 0,
                'expense_date' => now(self::TIMEZONE)->toDateString(),
                'completed_at' => now(self::TIMEZONE),
                'metadata' => [
                    'payroll_month' => $monthStr,
                    'salary_breakdown' => [
                        'basic_salary' => $basicSalary,
                        'rewards' => $totalRewards,
                        'overtime_pay' => $totalOvertimePay,
                        'fines' => $totalFines,
                        'late_fees' => $totalLateFees,
                        'net_payable' => $netPayable,
                    ],
                ],
            ]);

            $payment = ExpensePayment::create([
                'expense_id' => $expense->id,
                'payment_method_id' => $paymentMethod->id,
                'store_id' => $storeId,
                'processed_by' => $actor->id,
                'amount' => $netPayable,
                'fee_amount' => 0,
                'net_amount' => $netPayable,
                'status' => 'completed',
                'processed_at' => now(self::TIMEZONE),
                'completed_at' => now(self::TIMEZONE),
                'metadata' => [
                    'payroll_month' => $monthStr,
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'expense_type' => 'salary_payment',
                ],
                'notes' => "Salary paid for {$monthStr}",
            ]);

            $fines->update([
                'is_applied' => true,
                'applied_month' => $monthStart->toDateString(),
                'applied_at' => now(self::TIMEZONE),
                'applied_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $rewards->update([
                'is_applied' => true,
                'applied_month' => $monthStart->toDateString(),
                'applied_at' => now(self::TIMEZONE),
                'applied_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $lateFees->update([
                'is_applied' => true,
                'applied_at' => now(self::TIMEZONE),
            ]);

            $overtime->update([
                'is_applied' => true,
                'applied_at' => now(self::TIMEZONE),
            ]);

            $transactions = Transaction::query()
                ->byReference(ExpensePayment::class, $payment->id)
                ->completed()
                ->orderBy('id')
                ->get();

            return [
                'net_payable' => $netPayable,
                'expense_id' => $expense->id,
                'expense_number' => $expense->expense_number,
                'expense_payment_id' => $payment->id,
                'expense_payment_number' => $payment->payment_number,
                'accounting_posted' => $transactions->isNotEmpty(),
                'transaction_ids' => $transactions->pluck('id')->values(),
                'transaction_numbers' => $transactions->pluck('transaction_number')->filter()->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Salary marked as paid and posted to accounting.',
            'data' => $result,
        ]);
    }

    private function resolvePaymentMethod(?int $paymentMethodId): PaymentMethod
    {
        if ($paymentMethodId) {
            $method = PaymentMethod::query()->find($paymentMethodId);
            if ($method) {
                return $method;
            }
        }

        $paymentMethod = PaymentMethod::query()->where('code', 'cash')->first();
        if (!$paymentMethod) {
            $paymentMethod = PaymentMethod::query()->where('is_active', true)->first();
        }

        if (!$paymentMethod) {
            throw new \RuntimeException('No active payment method found system-wide.');
        }

        return $paymentMethod;
    }

    private function findPayrollExpense(Employee $employee, int $storeId, string $monthStr): ?Expense
    {
        return Expense::query()
            ->where('expense_type', 'salary_payment')
            ->where('employee_id', $employee->id)
            ->where('store_id', $storeId)
            ->where(function ($q) use ($monthStr, $employee) {
                $q->where('metadata->payroll_month', $monthStr)
                    ->orWhere('description', 'like', "%Salary payment to: {$employee->name} for {$monthStr}%");
            })
            ->latest('id')
            ->first();
    }

    private function actor(Request $request): Employee
    {
        $user = $request->user();
        if ($user && !$user->relationLoaded('role')) {
            $user->load('role');
        }
        return $user;
    }

    private function isAdmin(Employee $employee): bool
    {
        $slug = $employee->role?->slug;
        return in_array($slug, ['super-admin', 'super_admin', 'superadmin', 'admin'], true);
    }

    private function isManager(Employee $employee): bool
    {
        $slug = $employee->role?->slug;
        return $slug === 'manager' || $slug === 'branch-manager' || $slug === 'branch_manager';
    }

    private function assertStoreAccess(Employee $actor, int $storeId): void
    {
        if ($this->isAdmin($actor)) {
            return;
        }

        if ($this->isManager($actor) && (int) $actor->store_id !== $storeId) {
            throw new AuthorizationException('Manager can only manage payroll for own store');
        }

        if (!$this->isManager($actor)) {
            throw new AuthorizationException('Unauthorized access to payroll');
        }
    }
}
