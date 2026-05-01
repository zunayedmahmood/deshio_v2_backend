<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceOrder;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ServiceController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * Get all services with filters
     */
    public function index(Request $request)
    {
        $query = Service::query();

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // Filter by featured
        if ($request->has('is_featured')) {
            $query->where('is_featured', $request->is_featured);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['name', 'description', 'service_code'], $search);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');
        $query->orderBy($sortBy, $sortDirection);

        $perPage = $request->get('per_page', 15);
        $services = $query->paginate($perPage);

        return response()->json($services);
    }

    /**
     * Create a new service
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'base_price' => 'required|numeric|min:0',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'pricing_type' => 'required|in:fixed,variable,per_unit',
            'estimated_duration' => 'nullable|integer|min:0',
            'unit' => 'nullable|string|max:50',
            'min_quantity' => 'nullable|integer|min:0',
            'max_quantity' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'requires_approval' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'images' => 'nullable|array',
            'icon' => 'nullable|string',
            'options' => 'nullable|array',
            'requirements' => 'nullable|array',
            'instructions' => 'nullable|string',
            'metadata' => 'nullable|array',
            'sort_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $service = Service::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Service created successfully',
            'data' => $service
        ], 201);
    }

    /**
     * Get a single service
     */
    public function show($id)
    {
        $service = Service::with(['fields'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $service
        ]);
    }

    /**
     * Update a service
     */
    public function update(Request $request, $id)
    {
        $service = Service::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'base_price' => 'sometimes|numeric|min:0',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'pricing_type' => 'sometimes|in:fixed,variable,per_unit',
            'estimated_duration' => 'nullable|integer|min:0',
            'unit' => 'nullable|string|max:50',
            'min_quantity' => 'nullable|integer|min:0',
            'max_quantity' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'requires_approval' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'images' => 'nullable|array',
            'icon' => 'nullable|string',
            'options' => 'nullable|array',
            'requirements' => 'nullable|array',
            'instructions' => 'nullable|string',
            'metadata' => 'nullable|array',
            'sort_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $service->update($request->except(['service_code']));

        return response()->json([
            'success' => true,
            'message' => 'Service updated successfully',
            'data' => $service
        ]);
    }

    /**
     * Delete a service
     */
    public function destroy($id)
    {
        $service = Service::findOrFail($id);
        
        // Check if service has orders
        $orderCount = ServiceOrder::where('service_id', $id)->count();
        if ($orderCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete service with existing orders. Consider deactivating instead.'
            ], 400);
        }

        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service deleted successfully'
        ]);
    }

    /**
     * Hard delete a service (force delete)
     * 
     * DELETE /api/services/{id}/force
     */
    public function forceDestroy($id)
    {
        $service = Service::findOrFail($id);
        
        // Check if service has orders
        $orderCount = ServiceOrder::where('service_id', $id)->count();
        if ($orderCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot force delete service. It has {$orderCount} order(s) associated with it.",
                'order_count' => $orderCount
            ], 400);
        }

        $serviceName = $service->name;
        $serviceCode = $service->service_code;
        
        // Delete related service fields
        $service->serviceFields()->delete();
        
        // Detach field relationships
        $service->fields()->detach();
        
        // Force delete the service
        $service->forceDelete();

        return response()->json([
            'success' => true,
            'message' => "Service '{$serviceName}' (Code: {$serviceCode}) permanently deleted",
            'deleted_service' => [
                'name' => $serviceName,
                'code' => $serviceCode
            ]
        ]);
    }

    /**
     * Bulk delete services (hard delete)
     * 
     * POST /api/services/bulk-delete
     * Body: {
     *   "service_ids": [1, 2, 3],
     *   "force": true
     * }
     */
    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service_ids' => 'required|array|min:1',
            'service_ids.*' => 'exists:services,id',
            'force' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $serviceIds = $request->service_ids;
        $force = $request->boolean('force', false);
        
        $results = [
            'deleted' => [],
            'failed' => [],
            'summary' => [
                'total_requested' => count($serviceIds),
                'deleted_count' => 0,
                'failed_count' => 0
            ]
        ];

        foreach ($serviceIds as $serviceId) {
            try {
                $service = Service::findOrFail($serviceId);
                
                // Check for orders
                $orderCount = ServiceOrder::where('service_id', $serviceId)->count();
                
                if ($orderCount > 0) {
                    $results['failed'][] = [
                        'id' => $serviceId,
                        'name' => $service->name,
                        'code' => $service->service_code,
                        'reason' => "Has {$orderCount} order(s)"
                    ];
                    $results['summary']['failed_count']++;
                    continue;
                }
                
                $serviceName = $service->name;
                $serviceCode = $service->service_code;
                
                if ($force) {
                    // Hard delete
                    $service->serviceFields()->delete();
                    $service->fields()->detach();
                    $service->forceDelete();
                } else {
                    // Soft delete
                    $service->delete();
                }
                
                $results['deleted'][] = [
                    'id' => $serviceId,
                    'name' => $serviceName,
                    'code' => $serviceCode,
                    'type' => $force ? 'permanent' : 'soft'
                ];
                $results['summary']['deleted_count']++;
                
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'id' => $serviceId,
                    'reason' => $e->getMessage()
                ];
                $results['summary']['failed_count']++;
            }
        }

        return response()->json([
            'success' => $results['summary']['deleted_count'] > 0,
            'message' => "Deleted {$results['summary']['deleted_count']} service(s), {$results['summary']['failed_count']} failed",
            'data' => $results
        ]);
    }

    /**
     * Activate a service
     */
    public function activate($id)
    {
        $service = Service::findOrFail($id);
        $service->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Service activated successfully',
            'data' => $service
        ]);
    }

    /**
     * Deactivate a service
     */
    public function deactivate($id)
    {
        $service = Service::findOrFail($id);
        $service->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Service deactivated successfully',
            'data' => $service
        ]);
    }

    /**
     * Get service statistics
     */
    public function getStatistics(Request $request)
    {
        $stats = [
            'total_services' => Service::count(),
            'active_services' => Service::where('is_active', true)->count(),
            'inactive_services' => Service::where('is_active', false)->count(),
            'featured_services' => Service::where('is_featured', true)->count(),
            'by_category' => Service::selectRaw('category, COUNT(*) as count')
                ->whereNotNull('category')
                ->groupBy('category')
                ->pluck('count', 'category'),
            'by_pricing_type' => Service::selectRaw('pricing_type, COUNT(*) as count')
                ->groupBy('pricing_type')
                ->pluck('count', 'pricing_type'),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get active services only
     */
    public function getActiveServices(Request $request)
    {
        $services = Service::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $services
        ]);
    }

    /**
     * Get services by category
     */
    public function getByCategory($category)
    {
        $services = Service::where('category', $category)
            ->where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $services
        ]);
    }

    /**
     * Bulk update service status
     */
    public function bulkUpdateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'service_ids' => 'required|array',
            'service_ids.*' => 'exists:services,id',
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        Service::whereIn('id', $request->service_ids)
            ->update(['is_active' => $request->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Services updated successfully'
        ]);
    }

    /**
     * Reorder services
     */
    public function reorder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'services' => 'required|array',
            'services.*.id' => 'required|exists:services,id',
            'services.*.sort_order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        foreach ($request->services as $serviceData) {
            Service::where('id', $serviceData['id'])
                ->update(['sort_order' => $serviceData['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Services reordered successfully'
        ]);
    }
}

