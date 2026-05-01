<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PermissionController extends Controller
{
    use DatabaseAgnosticSearch;
    public function index(Request $request)
    {
        $query = Permission::query();

        if ($request->has('is_active')) {
            $query->active();
        }

        if ($request->has('module')) {
            $query->byModule($request->module);
        }

        if ($request->has('guard_name')) {
            $query->byGuard($request->guard_name);
        }

        $permissions = $query->orderBy('module')->orderBy('title')->get();

        return response()->json(['success' => true, 'data' => $permissions]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'module' => 'required|string|max:100',
            'guard_name' => 'required|in:api,web',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $request->all();
        $data['slug'] = Str::slug($request->module . '-' . $request->title);

        $permission = Permission::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Permission created successfully',
            'data' => $permission
        ], 201);
    }

    public function show($id)
    {
        $permission = Permission::findOrFail($id);

        return response()->json(['success' => true, 'data' => $permission]);
    }

    public function update(Request $request, $id)
    {
        $permission = Permission::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'module' => 'sometimes|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $request->except(['slug', 'guard_name']);
        if ($request->has('module') || $request->has('title')) {
            $module = $request->get('module', $permission->module);
            $title = $request->get('title', $permission->title);
            $data['slug'] = Str::slug($module . '-' . $title);
        }

        $permission->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Permission updated successfully',
            'data' => $permission
        ]);
    }

    public function destroy($id)
    {
        $permission = Permission::findOrFail($id);
        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permission deleted successfully'
        ]);
    }

    public function getByModule()
    {
        $stringAggSql = $this->getStringAggregateSql('title', ',');
        $permissions = Permission::active()
            ->selectRaw("module, {$stringAggSql} as permissions, COUNT(*) as count")
            ->groupBy('module')
            ->orderBy('module')
            ->get();

        return response()->json(['success' => true, 'data' => $permissions]);
    }

    public function getStatistics()
    {
        $stats = [
            'total_permissions' => Permission::count(),
            'active_permissions' => Permission::active()->count(),
            'by_module' => Permission::selectRaw('module, COUNT(*) as count')
                ->groupBy('module')
                ->pluck('count', 'module'),
            'by_guard' => Permission::selectRaw('guard_name, COUNT(*) as count')
                ->groupBy('guard_name')
                ->pluck('count', 'guard_name'),
        ];

        return response()->json(['success' => true, 'data' => $stats]);
    }
}

