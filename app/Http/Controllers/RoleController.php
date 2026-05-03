<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * RoleController — Deshio V2 RBAC Refactor (2026-03-30)
 *
 * Manages the simplified 7-role set. Permission management endpoints have been
 * removed because access control is now handled entirely on the frontend via
 * role slugs. The `role_permissions` table is kept in the database for backwards
 * compatibility but is no longer populated or queried for authorization logic.
 */
class RoleController extends Controller
{
    /**
     * List all roles.
     * Supports optional ?is_active=1|0 filter.
     */
    public function index(Request $request)
    {
        $query = Role::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $roles = $query->ordered()->get();

        return response()->json(['success' => true, 'data' => $roles]);
    }

    /**
     * Create a new role.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255|unique:roles,title',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'is_default'  => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data           = $validator->validated();
        $data['slug']   = Str::slug($request->title);
        $data['guard_name'] = 'api';

        // Prevent duplicate slugs
        if (Role::where('slug', $data['slug'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A role with a similar name (slug) already exists.',
            ], 422);
        }

        $role = Role::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully',
            'data'    => $role,
        ], 201);
    }

    /**
     * Get a single role.
     */
    public function show($id)
    {
        $role = Role::findOrFail($id);

        return response()->json(['success' => true, 'data' => $role]);
    }

    /**
     * Update a role's title, description, or status flags.
     * The `slug` and `guard_name` are never updated to avoid breaking references.
     */
    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'is_default'  => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Regenerate slug if title changes, but only for custom roles (allow
        // system roles to retain their canonical slugs).
        if (isset($data['title']) && $data['title'] !== $role->title) {
            $systemSlugs = ['super-admin', 'admin', 'branch-manager', 'online-moderator', 'pos-salesman', 'employee'];
            if (!in_array($role->slug, $systemSlugs)) {
                $data['slug'] = Str::slug($data['title']);
            }
        }

        $role->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'data'    => $role,
        ]);
    }

    /**
     * Delete a role — only allowed if no employees are assigned to it.
     */
    public function destroy($id)
    {
        $role = Role::findOrFail($id);

        // Prevent deleting system roles
        $systemSlugs = ['super-admin', 'admin', 'branch-manager', 'online-moderator', 'pos-salesman', 'employee'];
        if (in_array($role->slug, $systemSlugs)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete the system role \"{$role->title}\". Deactivate it instead.",
            ], 400);
        }

        $employeeCount = $role->employees()->count();
        if ($employeeCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete role assigned to {$employeeCount} employee(s). Re-assign them first.",
            ], 400);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role deleted successfully',
        ]);
    }

    /**
     * Basic role statistics.
     */
    public function getStatistics()
    {
        $stats = [
            'total_roles'    => Role::count(),
            'active_roles'   => Role::where('is_active', true)->count(),
            'inactive_roles' => Role::where('is_active', false)->count(),
            'by_role'        => Role::withCount('employees')
                ->orderBy('title')
                ->get(['id', 'title', 'slug', 'is_active'])
                ->map(fn ($r) => [
                    'id'             => $r->id,
                    'title'          => $r->title,
                    'slug'           => $r->slug,
                    'is_active'      => $r->is_active,
                    'employee_count' => $r->employees_count,
                ]),
        ];

        return response()->json(['success' => true, 'data' => $stats]);
    }
}
