<?php

namespace App\Http\Controllers;

use App\Models\EmployeeAttendance;
use App\Models\EmployeeOvertime;
use App\Models\EmployeeRewardFine;
use App\Models\EmployeeDailySale;
use App\Models\EmployeeSalesTarget;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EmployeePanelController extends Controller
{
    private const TIMEZONE = 'Asia/Dhaka';

    public function getMyAttendance(Request $request)
    {
        $me = $request->user();
        $month = $request->get('month', now(self::TIMEZONE)->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month, self::TIMEZONE)->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m', $month, self::TIMEZONE)->endOfMonth()->toDateString();

        $data = EmployeeAttendance::query()
            ->where('employee_id', $me->id)
            ->whereBetween('attendance_date', [$start, $end])
            ->orderBy('attendance_date')
            ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function getMyOvertime(Request $request)
    {
        $me = $request->user();
        $month = $request->get('month', now(self::TIMEZONE)->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month, self::TIMEZONE)->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m', $month, self::TIMEZONE)->endOfMonth()->toDateString();

        $data = EmployeeOvertime::query()
            ->where('employee_id', $me->id)
            ->whereBetween('overtime_date', [$start, $end])
            ->orderBy('overtime_date')
            ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function getMyRewardsFines(Request $request)
    {
        $me = $request->user();
        $month = $request->get('month', now(self::TIMEZONE)->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month, self::TIMEZONE)->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m', $month, self::TIMEZONE)->endOfMonth()->toDateString();

        $data = EmployeeRewardFine::query()
            ->where('employee_id', $me->id)
            ->whereBetween('entry_date', [$start, $end])
            ->orderBy('entry_date')
            ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function getMyPerformance(Request $request)
    {
        $me = $request->user();
        $monthStr = $request->get('month', now(self::TIMEZONE)->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $monthStr, self::TIMEZONE)->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m', $monthStr, self::TIMEZONE)->endOfMonth()->toDateString();

        $totalSales = EmployeeDailySale::query()
            ->where('employee_id', $me->id)
            ->whereBetween('sales_date', [$start, $end])
            ->sum('total_sales_amount');

        $target = EmployeeSalesTarget::query()
            ->where('employee_id', $me->id)
            ->where('target_month', $start)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $monthStr,
                'achieved' => (float)$totalSales,
                'target' => $target ? $target->target_amount : 0,
                'percent' => ($target && $target->target_amount > 0) ? round($totalSales / $target->target_amount * 100, 2) : 0,
            ]
        ]);
    }
}
