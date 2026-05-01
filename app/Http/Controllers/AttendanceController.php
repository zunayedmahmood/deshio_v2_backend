<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceHistory;
use App\Models\EmployeeOvertime;
use App\Models\EmployeeOvertimeHistory;
use App\Models\EmployeeRewardFine;
use App\Models\EmployeeRewardFineHistory;
use App\Models\EmployeeSalaryAdjustment;
use App\Models\EmployeeWorkSchedule;
use App\Models\StoreAttendanceHoliday;
use App\Models\StoreAttendancePolicy;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    private const TIMEZONE = 'Asia/Dhaka';

    public function upsertStorePolicy(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'mode' => 'required|in:fixed_day_off,always_on_duty',
            'fixed_days_off' => 'nullable|array',
            'fixed_days_off.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'fixed_start_time' => 'nullable|date_format:H:i',
            'fixed_end_time' => 'nullable|date_format:H:i',
            'effective_from' => 'nullable|date',
            'timezone' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'late_fee_per_minute' => 'nullable|numeric|min:0',
            'overtime_rate_per_hour' => 'nullable|numeric|min:0',
            'grace_period_minutes' => 'nullable|integer|min:0',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        if ($validated['mode'] === 'fixed_day_off' && empty($validated['fixed_days_off'])) {
            return response()->json([
                'success' => false,
                'message' => 'fixed_days_off is required for fixed_day_off mode',
            ], 422);
        }

        if ($validated['mode'] === 'fixed_day_off' && (empty($validated['fixed_start_time']) || empty($validated['fixed_end_time']))) {
            return response()->json([
                'success' => false,
                'message' => 'fixed_start_time and fixed_end_time are required for fixed_day_off mode',
            ], 422);
        }

        if (!empty($validated['fixed_start_time']) && !empty($validated['fixed_end_time'])
            && $validated['fixed_start_time'] === $validated['fixed_end_time']) {
            return response()->json([
                'success' => false,
                'message' => 'fixed_start_time and fixed_end_time cannot be same',
            ], 422);
        }

        $effectiveFrom = Carbon::parse($validated['effective_from'] ?? now(self::TIMEZONE)->toDateString())->toDateString();

        DB::transaction(function () use ($validated, $storeId, $actor, $effectiveFrom) {
            $previous = StoreAttendancePolicy::query()
                ->where('store_id', $storeId)
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->first();

            if ($previous && Carbon::parse($previous->effective_from)->toDateString() <= $effectiveFrom) {
                $previous->update([
                    'effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(),
                ]);
            }

            StoreAttendancePolicy::create([
                'store_id' => $storeId,
                'mode' => $validated['mode'],
                'fixed_days_off' => $validated['mode'] === 'fixed_day_off'
                    ? array_values(array_unique(array_map('strtolower', $validated['fixed_days_off'] ?? [])))
                    : null,
                'fixed_start_time' => $validated['mode'] === 'fixed_day_off' ? ($validated['fixed_start_time'] ?? null) : null,
                'fixed_end_time' => $validated['mode'] === 'fixed_day_off' ? ($validated['fixed_end_time'] ?? null) : null,
                'timezone' => $validated['timezone'] ?? self::TIMEZONE,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'declared_by' => $actor->id,
                'notes' => $validated['notes'] ?? null,
                'late_fee_per_minute' => $validated['late_fee_per_minute'] ?? 0,
                'overtime_rate_per_hour' => $validated['overtime_rate_per_hour'] ?? 0,
                'grace_period_minutes' => $validated['grace_period_minutes'] ?? 0,
            ]);
        });

        $policy = StoreAttendancePolicy::query()
            ->where('store_id', $storeId)
            ->orderByDesc('effective_from')
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Store attendance policy updated successfully',
            'data' => $policy,
        ]);
    }

    public function getStorePolicy(Request $request, int $storeId)
    {
        $actor = $this->actor($request);
        $this->assertStoreAccess($actor, $storeId, true);

        $date = Carbon::parse($request->get('date', now(self::TIMEZONE)->toDateString()));

        $policy = $this->policyForDate($storeId, $date);

        return response()->json([
            'success' => true,
            'data' => $policy,
        ]);
    }

    public function declareHoliday(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        $holiday = StoreAttendanceHoliday::create([
            'store_id' => $storeId,
            'start_date' => Carbon::parse($validated['start_date'])->toDateString(),
            'end_date' => Carbon::parse($validated['end_date'])->toDateString(),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'declared_by' => $actor->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Holiday declared successfully',
            'data' => $holiday,
        ], 201);
    }

    public function listHolidays(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $query = StoreAttendanceHoliday::query()->where('store_id', $storeId);
        if (!empty($validated['from'])) {
            $query->whereDate('end_date', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $query->whereDate('start_date', '<=', $validated['to']);
        }

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('start_date')->get(),
        ]);
    }

    public function getSchedules(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'employee_id' => 'nullable|exists:employees,id',
            'date' => 'nullable|date',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $date = !empty($validated['date']) ? Carbon::parse($validated['date']) : now(self::TIMEZONE);

        $query = EmployeeWorkSchedule::query()
            ->with(['employee:id,name,employee_code,store_id'])
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date->toDateString());
            });

        if (!empty($validated['employee_id'])) {
            $query->where('employee_id', (int) $validated['employee_id']);
        }

        $rows = $query->orderBy('employee_id')->orderByDesc('effective_from')->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function assignSchedule(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'store_id' => 'required|exists:stores,id',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'duty_mode' => 'nullable|in:all_days,weekly_pattern,selected_dates',
            'weekly_days' => 'nullable|array',
            'weekly_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'duty_dates' => 'nullable|array',
            'duty_dates.*' => 'date',
            'notes' => 'nullable|string',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        if ((int) $employee->store_id !== $storeId) {
            return response()->json([
                'success' => false,
                'message' => 'Employee does not belong to this store',
            ], 422);
        }

        $effectiveFrom = Carbon::parse($validated['effective_from'] ?? now(self::TIMEZONE)->toDateString())->toDateString();

        $dutyMode = $validated['duty_mode'] ?? 'all_days';
        $weeklyDays = !empty($validated['weekly_days'])
            ? array_values(array_unique(array_map('strtolower', $validated['weekly_days'])))
            : null;
        $dutyDates = !empty($validated['duty_dates'])
            ? array_values(array_unique(array_map(fn ($value) => Carbon::parse($value)->toDateString(), $validated['duty_dates'])))
            : null;

        if ($dutyMode === 'weekly_pattern' && empty($weeklyDays)) {
            return response()->json([
                'success' => false,
                'message' => 'weekly_days is required for weekly_pattern duty mode',
            ], 422);
        }

        if ($dutyMode === 'selected_dates' && empty($dutyDates)) {
            return response()->json([
                'success' => false,
                'message' => 'duty_dates is required for selected_dates duty mode',
            ], 422);
        }

        DB::transaction(function () use ($employee, $validated, $actor, $storeId, $effectiveFrom, $dutyMode, $weeklyDays, $dutyDates) {
            EmployeeWorkSchedule::query()
                ->where('employee_id', $employee->id)
                ->where('is_active', true)
                ->whereNull('effective_to')
                ->update([
                    'effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(),
                    'is_active' => false,
                ]);

            EmployeeWorkSchedule::create([
                'employee_id' => $employee->id,
                'store_id' => $storeId,
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'effective_from' => $effectiveFrom,
                'effective_to' => $validated['effective_to'] ?? null,
                'duty_mode' => $dutyMode,
                'weekly_days' => $dutyMode === 'weekly_pattern' ? $weeklyDays : null,
                'duty_dates' => $dutyMode === 'selected_dates' ? $dutyDates : null,
                'is_active' => true,
                'assigned_by' => $actor->id,
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Work schedule assigned successfully',
        ], 201);
    }

    public function markAttendance(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'attendance_date' => 'required|date',
            'entries' => 'required|array|min:1',
            'entries.*.employee_id' => 'required|exists:employees,id',
            'entries.*.status' => 'required|in:present,late,absent,leave,half_day',
            'entries.*.in_time' => 'nullable|date_format:H:i',
            'entries.*.out_time' => 'nullable|date_format:H:i',
            'entries.*.notes' => 'nullable|string',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        $date = Carbon::parse($validated['attendance_date']);

        if ($this->isHoliday($storeId, $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance cannot be marked on declared holiday',
            ], 422);
        }

        if ($this->isFixedDayOff($storeId, $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Attendance cannot be marked on fixed day off',
            ], 422);
        }

        $saved = [];

        DB::transaction(function () use ($validated, $actor, $storeId, $date, &$saved) {
            foreach ($validated['entries'] as $entry) {
                $employee = Employee::query()->findOrFail((int) $entry['employee_id']);

                if ((int) $employee->store_id !== $storeId) {
                    throw new \RuntimeException('Employee #' . $employee->id . ' does not belong to this store');
                }

                $schedule = $this->scheduleForDate($employee->id, $date);
                $policy = $this->policyForDate($storeId, $date);
                if ($policy && $policy->mode === 'always_on_duty' && !$this->isEmployeeScheduledForDate($employee->id, $date)) {
                    throw new \RuntimeException('Employee #' . $employee->id . ' is not scheduled for duty on ' . $date->toDateString());
                }

                $status = strtolower($entry['status']);
                $inTime = $entry['in_time'] ?? null;
                $outTime = $entry['out_time'] ?? null;

                // Auto-inject timestamps for bulk actions if missing
                if (is_null($inTime) && in_array($status, ['present', 'late'])) {
                    $inTime = now()->format('H:i');
                }

                // Support "Leaving" status mapping from frontend if needed
                if ($status === 'leaving' || ($status === 'present' && !is_null($outTime))) {
                    $outTime = $outTime ?? now()->format('H:i');
                    $status = 'present'; // Ensure stored status is 'present'
                }

                // If status is present/late, check if it should be late based on schedule
                if (in_array($status, ['present', 'late']) && $inTime && $schedule) {
                    $carbonInTime = Carbon::createFromFormat('H:i', $inTime);
                    $startTime = Carbon::createFromFormat('H:i:s', $schedule->start_time);
                    if ($carbonInTime->greaterThan($startTime)) {
                        $status = 'late';
                    }
                }

                $lateMinutes = 0;
                $lateFee = 0;

                if ($status === 'late' && $inTime && $schedule && $policy) {
                    $lateStats = $this->calculateLateMinutesAndFee($employee, $date, $inTime);
                    $lateMinutes = $lateStats['late_minutes'];
                    $lateFee = $lateStats['late_fee'];
                }

                $attendance = EmployeeAttendance::query()
                    ->where('employee_id', $employee->id)
                    ->where('store_id', $storeId)
                    ->whereDate('attendance_date', $date->toDateString())
                    ->first();

                if ($attendance) {
                    if ($attendance->is_applied) {
                        throw new \RuntimeException('Attendance record for employee #' . $employee->id . ' on ' . $date->toDateString() . ' is already applied to a payroll and cannot be modified');
                    }
                    $old = $attendance->only(['status', 'in_time', 'out_time']);

                    $attendance->update([
                        'status' => $status,
                        'in_time' => $inTime,
                        'out_time' => $outTime,
                        'marked_by' => $actor->id,
                        'marked_at' => now(),
                        'notes' => $entry['notes'] ?? null,
                        'is_modified' => true,
                        'late_minutes' => $lateMinutes,
                        'late_fee' => $lateFee,
                    ]);

                    EmployeeAttendanceHistory::create([
                        'attendance_id' => $attendance->id,
                        'old_status' => $old['status'],
                        'new_status' => $status,
                        'old_in_time' => $old['in_time'],
                        'new_in_time' => $inTime,
                        'old_out_time' => $old['out_time'],
                        'new_out_time' => $outTime,
                        'reason' => 'Bulk mark update',
                        'changed_by' => $actor->id,
                        'changed_at' => now(),
                    ]);
                } else {
                    $attendance = EmployeeAttendance::create([
                        'employee_id' => $employee->id,
                        'store_id' => $storeId,
                        'attendance_date' => $date->toDateString(),
                        'status' => $status,
                        'in_time' => $inTime,
                        'out_time' => $outTime,
                        'marked_by' => $actor->id,
                        'marked_at' => now(),
                        'notes' => $entry['notes'] ?? null,
                        'is_modified' => false,
                        'late_minutes' => $lateMinutes,
                        'late_fee' => $lateFee,
                    ]);
                }

                $saved[] = $attendance->fresh();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Attendance marked successfully',
            'data' => $saved,
        ]);
    }

    public function updateAttendance(Request $request, int $attendanceId)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'status' => 'sometimes|required|in:present,late,absent,leave,half_day,off_day_auto,holiday_auto',
            'in_time' => 'nullable|date_format:H:i',
            'out_time' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string',
            'reason' => 'required|string',
        ]);

        $attendance = EmployeeAttendance::query()->with('employee')->findOrFail($attendanceId);
        $this->assertStoreAccess($actor, (int) $attendance->store_id);

        if ($attendance->is_applied) {
            return response()->json([
                'success' => false,
                'message' => 'This attendance record is already applied to a payroll and cannot be modified',
            ], 422);
        }

        $old = $attendance->only(['status', 'in_time', 'out_time']);

        $status = $validated['status'] ?? $attendance->status;
        $inTimeStr = array_key_exists('in_time', $validated) ? $validated['in_time'] : $attendance->in_time;
        $outTimeStr = array_key_exists('out_time', $validated) ? $validated['out_time'] : $attendance->out_time;
        $lateMinutes = 0;
        $lateFee = 0;

        $date = Carbon::parse($attendance->attendance_date);
        $schedule = $this->scheduleForDate($attendance->employee_id, $date);
        $policy = $this->policyForDate($attendance->store_id, $date);

        if ($status === 'present' && !empty($inTimeStr) && $schedule) {
            $inTime = Carbon::createFromFormat('H:i', $inTimeStr);
            $startTime = Carbon::createFromFormat('H:i:s', $schedule->start_time);
            if ($inTime->greaterThan($startTime)) {
                $status = 'late';
            }
        }

        if ($status === 'late' && !empty($inTimeStr) && $schedule && $policy) {
            $lateStats = $this->calculateLateMinutesAndFee($attendance->employee, $date, $inTimeStr);
            $lateMinutes = $lateStats['late_minutes'];
            $lateFee = $lateStats['late_fee'];
        }

        $attendance->update([
            'status' => $status,
            'in_time' => $inTimeStr,
            'out_time' => $outTimeStr,
            'notes' => $validated['notes'] ?? $attendance->notes,
            'marked_by' => $actor->id,
            'marked_at' => now(),
            'is_modified' => true,
            'late_minutes' => $lateMinutes,
            'late_fee' => $lateFee,
        ]);

        EmployeeAttendanceHistory::create([
            'attendance_id' => $attendance->id,
            'old_status' => $old['status'],
            'new_status' => $attendance->status,
            'old_in_time' => $old['in_time'],
            'new_in_time' => $attendance->in_time,
            'old_out_time' => $old['out_time'],
            'new_out_time' => $attendance->out_time,
            'reason' => $validated['reason'],
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Attendance updated successfully',
            'data' => $attendance->fresh(),
        ]);
    }

    public function getRangeReport(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer|exists:employees,id',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);

        if ($from->diffInDays($to) > 90) {
            return response()->json([
                'success' => false,
                'message' => 'Date range cannot exceed 90 days',
            ], 422);
        }

        $employeeQuery = Employee::query()->where('store_id', $storeId)->where('is_active', true);
        if (!empty($validated['employee_ids'])) {
            $employeeQuery->whereIn('id', $validated['employee_ids']);
        }
        $employees = $employeeQuery->orderBy('name')->get(['id', 'name', 'employee_code']);

        $attendanceRows = EmployeeAttendance::query()
            ->where('store_id', $storeId)
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $attendanceMap = [];
        foreach ($attendanceRows as $row) {
            $attendanceMap[$row->employee_id . '|' . Carbon::parse($row->attendance_date)->toDateString()] = $row;
        }

        $days = [];
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $days[] = $cursor->copy();
        }

        $report = [];
        foreach ($employees as $employee) {
            $daily = [];
            $summary = [
                'present' => 0,
                'late' => 0,
                'absent' => 0,
                'leave' => 0,
                'half_day' => 0,
                'off_day_auto' => 0,
                'holiday_auto' => 0,
                'upcoming' => 0,
                'overtime_minutes' => 0,
                'undertime_minutes' => 0,
                'worked_minutes' => 0,
                'duty_minutes' => 0,
            ];

            foreach ($days as $date) {
                $computed = $this->computeAttendanceForDate((int) $employee->id, $storeId, $date, $attendanceMap);
                $daily[] = $computed;
                $summary[$computed['status']]++;
                $summary['overtime_minutes'] += (int) ($computed['overtime_minutes'] ?? 0);
                $summary['undertime_minutes'] += (int) ($computed['undertime_minutes'] ?? 0);
                $summary['worked_minutes'] += (int) ($computed['worked_minutes'] ?? 0);
                $summary['duty_minutes'] += (int) ($computed['duty_minutes'] ?? 0);
            }

            $summary['overtime_hhmm'] = $this->minutesToHhmm((int) $summary['overtime_minutes']);
            $summary['undertime_hhmm'] = $this->minutesToHhmm((int) $summary['undertime_minutes']);
            $summary['worked_hhmm'] = $this->minutesToHhmm((int) $summary['worked_minutes']);
            $summary['duty_hhmm'] = $this->minutesToHhmm((int) $summary['duty_minutes']);

            $report[] = [
                'employee' => $employee,
                'summary' => $summary,
                'daily' => $daily,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'store_id' => $storeId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'employees' => $report,
            ],
        ]);
    }

    public function getDayReport(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'date' => 'required|date',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer|exists:employees,id',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $date = Carbon::parse($validated['date']);

        $employeeQuery = Employee::query()->where('store_id', $storeId)->where('is_active', true);
        if (!empty($validated['employee_ids'])) {
            $employeeQuery->whereIn('id', $validated['employee_ids']);
        }
        $employees = $employeeQuery->orderBy('name')->get(['id', 'name', 'employee_code']);

        $attendanceRows = EmployeeAttendance::query()
            ->where('store_id', $storeId)
            ->whereDate('attendance_date', $date->toDateString())
            ->get();

        $attendanceMap = [];
        foreach ($attendanceRows as $row) {
            $attendanceMap[$row->employee_id . '|' . Carbon::parse($row->attendance_date)->toDateString()] = $row;
        }

        $rows = [];
        foreach ($employees as $employee) {
            $rows[] = [
                'employee' => $employee,
                'attendance' => $this->computeAttendanceForDate((int) $employee->id, $storeId, $date, $attendanceMap),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'store_id' => $storeId,
                'date' => $date->toDateString(),
                'rows' => $rows,
            ],
        ]);
    }

    public function getTodayReport(Request $request)
    {
        $request->merge(['date' => now(self::TIMEZONE)->toDateString()]);
        return $this->getDayReport($request);
    }

    public function getPresentToday(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $date = now(self::TIMEZONE)->toDateString();

        $rows = EmployeeAttendance::query()
            ->with(['employee:id,name,employee_code'])
            ->where('store_id', $storeId)
            ->whereDate('attendance_date', $date)
            ->whereIn('status', ['present', 'late', 'half_day'])
            ->orderBy('status')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'store_id' => $storeId,
                'date' => $date,
                'present' => $rows,
            ],
        ]);
    }

    public function getAttendanceHistory(Request $request, int $attendanceId)
    {
        $actor = $this->actor($request);

        $attendance = EmployeeAttendance::query()->findOrFail($attendanceId);
        $this->assertStoreAccess($actor, (int) $attendance->store_id, true);

        $history = EmployeeAttendanceHistory::query()
            ->where('attendance_id', $attendanceId)
            ->with('changedBy:id,name,employee_code')
            ->orderByDesc('changed_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'attendance' => $attendance,
                'history' => $history,
            ],
        ]);
    }

    public function markOvertime(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'employee_id' => 'required|exists:employees,id',
            'overtime_date' => 'required|date',
            'overtime_hhmm' => 'required|regex:/^\d{1,2}:\d{2}$/',
            'notes' => 'nullable|string',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        if ((int) $employee->store_id !== $storeId) {
            return response()->json([
                'success' => false,
                'message' => 'Employee does not belong to this store',
            ], 422);
        }

        $date = Carbon::parse($validated['overtime_date']);

        if (EmployeeOvertime::query()->where('employee_id', $employee->id)->whereDate('overtime_date', $date->toDateString())->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Overtime already exists for this employee on selected date',
            ], 422);
        }

        $minutes = $this->parseOvertimeMinutes($validated['overtime_hhmm']);
        if ($minutes <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Overtime must be greater than 00:00',
            ], 422);
        }

        if (!$this->canMarkOvertimeForDate($employee, $storeId, $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Overtime requires present/late attendance or an active on-duty schedule for that date',
            ], 422);
        }

        $policy = $this->policyForDate($storeId, $date);
        $overtimePay = 0;
        if ($policy) {
            $overtimePay = ($minutes / 60) * (float) $policy->overtime_rate_per_hour;
        }

        $overtime = EmployeeOvertime::create([
            'employee_id' => $employee->id,
            'store_id' => $storeId,
            'overtime_date' => $date->toDateString(),
            'overtime_minutes' => $minutes,
            'overtime_hours' => $this->roundHours($minutes),
            'overtime_hhmm' => $this->minutesToHhmm($minutes),
            'notes' => $validated['notes'] ?? null,
            'marked_by' => $actor->id,
            'marked_at' => now(),
            'is_modified' => false,
            'overtime_pay' => $overtimePay,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Overtime marked successfully',
            'data' => $overtime,
        ], 201);
    }

    public function updateOvertime(Request $request, int $overtimeId)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'overtime_date' => 'sometimes|required|date',
            'overtime_hhmm' => 'sometimes|required|regex:/^\d{1,2}:\d{2}$/',
            'notes' => 'nullable|string',
            'reason' => 'required|string',
            'change_note' => 'nullable|string',
        ]);

        $overtime = EmployeeOvertime::query()->with('employee')->findOrFail($overtimeId);
        $this->assertStoreAccess($actor, (int) $overtime->store_id);

        $targetDate = array_key_exists('overtime_date', $validated)
            ? Carbon::parse($validated['overtime_date'])->toDateString()
            : Carbon::parse($overtime->overtime_date)->toDateString();

        if (array_key_exists('overtime_date', $validated)) {
            $hasConflict = EmployeeOvertime::query()
                ->where('employee_id', $overtime->employee_id)
                ->whereDate('overtime_date', $targetDate)
                ->where('id', '!=', $overtime->id)
                ->exists();

            if ($hasConflict) {
                return response()->json([
                    'success' => false,
                    'message' => 'Another overtime entry already exists for this employee on selected date',
                ], 422);
            }
        }

        $targetMinutes = array_key_exists('overtime_hhmm', $validated)
            ? $this->parseOvertimeMinutes($validated['overtime_hhmm'])
            : (int) $overtime->overtime_minutes;

        if ($targetMinutes <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Overtime must be greater than 00:00',
            ], 422);
        }

        $targetDateCarbon = Carbon::parse($targetDate);
        if (!$this->canMarkOvertimeForDate($overtime->employee, (int) $overtime->store_id, $targetDateCarbon)) {
            return response()->json([
                'success' => false,
                'message' => 'Overtime requires present/late attendance or an active on-duty schedule for that date',
            ], 422);
        }

        $old = $overtime->only(['overtime_date', 'overtime_minutes', 'overtime_hours', 'overtime_hhmm']);

        $overtime->update([
            'overtime_date' => $targetDate,
            'overtime_minutes' => $targetMinutes,
            'overtime_hours' => $this->roundHours($targetMinutes),
            'overtime_hhmm' => $this->minutesToHhmm($targetMinutes),
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $overtime->notes,
            'marked_by' => $actor->id,
            'marked_at' => now(),
            'is_modified' => true,
        ]);

        EmployeeOvertimeHistory::create([
            'overtime_id' => $overtime->id,
            'old_overtime_date' => $old['overtime_date'],
            'new_overtime_date' => $overtime->overtime_date,
            'old_overtime_minutes' => $old['overtime_minutes'],
            'new_overtime_minutes' => $overtime->overtime_minutes,
            'old_overtime_hours' => $old['overtime_hours'],
            'new_overtime_hours' => $overtime->overtime_hours,
            'old_overtime_hhmm' => $old['overtime_hhmm'],
            'new_overtime_hhmm' => $overtime->overtime_hhmm,
            'reason' => $validated['reason'],
            'change_note' => $validated['change_note'] ?? null,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Overtime updated successfully',
            'data' => $overtime->fresh(),
        ]);
    }

    public function getOvertimeHistory(Request $request, int $overtimeId)
    {
        $actor = $this->actor($request);

        $overtime = EmployeeOvertime::query()->findOrFail($overtimeId);
        $this->assertStoreAccess($actor, (int) $overtime->store_id, true);

        $history = EmployeeOvertimeHistory::query()
            ->where('overtime_id', $overtimeId)
            ->with('changedBy:id,name,employee_code')
            ->orderByDesc('changed_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'overtime' => $overtime,
                'history' => $history,
            ],
        ]);
    }

    public function getEmployeeOvertimeReport(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'employee_id' => 'required|exists:employees,id',
            'from' => 'nullable|date|required_without:month',
            'to' => 'nullable|date|after_or_equal:from|required_with:from',
            'month' => 'nullable|date_format:Y-m|required_without:from',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        if ((int) $employee->store_id !== $storeId) {
            return response()->json([
                'success' => false,
                'message' => 'Employee does not belong to this store',
            ], 422);
        }

        [$from, $to] = $this->resolveOvertimeDateRange($validated);

        $rows = EmployeeOvertime::query()
            ->where('store_id', $storeId)
            ->where('employee_id', $employee->id)
            ->whereBetween('overtime_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('overtime_date')
            ->get();

        $totalMinutes = (int) $rows->sum('overtime_minutes');

        return response()->json([
            'success' => true,
            'data' => [
                'store_id' => $storeId,
                'employee' => $employee->only(['id', 'name', 'employee_code']),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'summary' => [
                    'total_minutes' => $totalMinutes,
                    'total_hhmm' => $this->minutesToHhmm($totalMinutes),
                    'total_hours' => $this->roundHours($totalMinutes),
                    'entries' => $rows->count(),
                ],
                'daily' => $rows,
            ],
        ]);
    }

    public function getCumulatedOvertime(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'from' => 'nullable|date|required_without:month',
            'to' => 'nullable|date|after_or_equal:from|required_with:from',
            'month' => 'nullable|date_format:Y-m|required_without:from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        [$from, $to] = $this->resolveOvertimeDateRange($validated);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $aggregated = EmployeeOvertime::query()
            ->selectRaw('employee_id, SUM(overtime_minutes) as total_minutes, SUM(overtime_hours) as total_hours, COUNT(*) as total_entries')
            ->where('store_id', $storeId)
            ->whereBetween('overtime_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('employee_id');

        $paginator = Employee::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->leftJoinSub($aggregated, 'ot', function ($join) {
                $join->on('employees.id', '=', 'ot.employee_id');
            })
            ->selectRaw('employees.id, employees.name, employees.employee_code, COALESCE(ot.total_minutes, 0) as total_minutes, COALESCE(ot.total_hours, 0) as total_hours, COALESCE(ot.total_entries, 0) as total_entries')
            ->orderByDesc('total_minutes')
            ->orderBy('employees.name')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(function ($row) {
            $minutes = (int) $row->total_minutes;
            return [
                'employee' => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'employee_code' => $row->employee_code,
                ],
                'total_minutes' => $minutes,
                'total_hhmm' => $this->minutesToHhmm($minutes),
                'total_hours' => $this->roundHours($minutes),
                'total_entries' => (int) $row->total_entries,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'store_id' => $storeId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'rows' => $rows,
            ],
        ]);
    }

    public function createRewardFine(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'employee_id' => 'required|exists:employees,id',
            'entry_date' => 'required|date',
            'entry_type' => 'required|in:reward,fine',
            'amount' => 'required|numeric|gt:0',
            'title' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        if ((int) $employee->store_id !== $storeId) {
            return response()->json([
                'success' => false,
                'message' => 'Employee does not belong to this store',
            ], 422);
        }

        $entry = EmployeeRewardFine::create([
            'employee_id' => $employee->id,
            'store_id' => $storeId,
            'entry_date' => Carbon::parse($validated['entry_date'])->toDateString(),
            'entry_type' => $validated['entry_type'],
            'amount' => round((float) $validated['amount'], 2, PHP_ROUND_HALF_UP),
            'title' => $validated['title'],
            'notes' => $validated['notes'] ?? null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'is_applied' => false,
        ]);

        $this->safeWriteRewardFineHistory(
            $entry,
            'created',
            null,
            $entry->toArray(),
            'Reward/fine entry created',
            $actor->id,
            ['source' => 'create_endpoint']
        );

        return response()->json([
            'success' => true,
            'message' => 'Reward/fine entry created successfully',
            'data' => $entry,
        ], 201);
    }

    public function updateRewardFine(Request $request, int $entryId)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'entry_date' => 'sometimes|required|date',
            'entry_type' => 'sometimes|required|in:reward,fine',
            'amount' => 'sometimes|required|numeric|gt:0',
            'title' => 'sometimes|required|string|max:255',
            'notes' => 'nullable|string',
            'reason' => 'required|string',
        ]);

        $entry = EmployeeRewardFine::query()->findOrFail($entryId);
        $this->assertStoreAccess($actor, (int) $entry->store_id);

        if ($entry->is_applied) {
            return response()->json([
                'success' => false,
                'message' => 'Applied reward/fine entries cannot be edited',
            ], 422);
        }

        $old = $entry->toArray();

        $entry->update([
            'entry_date' => array_key_exists('entry_date', $validated)
                ? Carbon::parse($validated['entry_date'])->toDateString()
                : Carbon::parse($entry->entry_date)->toDateString(),
            'entry_type' => $validated['entry_type'] ?? $entry->entry_type,
            'amount' => array_key_exists('amount', $validated)
                ? round((float) $validated['amount'], 2, PHP_ROUND_HALF_UP)
                : (float) $entry->amount,
            'title' => $validated['title'] ?? $entry->title,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $entry->notes,
            'updated_by' => $actor->id,
        ]);

        $this->safeWriteRewardFineHistory(
            $entry,
            'updated',
            $old,
            $entry->fresh()->toArray(),
            $validated['reason'],
            $actor->id,
            ['source' => 'update_endpoint']
        );

        return response()->json([
            'success' => true,
            'message' => 'Reward/fine entry updated successfully',
            'data' => $entry->fresh(),
        ]);
    }

    public function deleteRewardFine(Request $request, int $entryId)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $entry = EmployeeRewardFine::query()->findOrFail($entryId);
        $this->assertStoreAccess($actor, (int) $entry->store_id);

        if ($entry->is_applied) {
            return response()->json([
                'success' => false,
                'message' => 'Applied reward/fine entries cannot be deleted',
            ], 422);
        }

        $old = $entry->toArray();
        $entry->delete();

        $this->safeWriteRewardFineHistory(
            $entry,
            'deleted',
            $old,
            null,
            $validated['reason'],
            $actor->id,
            ['source' => 'delete_endpoint']
        );

        return response()->json([
            'success' => true,
            'message' => 'Reward/fine entry deleted successfully',
        ]);
    }

    public function getRewardFineHistory(Request $request, int $entryId)
    {
        $actor = $this->actor($request);

        $entry = EmployeeRewardFine::withTrashed()->findOrFail($entryId);
        $this->assertStoreAccess($actor, (int) $entry->store_id, true);

        $history = EmployeeRewardFineHistory::query()
            ->where('reward_fine_id', $entry->id)
            ->with('changedBy:id,name,employee_code')
            ->orderByDesc('changed_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'entry' => $entry,
                'history' => $history,
            ],
        ]);
    }

    public function getEmployeeRewardFineReport(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'employee_id' => 'required|exists:employees,id',
            'from' => 'nullable|date|required_without:month',
            'to' => 'nullable|date|after_or_equal:from|required_with:from',
            'month' => 'nullable|date_format:Y-m|required_without:from',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        if ((int) $employee->store_id !== $storeId) {
            return response()->json([
                'success' => false,
                'message' => 'Employee does not belong to this store',
            ], 422);
        }

        [$from, $to] = $this->resolveDateRangeFromMonthOrRange($validated);

        $rows = EmployeeRewardFine::query()
            ->where('store_id', $storeId)
            ->where('employee_id', $employee->id)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $totalReward = (float) $rows->where('entry_type', 'reward')->sum('amount');
        $totalFine = (float) $rows->where('entry_type', 'fine')->sum('amount');
        $net = round($totalReward - $totalFine, 2, PHP_ROUND_HALF_UP);

        return response()->json([
            'success' => true,
            'data' => [
                'store_id' => $storeId,
                'employee' => $employee->only(['id', 'name', 'employee_code']),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'summary' => [
                    'total_reward' => round($totalReward, 2, PHP_ROUND_HALF_UP),
                    'total_fine' => round($totalFine, 2, PHP_ROUND_HALF_UP),
                    'net_adjustment' => $net,
                    'entry_count' => $rows->count(),
                ],
                'rows' => $rows,
            ],
        ]);
    }

    public function getCumulatedRewardFine(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'from' => 'nullable|date|required_without:month',
            'to' => 'nullable|date|after_or_equal:from|required_with:from',
            'month' => 'nullable|date_format:Y-m|required_without:from',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId, true);

        [$from, $to] = $this->resolveDateRangeFromMonthOrRange($validated);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $aggregated = EmployeeRewardFine::query()
            ->selectRaw("employee_id,
                SUM(CASE WHEN entry_type = 'reward' THEN amount ELSE 0 END) as total_reward,
                SUM(CASE WHEN entry_type = 'fine' THEN amount ELSE 0 END) as total_fine,
                COUNT(*) as total_entries")
            ->where('store_id', $storeId)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('employee_id');

        $paginator = Employee::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->leftJoinSub($aggregated, 'rf', function ($join) {
                $join->on('employees.id', '=', 'rf.employee_id');
            })
            ->selectRaw("employees.id, employees.name, employees.employee_code, employees.salary,
                COALESCE(rf.total_reward, 0) as total_reward,
                COALESCE(rf.total_fine, 0) as total_fine,
                COALESCE(rf.total_entries, 0) as total_entries,
                (COALESCE(rf.total_reward, 0) - COALESCE(rf.total_fine, 0)) as net_adjustment")
            ->orderByDesc('net_adjustment')
            ->orderBy('employees.name')
            ->paginate($perPage);

        $rows = collect($paginator->items())->map(function ($row) {
            return [
                'employee' => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'employee_code' => $row->employee_code,
                    'salary' => round((float) ($row->salary ?? 0), 2, PHP_ROUND_HALF_UP),
                ],
                'total_reward' => round((float) $row->total_reward, 2, PHP_ROUND_HALF_UP),
                'total_fine' => round((float) $row->total_fine, 2, PHP_ROUND_HALF_UP),
                'net_adjustment' => round((float) $row->net_adjustment, 2, PHP_ROUND_HALF_UP),
                'total_entries' => (int) $row->total_entries,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'store_id' => $storeId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'rows' => $rows,
            ],
        ]);
    }

    public function applyRewardFineToSalary(Request $request)
    {
        $actor = $this->actor($request);

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'employee_id' => 'required|exists:employees,id',
            'month' => 'required|date_format:Y-m',
            'notes' => 'nullable|string',
        ]);

        $storeId = (int) $validated['store_id'];
        $this->assertStoreAccess($actor, $storeId);

        $employee = Employee::query()->findOrFail((int) $validated['employee_id']);
        if ((int) $employee->store_id !== $storeId) {
            return response()->json([
                'success' => false,
                'message' => 'Employee does not belong to this store',
            ], 422);
        }

        $monthStart = Carbon::createFromFormat('Y-m', $validated['month'], self::TIMEZONE)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $alreadyLocked = EmployeeSalaryAdjustment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('adjustment_month', $monthStart->toDateString())
            ->where('source', 'reward_fine')
            ->exists();

        if ($alreadyLocked) {
            return response()->json([
                'success' => false,
                'message' => 'Reward/fine month already applied for this employee',
            ], 422);
        }

        $entries = EmployeeRewardFine::query()
            ->where('employee_id', $employee->id)
            ->where('store_id', $storeId)
            ->whereBetween('entry_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->where('is_applied', false)
            ->get();

        if ($entries->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No unapplied reward/fine entries found for selected month',
            ], 422);
        }

        $totalReward = round((float) $entries->where('entry_type', 'reward')->sum('amount'), 2, PHP_ROUND_HALF_UP);
        $totalFine = round((float) $entries->where('entry_type', 'fine')->sum('amount'), 2, PHP_ROUND_HALF_UP);
        $net = round($totalReward - $totalFine, 2, PHP_ROUND_HALF_UP);

        $adjustment = DB::transaction(function () use ($employee, $storeId, $monthStart, $actor, $validated, $entries, $totalReward, $totalFine, $net) {
            $adjustment = EmployeeSalaryAdjustment::create([
                'employee_id' => $employee->id,
                'store_id' => $storeId,
                'adjustment_month' => $monthStart->toDateString(),
                'source' => 'reward_fine',
                'total_reward' => $totalReward,
                'total_fine' => $totalFine,
                'net_adjustment' => $net,
                'applied_by' => $actor->id,
                'applied_at' => now(),
                'notes' => $validated['notes'] ?? null,
                'metadata' => [
                    'entry_count' => $entries->count(),
                    'timezone' => self::TIMEZONE,
                ],
            ]);

            foreach ($entries as $entry) {
                $oldState = $entry->toArray();

                DB::table('employee_salary_adjustment_items')->insert([
                    'adjustment_id' => $adjustment->id,
                    'reward_fine_id' => $entry->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $entry->update([
                    'is_applied' => true,
                    'applied_month' => $monthStart->toDateString(),
                    'applied_by' => $actor->id,
                    'applied_at' => now(),
                    'updated_by' => $actor->id,
                ]);

                $this->safeWriteRewardFineHistory(
                    $entry,
                    'applied',
                    $oldState,
                    $entry->fresh()->toArray(),
                    'Applied to salary month ' . $monthStart->format('Y-m'),
                    $actor->id,
                    [
                        'adjustment_id' => $adjustment->id,
                        'source' => 'apply_endpoint',
                    ]
                );
            }

            return $adjustment;
        });

        return response()->json([
            'success' => true,
            'message' => 'Reward/fine adjustment applied to salary successfully',
            'data' => [
                'adjustment' => $adjustment,
                'summary' => [
                    'total_reward' => $totalReward,
                    'total_fine' => $totalFine,
                    'net_adjustment' => $net,
                    'entry_count' => $entries->count(),
                ],
            ],
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function actor(Request $request): Employee
    {
        /** @var Employee $actor */
        $actor = $request->user();
        return $actor;
    }

    private function isAdmin(Employee $employee): bool
    {
        $slug = $employee->role?->slug;
        return in_array($slug, ['super-admin', 'super_admin', 'superadmin', 'admin'], true);
    }

    private function isManager(Employee $employee): bool
    {
        $slug = $employee->role?->slug;
        return in_array($slug, ['manager', 'branch-manager', 'branch_manager'], true);
    }

    private function assertStoreAccess(Employee $actor, int $storeId, bool $allowAdminAny = true): void
    {
        if ($allowAdminAny && $this->isAdmin($actor)) {
            return;
        }

        if ($this->isManager($actor) && (int) $actor->store_id !== $storeId) {
            throw new AuthorizationException('Manager can only manage attendance for own store');
        }
    }

    private function policyForDate(int $storeId, Carbon $date): ?StoreAttendancePolicy
    {
        return StoreAttendancePolicy::query()
            ->where('store_id', $storeId)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date->toDateString());
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function isHoliday(int $storeId, Carbon $date): bool
    {
        return StoreAttendanceHoliday::query()
            ->where('store_id', $storeId)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();
    }

    private function isFixedDayOff(int $storeId, Carbon $date): bool
    {
        $policy = $this->policyForDate($storeId, $date);
        if (!$policy || $policy->mode !== 'fixed_day_off') {
            return false;
        }

        $offDays = array_map('strtolower', $policy->fixed_days_off ?? []);
        return in_array(strtolower($date->format('l')), $offDays, true);
    }

    private function scheduleForDate(int $employeeId, Carbon $date): ?EmployeeWorkSchedule
    {
        return EmployeeWorkSchedule::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $date->toDateString());
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    private function isEmployeeScheduledForDate(int $employeeId, Carbon $date): bool
    {
        $schedule = $this->scheduleForDate($employeeId, $date);
        if (!$schedule) {
            return false;
        }

        $mode = $schedule->duty_mode ?? 'all_days';

        if ($mode === 'selected_dates') {
            $dates = array_values(array_unique(array_map(
                fn ($value) => Carbon::parse($value)->toDateString(),
                $schedule->duty_dates ?? []
            )));
            return in_array($date->toDateString(), $dates, true);
        }

        if ($mode === 'weekly_pattern') {
            $days = array_values(array_unique(array_map('strtolower', $schedule->weekly_days ?? [])));
            return in_array(strtolower($date->format('l')), $days, true);
        }

        return true;
    }

    private function computeAttendanceForDate(int $employeeId, int $storeId, Carbon $date, array $attendanceMap): array
    {
        $key = $employeeId . '|' . $date->toDateString();
        $dutyWindow = $this->resolveDutyWindow($employeeId, $storeId, $date);

        if (isset($attendanceMap[$key])) {
            /** @var EmployeeAttendance $row */
            $row = $attendanceMap[$key];
            $metrics = $this->computeWorkMetrics($row->in_time, $row->out_time, $dutyWindow);
            return [
                'date' => $date->toDateString(),
                'status' => $row->status,
                'in_time' => $row->in_time,
                'out_time' => $row->out_time,
                'attendance_id' => $row->id,
                'source' => 'manual',
                'scheduled_start_time' => $dutyWindow['start_time'] ?? null,
                'scheduled_end_time' => $dutyWindow['end_time'] ?? null,
                'duty_minutes' => $metrics['duty_minutes'],
                'worked_minutes' => $metrics['worked_minutes'],
                'overtime_minutes' => $metrics['overtime_minutes'],
                'undertime_minutes' => $metrics['undertime_minutes'],
                'overtime_hhmm' => $this->minutesToHhmm($metrics['overtime_minutes']),
                'undertime_hhmm' => $this->minutesToHhmm($metrics['undertime_minutes']),
                'worked_hhmm' => $this->minutesToHhmm($metrics['worked_minutes']),
                'duty_hhmm' => $this->minutesToHhmm($metrics['duty_minutes']),
            ];
        }

        if ($this->isHoliday($storeId, $date)) {
            return $this->buildComputedDayPayload($date, 'holiday_auto', 'auto', $dutyWindow);
        }

        if ($this->isFixedDayOff($storeId, $date)) {
            return $this->buildComputedDayPayload($date, 'off_day_auto', 'auto', $dutyWindow);
        }

        $policy = $this->policyForDate($storeId, $date);
        if ($policy && $policy->mode === 'always_on_duty' && !$this->isEmployeeScheduledForDate($employeeId, $date)) {
            return $this->buildComputedDayPayload($date, 'off_day_auto', 'roster_off', $dutyWindow);
        }

        if ($date->isFuture() && !$date->isToday()) {
            return $this->buildComputedDayPayload($date, 'upcoming', 'future_scheduled', $dutyWindow);
        }

        return $this->buildComputedDayPayload($date, 'absent', 'computed_absent', $dutyWindow);
    }

    private function buildComputedDayPayload(Carbon $date, string $status, string $source, ?array $dutyWindow): array
    {
        $metrics = $this->computeWorkMetrics(null, null, $dutyWindow);

        return [
            'date' => $date->toDateString(),
            'status' => $status,
            'in_time' => null,
            'out_time' => null,
            'attendance_id' => null,
            'source' => $source,
            'scheduled_start_time' => $dutyWindow['start_time'] ?? null,
            'scheduled_end_time' => $dutyWindow['end_time'] ?? null,
            'duty_minutes' => $metrics['duty_minutes'],
            'worked_minutes' => $metrics['worked_minutes'],
            'overtime_minutes' => $metrics['overtime_minutes'],
            'undertime_minutes' => $metrics['undertime_minutes'],
            'overtime_hhmm' => $this->minutesToHhmm($metrics['overtime_minutes']),
            'undertime_hhmm' => $this->minutesToHhmm($metrics['undertime_minutes']),
            'worked_hhmm' => $this->minutesToHhmm($metrics['worked_minutes']),
            'duty_hhmm' => $this->minutesToHhmm($metrics['duty_minutes']),
        ];
    }

    private function resolveDutyWindow(int $employeeId, int $storeId, Carbon $date): ?array
    {
        $schedule = $this->scheduleForDate($employeeId, $date);
        $policy = $this->policyForDate($storeId, $date);

        $startTime = $schedule?->start_time ? substr((string) $schedule->start_time, 0, 5) : null;
        $endTime = $schedule?->end_time ? substr((string) $schedule->end_time, 0, 5) : null;

        if ((!$startTime || !$endTime) && $policy) {
            $startTime = $startTime ?: ($policy->fixed_start_time ? substr((string) $policy->fixed_start_time, 0, 5) : null);
            $endTime = $endTime ?: ($policy->fixed_end_time ? substr((string) $policy->fixed_end_time, 0, 5) : null);
        }

        if (!$startTime || !$endTime) {
            return null;
        }

        $start = Carbon::parse($date->toDateString() . ' ' . $startTime, self::TIMEZONE);
        $end = Carbon::parse($date->toDateString() . ' ' . $endTime, self::TIMEZONE);
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [
            'start' => $start,
            'end' => $end,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duty_minutes' => $start->diffInMinutes($end),
        ];
    }

    private function computeWorkMetrics(?string $inTime, ?string $outTime, ?array $dutyWindow): array
    {
        $dutyMinutes = (int) ($dutyWindow['duty_minutes'] ?? 0);
        $workedMinutes = 0;

        if ($inTime && $outTime) {
            $baseDate = now(self::TIMEZONE)->toDateString();
            $inAt = Carbon::parse($baseDate . ' ' . substr($inTime, 0, 5), self::TIMEZONE);
            $outAt = Carbon::parse($baseDate . ' ' . substr($outTime, 0, 5), self::TIMEZONE);

            if ($outAt->lessThan($inAt)) {
                $outAt->addDay();
            }

            $workedMinutes = $inAt->diffInMinutes($outAt);
        }

        $overtimeMinutes = $dutyMinutes > 0 ? max($workedMinutes - $dutyMinutes, 0) : 0;
        $undertimeMinutes = $dutyMinutes > 0 && $workedMinutes > 0 ? max($dutyMinutes - $workedMinutes, 0) : 0;

        return [
            'duty_minutes' => $dutyMinutes,
            'worked_minutes' => $workedMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'undertime_minutes' => $undertimeMinutes,
        ];
    }

    private function canMarkOvertimeForDate(Employee $employee, int $storeId, Carbon $date): bool
    {
        $hasPresentOrLateAttendance = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->where('store_id', $storeId)
            ->whereDate('attendance_date', $date->toDateString())
            ->whereIn('status', ['present', 'late'])
            ->exists();

        if ($hasPresentOrLateAttendance) {
            return true;
        }

        $policy = $this->policyForDate($storeId, $date);
        if (!$policy) {
            return false;
        }

        if ($policy->mode === 'always_on_duty') {
            return $this->isEmployeeScheduledForDate((int) $employee->id, $date);
        }

        if ($policy->mode === 'fixed_day_off') {
            return !empty($policy->fixed_start_time) && !empty($policy->fixed_end_time);
        }

        return false;
    }

    private function parseOvertimeMinutes(string $hhmm): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $hhmm));
        if ($minutes < 0 || $minutes > 59 || $hours < 0) {
            return 0;
        }

        return ($hours * 60) + $minutes;
    }

    private function roundHours(int $minutes): float
    {
        return round($minutes / 60, 2, PHP_ROUND_HALF_UP);
    }

    private function minutesToHhmm(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return str_pad((string) $hours, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $remaining, 2, '0', STR_PAD_LEFT);
    }

    private function resolveOvertimeDateRange(array $validated): array
    {
        if (!empty($validated['month'])) {
            $monthDate = Carbon::createFromFormat('Y-m', $validated['month'], self::TIMEZONE);
            return [$monthDate->copy()->startOfMonth(), $monthDate->copy()->endOfMonth()];
        }

        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        return [$from, $to];
    }

    private function resolveDateRangeFromMonthOrRange(array $validated): array
    {
        if (!empty($validated['month'])) {
            $monthDate = Carbon::createFromFormat('Y-m', $validated['month'], self::TIMEZONE);
            return [$monthDate->copy()->startOfMonth(), $monthDate->copy()->endOfMonth()];
        }

        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        return [$from, $to];
    }

    private function safeWriteRewardFineHistory(
        EmployeeRewardFine $entry,
        string $action,
        ?array $old,
        ?array $new,
        ?string $reason,
        int $changedBy,
        array $metadata = []
    ): void {
        try {
            $this->writeRewardFineHistory($entry, $action, $old, $new, $reason, $changedBy, $metadata);
        } catch (\Throwable $e) {
            logger()->warning('Reward/fine history write failed after main entry save', [
                'reward_fine_id' => $entry->id ?? null,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function writeRewardFineHistory(
        EmployeeRewardFine $entry,
        string $action,
        ?array $old,
        ?array $new,
        ?string $reason,
        int $changedBy,
        array $metadata = []
    ): void {
        EmployeeRewardFineHistory::create([
            'reward_fine_id' => $entry->id,
            'action' => $action,
            'old_entry_date' => isset($old['entry_date']) ? Carbon::parse($old['entry_date'])->toDateString() : null,
            'new_entry_date' => isset($new['entry_date']) ? Carbon::parse($new['entry_date'])->toDateString() : null,
            'old_entry_type' => $old['entry_type'] ?? null,
            'new_entry_type' => $new['entry_type'] ?? null,
            'old_amount' => isset($old['amount']) ? round((float) $old['amount'], 2, PHP_ROUND_HALF_UP) : null,
            'new_amount' => isset($new['amount']) ? round((float) $new['amount'], 2, PHP_ROUND_HALF_UP) : null,
            'old_title' => $old['title'] ?? null,
            'new_title' => $new['title'] ?? null,
            'old_notes' => $old['notes'] ?? null,
            'new_notes' => $new['notes'] ?? null,
            'reason' => $reason,
            'changed_by' => $changedBy,
            'changed_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}