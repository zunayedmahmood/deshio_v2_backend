<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Store;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    use DatabaseAgnosticSearch;
    public function createEmployee(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:employees,email',
            'password' => 'required|string|min:8',
            'store_id' => 'required|exists:stores,id',
            'role_id' => 'required|exists:roles,id',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'employee_code' => 'nullable|string|unique:employees,employee_code',
            'hire_date' => 'nullable|date',
            'department' => 'nullable|string',
            'salary' => 'nullable|numeric',
            'manager_id' => 'nullable|exists:employees,id',
            'is_active' => 'boolean',
            'avatar' => 'nullable|string',
        ]);

        $validated['password'] = bcrypt($validated['password']);
        $validated['is_in_service'] = true;

        $employee = Employee::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Employee created successfully',
            'data' => $employee->load(['store', 'role', 'manager'])
        ], 201);
    }

    public function updateEmployee(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('employees')->ignore($employee->id)],
            'store_id' => 'sometimes|required|exists:stores,id',
            'role_id' => 'sometimes|required|exists:roles,id',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'employee_code' => ['sometimes', 'nullable', 'string', Rule::unique('employees')->ignore($employee->id)],
            'hire_date' => 'nullable|date',
            'department' => 'nullable|string',
            'salary' => 'nullable|numeric',
            'manager_id' => ['nullable', 'exists:employees,id', Rule::notIn([$employee->id])],
            'avatar' => 'nullable|string',
        ]);

        // Prevent changing own manager to self
        if (isset($validated['manager_id']) && $validated['manager_id'] == $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Employee cannot be their own manager'
            ], 400);
        }

        $employee->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Employee updated successfully',
            'data' => $employee->load(['store', 'role', 'manager'])
        ]);
    }

    public function changeEmployeeRole(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        $oldRole = $employee->role->title ?? 'No Role';
        $newRole = Role::findOrFail($validated['role_id'])->title;

        $employee->update(['role_id' => $validated['role_id']]);

        return response()->json([
            'success' => true,
            'message' => "Employee role changed from '{$oldRole}' to '{$newRole}'",
            'data' => $employee->load(['store', 'role', 'manager'])
        ]);
    }

    public function transferEmployee(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'new_store_id' => 'required|exists:stores,id',
        ]);

        $newStoreId = $validated['new_store_id'];

        // Check if transferring to the same store
        if ($employee->store_id == $newStoreId) {
            return response()->json([
                'success' => false,
                'message' => 'Employee is already assigned to this store'
            ], 400);
        }

        $oldStore = $employee->store->name ?? 'Unknown Store';
        $newStore = Store::findOrFail($newStoreId)->name;

        $employee->update(['store_id' => $newStoreId]);

        return response()->json([
            'success' => true,
            'message' => "Employee transferred from '{$oldStore}' to '{$newStore}'",
            'data' => $employee->load(['store', 'role', 'manager'])
        ]);
    }

    public function deleteEmployee($id)
    {
        $employee = Employee::findOrFail($id); 

        // Prevent deleting self
        if ($employee->id == auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account'
            ], 400);
        }

        // Soft delete by setting is_active to false
        $employee->update([
            'is_active' => false,
            'is_in_service' => false
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee deactivated successfully'
        ]);
    }

    public function activateEmployee($id)
    {
        $employee = Employee::findOrFail($id);

        $employee->update([
            'is_active' => true,
            'is_in_service' => true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee activated successfully',
            'data' => $employee->load(['store', 'role', 'manager'])
        ]);
    }

    public function deactivateEmployee($id)
    {
        $employee = Employee::findOrFail($id);

        // Prevent deactivating self
        if ($employee->id == auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account'
            ], 400);
        }

        $employee->update([
            'is_active' => false,
            'is_in_service' => false
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Employee deactivated successfully'
        ]);
    }

    public function getEmployees(Request $request)
    {
        $authUser = auth()->user();
        $query = Employee::with(['store', 'role', 'manager']);

        // ---------------------------------------------------------------
        // Store-based visibility enforcement
        // Global roles (super-admin, admin): see all employees.
        // All other roles: only see employees in their assigned store.
        // This mirrors the frontend FEATURE_ROLES logic where branch roles
        // are "scoped" to a single store.
        // ---------------------------------------------------------------
        $isGlobal = $authUser && $authUser->isGlobal();
        if (!$isGlobal && $authUser) {
            // Force-scope to the authenticated user's own store.
            $query->where('store_id', $authUser->store_id);
        } elseif ($request->has('store_id') && $request->store_id) {
            // Global users can optionally filter by store.
            $query->where('store_id', $request->store_id);
        }

        // Filters
        if ($request->has('role_id') && $request->role_id) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->has('department') && $request->department) {
            $query->where('department', $request->department);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('is_in_service')) {
            $query->where('is_in_service', $request->boolean('is_in_service'));
        }

        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['name', 'email', 'employee_code', 'phone'], $search);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        $allowedSortFields = ['name', 'email', 'employee_code', 'hire_date', 'department', 'salary', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        $employees = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $employees,
            // Let the frontend know whether this result is store-scoped
            'is_scoped' => !$isGlobal,
            'scoped_store_id' => !$isGlobal && $authUser ? $authUser->store_id : null,
        ]);
    }

    public function getEmployee($id)
    {
        $employee = Employee::with([
            'store',
            'role',
            'manager',
            'subordinates',
            'sessions' => function($query) {
                $query->latest()->limit(5);
            }
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $employee
        ]);
    }

    public function getEmployeesByStore($storeId)
    {
        $store = Store::findOrFail($storeId);

        $employees = Employee::with(['role', 'manager'])
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $employees,
            'store' => $store
        ]);
    }

    public function getEmployeesByRole($roleId)
    {
        $role = Role::findOrFail($roleId);

        $employees = Employee::with(['store', 'manager'])
            ->where('role_id', $roleId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $employees,
            'role' => $role
        ]);
    }

    public function getEmployeeStats()
    {
        $authUser = auth()->user();
        $isGlobal = $authUser && $authUser->isGlobal();
        
        $query = Employee::query();
        if (!$isGlobal && $authUser) {
            $query->where('store_id', $authUser->store_id);
        }

        $stats = [
            'total_employees' => (clone $query)->count(),
            'active_employees' => (clone $query)->where('is_active', true)->count(),
            'inactive_employees' => (clone $query)->where('is_active', false)->count(),
            'in_service' => (clone $query)->where('is_in_service', true)->count(),
            'by_department' => (clone $query)->where('is_active', true)
                ->whereNotNull('department')
                ->selectRaw('department, COUNT(*) as count')
                ->groupBy('department')
                ->get(),
            'by_role' => (clone $query)->with('role')
                ->where('is_active', true)
                ->selectRaw('role_id, COUNT(*) as count')
                ->groupBy('role_id')
                ->get()
                ->map(function($item) {
                    return [
                        'role' => $item->role->title ?? 'No Role',
                        'count' => $item->count
                    ];
                }),
            'recent_hires' => (clone $query)->where('is_active', true)
                ->orderBy('hire_date', 'desc')
                ->limit(5)
                ->get(['name', 'hire_date', 'department'])
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    public function changePassword(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'current_password' => 'required_with:new_password|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        // If changing own password, verify current password
        if ($employee->id == auth()->id() && isset($validated['current_password'])) {
            if (!Hash::check($validated['current_password'], $employee->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 400);
            }
        }

        $employee->update([
            'password' => Hash::make($validated['new_password'])
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'exists:employees,id',
            'is_active' => 'required|boolean',
            'is_in_service' => 'boolean',
        ]);

        $count = Employee::whereIn('id', $validated['employee_ids'])
            ->update([
                'is_active' => $validated['is_active'],
                'is_in_service' => $validated['is_in_service'] ?? $validated['is_active']
            ]);

        return response()->json([
            'success' => true,
            'message' => "Updated {$count} employees successfully"
        ]);
    }

    public function getSubordinates($id)
    {
        $employee = Employee::findOrFail($id);

        $subordinates = $employee->subordinates()
            ->with(['store', 'role'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subordinates,
            'manager' => $employee->only(['id', 'name', 'employee_code'])
        ]);
    }

    /**
     * Get employee sessions
     */
    public function getSessions($id)
    {
        $employee = Employee::findOrFail($id);

        $sessions = $employee->sessions()
            ->orderBy('last_activity_at', 'desc')
            ->paginate(request()->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $sessions,
            'active_sessions_count' => $employee->activeSessions()->count()
        ]);
    }

    /**
     * Revoke employee session
     */
    public function revokeSession(Request $request, $id, $sessionId)
    {
        $employee = Employee::findOrFail($id);
        
        $session = $employee->sessions()->findOrFail($sessionId);
        $session->update(['revoked_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Session revoked successfully'
        ]);
    }

    /**
     * Revoke all sessions for an employee
     */
    public function revokeAllSessions($id)
    {
        $employee = Employee::findOrFail($id);
        
        $count = $employee->sessions()
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => "Revoked {$count} active sessions"
        ]);
    }

    /**
     * Get employee MFA settings
     */
    public function getMFASettings($id)
    {
        $employee = Employee::findOrFail($id);

        $mfaSettings = $employee->mfa()
            ->with('backupCodes')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $mfaSettings,
            'has_mfa_enabled' => $employee->enabledMfa()->exists()
        ]);
    }

    /**
     * Enable MFA for employee
     */
    public function enableMFA(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'type' => 'required|in:sms,email,totp,backup_codes',
            'secret' => 'nullable|string',
            'settings' => 'nullable|array',
        ]);

        // Check if MFA of this type already exists
        $mfa = $employee->mfa()->where('type', $validated['type'])->first();

        if ($mfa) {
            $mfa->update([
                'is_enabled' => true,
                'verified_at' => now(),
                'secret' => $validated['secret'] ?? $mfa->secret,
                'settings' => $validated['settings'] ?? $mfa->settings,
            ]);
        } else {
            $mfa = $employee->mfa()->create([
                'type' => $validated['type'],
                'secret' => $validated['secret'] ?? null,
                'is_enabled' => true,
                'verified_at' => now(),
                'settings' => $validated['settings'] ?? null,
            ]);
        }

        // Generate backup codes for TOTP
        if ($validated['type'] === 'totp' && $request->boolean('generate_backup_codes', true)) {
            $this->generateBackupCodes($mfa);
        }

        return response()->json([
            'success' => true,
            'message' => 'MFA enabled successfully',
            'data' => $mfa->load('backupCodes')
        ]);
    }

    /**
     * Disable MFA for employee
     */
    public function disableMFA($id, $mfaId)
    {
        $employee = Employee::findOrFail($id);
        
        $mfa = $employee->mfa()->findOrFail($mfaId);
        $mfa->update(['is_enabled' => false]);

        return response()->json([
            'success' => true,
            'message' => 'MFA disabled successfully'
        ]);
    }

    /**
     * Generate MFA backup codes
     */
    public function generateBackupCodes($mfa)
    {
        // Delete old backup codes
        $mfa->backupCodes()->delete();

        // Generate new codes
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            $codes[] = $mfa->backupCodes()->create([
                'code' => $code,
                'expires_at' => now()->addMonths(6),
            ]);
        }

        return $codes;
    }

    /**
     * Regenerate MFA backup codes
     */
    public function regenerateBackupCodes($id, $mfaId)
    {
        $employee = Employee::findOrFail($id);
        $mfa = $employee->mfa()->findOrFail($mfaId);

        $codes = $this->generateBackupCodes($mfa);

        return response()->json([
            'success' => true,
            'message' => 'Backup codes regenerated successfully',
            'data' => $codes
        ]);
    }

    /**
     * Get employee activity log
     */
    public function getActivityLog($id)
    {
        $employee = Employee::findOrFail($id);

        $activities = [
            'last_login' => $employee->last_login_at,
            'recent_sessions' => $employee->sessions()
                ->orderBy('last_activity_at', 'desc')
                ->limit(10)
                ->get(['id', 'ip_address', 'user_agent', 'last_activity_at', 'created_at']),
            'password_resets' => $employee->passwordResetTokens()
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['created_at', 'expires_at', 'used_at']),
            'mfa_usage' => $employee->mfa()
                ->where('is_enabled', true)
                ->get(['type', 'last_used_at', 'verified_at']),
        ];

        return response()->json([
            'success' => true,
            'data' => $activities
        ]);
    }

    /**
     * Get employees by manager
     */
    public function getEmployeesByManager($managerId)
    {
        $manager = Employee::findOrFail($managerId);

        $employees = Employee::with(['store', 'role'])
            ->where('manager_id', $managerId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $employees,
            'manager' => $manager->only(['id', 'name', 'employee_code', 'department'])
        ]);
    }

    /**
     * Get employees by department
     */
    public function getEmployeesByDepartment($department)
    {
        $employees = Employee::with(['store', 'role', 'manager'])
            ->where('department', $department)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $stats = [
            'total' => $employees->count(),
            'active' => $employees->where('is_active', true)->count(),
            'in_service' => $employees->where('is_in_service', true)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $employees,
            'department' => $department,
            'stats' => $stats
        ]);
    }

    /**
     * Assign manager to employee
     */
    public function assignManager(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'manager_id' => 'required|exists:employees,id',
        ]);

        // Prevent self-assignment
        if ($validated['manager_id'] == $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Employee cannot be their own manager'
            ], 400);
        }

        // Prevent circular hierarchy
        $manager = Employee::findOrFail($validated['manager_id']);
        if ($manager->manager_id == $employee->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot create circular management hierarchy'
            ], 400);
        }

        $employee->update(['manager_id' => $validated['manager_id']]);

        return response()->json([
            'success' => true,
            'message' => 'Manager assigned successfully',
            'data' => $employee->load(['manager', 'store', 'role'])
        ]);
    }

    /**
     * Remove manager from employee
     */
    public function removeManager($id)
    {
        $employee = Employee::findOrFail($id);
        $employee->update(['manager_id' => null]);

        return response()->json([
            'success' => true,
            'message' => 'Manager removed successfully',
            'data' => $employee
        ]);
    }

    /**
     * Update employee salary
     */
    public function updateSalary(Request $request, $id)
    {
        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'salary' => 'required|numeric|min:0',
            'effective_date' => 'nullable|date',
            'reason' => 'nullable|string',
        ]);

        $oldSalary = $employee->salary;
        $employee->update(['salary' => $validated['salary']]);

        // Log salary change in metadata
        $metadata = $employee->metadata ?? [];
        $metadata['salary_history'] = $metadata['salary_history'] ?? [];
        $metadata['salary_history'][] = [
            'old_salary' => $oldSalary,
            'new_salary' => $validated['salary'],
            'effective_date' => $validated['effective_date'] ?? now()->toDateString(),
            'reason' => $validated['reason'] ?? null,
            'changed_by' => auth()->id(),
            'changed_at' => now()->toISOString(),
        ];
        $employee->update(['metadata' => $metadata]);

        return response()->json([
            'success' => true,
            'message' => 'Salary updated successfully',
            'data' => [
                'old_salary' => $oldSalary,
                'new_salary' => $validated['salary'],
                'employee' => $employee
            ]
        ]);
    }

    /**
     * Get employee hierarchy (organization chart)
     */
    public function getHierarchy($id)
    {
        $employee = Employee::with([
            'manager.manager',
            'subordinates.subordinates',
            'store',
            'role'
        ])->findOrFail($id);

        $hierarchy = [
            'employee' => $employee,
            'chain_of_command' => $this->getChainOfCommand($employee),
            'direct_reports' => $employee->subordinates,
            'all_subordinates' => $this->getAllSubordinates($employee),
        ];

        return response()->json([
            'success' => true,
            'data' => $hierarchy
        ]);
    }

    private function getChainOfCommand($employee)
    {
        $chain = [];
        $current = $employee->manager;
        
        while ($current) {
            $chain[] = $current->only(['id', 'name', 'employee_code', 'role_id', 'department']);
            $current = $current->manager;
        }

        return $chain;
    }

    private function getAllSubordinates($employee, &$subordinates = [])
    {
        foreach ($employee->subordinates as $subordinate) {
            $subordinates[] = $subordinate->only(['id', 'name', 'employee_code', 'department', 'role_id']);
            $this->getAllSubordinates($subordinate, $subordinates);
        }

        return $subordinates;
    }
}

