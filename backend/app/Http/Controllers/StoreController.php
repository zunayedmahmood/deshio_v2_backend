<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreController extends Controller
{
    use DatabaseAgnosticSearch;
    public function createStore(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'address' => 'nullable|string',
            'pathao_key' => 'nullable|string|max:100',
            'is_warehouse' => 'boolean',
            'is_online' => 'boolean',
            'phone' => 'nullable|string',
            'email' => 'nullable|email|unique:stores,email',
            'contact_person' => 'nullable|string',
            'store_code' => 'nullable|string|unique:stores,store_code',
            'description' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'capacity' => 'nullable|integer|min:0',
            'opening_hours' => 'nullable|array',
            'opening_hours.*.day' => 'required_with:opening_hours|string',
            'opening_hours.*.open' => 'required_with:opening_hours|string',
            'opening_hours.*.close' => 'required_with:opening_hours|string',
        ]);

        // Auto-sync: pathao_key is actually the store ID, keep both columns same
        if (isset($validated['pathao_key'])) {
            $validated['pathao_store_id'] = $validated['pathao_key'];
        }

        $store = Store::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Store created successfully',
            'data' => $store
        ], 201);
    }

    public function updateStore(Request $request, $id)
    {
        $store = Store::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'address' => 'nullable|string',
            'pathao_key' => 'nullable|string|max:100',
            'is_warehouse' => 'boolean',
            'is_online' => 'boolean',
            'phone' => 'nullable|string',
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('stores')->ignore($store->id)],
            'contact_person' => 'nullable|string',
            'store_code' => ['sometimes', 'nullable', 'string', Rule::unique('stores')->ignore($store->id)],
            'description' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'capacity' => 'nullable|integer|min:0',
            'opening_hours' => 'nullable|array',
            'opening_hours.*.day' => 'required_with:opening_hours|string',
            'opening_hours.*.open' => 'required_with:opening_hours|string',
            'opening_hours.*.close' => 'required_with:opening_hours|string',
        ]);

        // Auto-sync: pathao_key is actually the store ID, keep both columns same
        if (isset($validated['pathao_key'])) {
            $validated['pathao_store_id'] = $validated['pathao_key'];
        }

        $store->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Store updated successfully',
            'data' => $store
        ]);
    }

    public function deleteStore($id)
    {
        $store = Store::findOrFail($id);

        // Soft delete by setting is_active to false
        $store->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Store deactivated successfully'
        ]);
    }

    public function activateStore($id)
    {
        $store = Store::findOrFail($id);

        $store->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Store activated successfully',
            'data' => $store
        ]);
    }

    public function deactivateStore($id)
    {
        $store = Store::findOrFail($id);

        $store->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Store deactivated successfully'
        ]);
    }

    public function getStores(Request $request)
    {
        $query = Store::query();

        // Filters
        if ($request->has('is_warehouse')) {
            $query->where('is_warehouse', $request->boolean('is_warehouse'));
        }

        if ($request->has('is_online')) {
            $query->where('is_online', $request->boolean('is_online'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('type')) {
            switch ($request->type) {
                case 'warehouse':
                    $query->warehouses();
                    break;
                case 'online':
                    $query->onlineStores();
                    break;
                case 'physical':
                    $query->physicalStores();
                    break;
            }
        }

        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['name', 'email', 'store_code', 'phone', 'contact_person'], $search);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        $allowedSortFields = ['name', 'email', 'store_code', 'created_at', 'capacity'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        $stores = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $stores
        ]);
    }

    public function getStore($id)
    {
        $store = Store::with([
            'activeEmployees' => function($query) {
                $query->select('id', 'name', 'email', 'store_id')->limit(10);
            },
            'availableProductBatches' => function($query) {
                $query->with('product')->limit(10);
            }
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $store
        ]);
    }

    public function getStoresByType($type)
    {
        $query = Store::where('is_active', true);

        switch ($type) {
            case 'warehouse':
                $query->warehouses();
                break;
            case 'online':
                $query->onlineStores();
                break;
            case 'physical':
                $query->physicalStores();
                break;
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid store type. Use: warehouse, online, or physical'
                ], 400);
        }

        $stores = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $stores,
            'type' => $type
        ]);
    }

    public function getStoreStats()
    {
        $stats = [
            'total_stores' => Store::count(),
            'active_stores' => Store::where('is_active', true)->count(),
            'inactive_stores' => Store::where('is_active', false)->count(),
            'warehouses' => Store::warehouses()->where('is_active', true)->count(),
            'online_stores' => Store::onlineStores()->where('is_active', true)->count(),
            'physical_stores' => Store::physicalStores()->where('is_active', true)->count(),
            'total_capacity' => Store::where('is_active', true)->whereNotNull('capacity')->sum('capacity'),
            'stores_with_coordinates' => Store::where('is_active', true)->whereNotNull('latitude')->whereNotNull('longitude')->count(),
            'recent_stores' => Store::orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['name', 'store_code', 'is_warehouse', 'is_online', 'created_at'])
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'store_ids' => 'required|array',
            'store_ids.*' => 'exists:stores,id',
            'is_active' => 'required|boolean',
        ]);

        $count = Store::whereIn('id', $validated['store_ids'])
            ->update(['is_active' => $validated['is_active']]);

        return response()->json([
            'success' => true,
            'message' => "Updated {$count} stores successfully"
        ]);
    }

    public function getStoreInventory($id)
    {
        $store = Store::findOrFail($id);

        $inventory = $store->availableProductBatches()
            ->with(['product', 'productBatchItems'])
            ->get()
            ->groupBy('product.name')
            ->map(function($batches, $productName) {
                $totalQuantity = $batches->sum(function($batch) {
                    return $batch->productBatchItems->sum('quantity');
                });

                return [
                    'product_name' => $productName,
                    'total_quantity' => $totalQuantity,
                    'batches_count' => $batches->count(),
                    'batches' => $batches->map(function($batch) {
                        return [
                            'id' => $batch->id,
                            'batch_number' => $batch->batch_number,
                            'quantity' => $batch->productBatchItems->sum('quantity'),
                            'expiry_date' => $batch->expiry_date,
                        ];
                    })
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'store' => $store->only(['id', 'name', 'store_code']),
                'inventory' => $inventory
            ]
        ]);
    }
}
