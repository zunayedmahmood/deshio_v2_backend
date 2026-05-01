<?php

namespace App\Http\Controllers;

use App\Models\InventoryRebalancing;
use App\Models\ProductBatch;
use App\Models\Product;
use App\Models\Store;
use App\Models\ProductDispatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class InventoryRebalancingController extends Controller
{
    /**
     * Get all rebalancing requests
     */
    public function index(Request $request)
    {
        try {
            $query = InventoryRebalancing::with([
                'product',
                'sourceStore',
                'destinationStore',
                'requestedBy',
                'approvedBy',
                'dispatch'
            ]);

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by store
            if ($request->has('store_id')) {
                $query->where(function ($q) use ($request) {
                    $q->where('source_store_id', $request->store_id)
                      ->orWhere('destination_store_id', $request->store_id);
                });
            }

            // Filter by product
            if ($request->has('product_id')) {
                $query->where('product_id', $request->product_id);
            }

            $rebalancings = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $rebalancings,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rebalancing requests: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get rebalancing suggestions based on stock levels
     */
    public function getSuggestions(Request $request)
    {
        try {
            // Find products with uneven distribution across stores
            $products = Product::with(['productBatches' => function ($query) {
                $query->where('quantity', '>', 0)->with('store');
            }])->get();

            $suggestions = [];

            foreach ($products as $product) {
                $batches = $product->productBatches;
                
                if ($batches->count() < 2) continue;

                // Find overstocked and understocked stores
                $storeInventories = $batches->groupBy('store_id')->map(function ($storeBatches) {
                    return [
                        'store' => $storeBatches->first()->store,
                        'quantity' => $storeBatches->sum('quantity'),
                        'reorder_level' => $storeBatches->avg('reorder_level'),
                    ];
                });

                $averageStock = $storeInventories->avg('quantity');

                foreach ($storeInventories as $storeId => $inventory) {
                    // Overstocked: has more than 2x average
                    if ($inventory['quantity'] > $averageStock * 2) {
                        // Find understocked stores (below reorder level)
                        $understocked = $storeInventories->filter(function ($inv) {
                            return $inv['quantity'] < $inv['reorder_level'];
                        });

                        foreach ($understocked as $destStoreId => $destInventory) {
                            if ($storeId != $destStoreId) {
                                $suggestedQuantity = min(
                                    $inventory['quantity'] - $averageStock,
                                    $destInventory['reorder_level'] - $destInventory['quantity']
                                );

                                if ($suggestedQuantity > 0) {
                                    $suggestions[] = [
                                        'product_id' => $product->id,
                                        'product_name' => $product->name,
                                        'sku' => $product->sku,
                                        'from_store_id' => $inventory['store']->id,
                                        'from_store_name' => $inventory['store']->name,
                                        'from_store_quantity' => $inventory['quantity'],
                                        'to_store_id' => $destInventory['store']->id,
                                        'to_store_name' => $destInventory['store']->name,
                                        'to_store_quantity' => $destInventory['quantity'],
                                        'to_store_reorder_level' => $destInventory['reorder_level'],
                                        'suggested_quantity' => round($suggestedQuantity),
                                        'reason' => 'Rebalance: Source overstocked, Destination understocked',
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_suggestions' => count($suggestions),
                    'suggestions' => $suggestions,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate suggestions: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new rebalancing request
     */
    public function create(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'source_store_id' => 'required|exists:stores,id',
            'source_batch_id' => 'nullable|exists:product_batches,id',
            'destination_store_id' => 'required|exists:stores,id|different:source_store_id',
            'quantity' => 'required|integer|min:1',
            'reason' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
        ]);

        DB::beginTransaction();
        try {
            // Verify source store has enough quantity
            $query = ProductBatch::where('product_id', $request->product_id)
                ->where('store_id', $request->source_store_id)
                ->where('quantity', '>', 0);
            
            if ($request->source_batch_id) {
                $query->where('id', $request->source_batch_id);
            }
            
            $sourceQuantity = $query->sum('quantity');

            if ($sourceQuantity < $request->quantity) {
                throw new \Exception("Source store doesn't have enough quantity. Available: {$sourceQuantity}");
            }

            $rebalancing = InventoryRebalancing::create([
                'product_id' => $request->product_id,
                'source_store_id' => $request->source_store_id,
                'source_batch_id' => $request->source_batch_id,
                'destination_store_id' => $request->destination_store_id,
                'quantity' => $request->quantity,
                'reason' => $request->reason,
                'priority' => $request->priority ?? 'medium',
                'status' => 'pending',
                'requested_by' => Auth::id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rebalancing request created successfully',
                'data' => $rebalancing->load(['product', 'sourceStore', 'destinationStore']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create rebalancing request: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve and execute a rebalancing request
     */
    public function approve(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $rebalancing = InventoryRebalancing::findOrFail($id);

            if ($rebalancing->status !== 'pending') {
                throw new \Exception('Only pending rebalancing requests can be approved');
            }

            // Create a product dispatch to execute the rebalancing
            $dispatch = ProductDispatch::create([
                'source_store_id' => $rebalancing->source_store_id,
                'destination_store_id' => $rebalancing->destination_store_id,
                'dispatch_date' => now(),
                'status' => 'pending',
                'created_by' => Auth::id(),  // Set the employee approving the rebalancing
                'notes' => "Inventory Rebalancing: {$rebalancing->reason}",
            ]);

            // Update rebalancing
            $rebalancing->update([
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'dispatch_id' => $dispatch->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rebalancing approved. Product dispatch created.',
                'data' => $rebalancing->load(['dispatch']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve rebalancing: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a rebalancing request
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            $rebalancing = InventoryRebalancing::findOrFail($id);

            if ($rebalancing->status !== 'pending') {
                throw new \Exception('Only pending rebalancing requests can be rejected');
            }

            $rebalancing->update([
                'status' => 'rejected',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'reason' => $rebalancing->reason . ' | Rejected: ' . $request->rejection_reason,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rebalancing request rejected',
                'data' => $rebalancing,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject rebalancing: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel a rebalancing request
     */
    public function cancel($id)
    {
        DB::beginTransaction();
        try {
            $rebalancing = InventoryRebalancing::findOrFail($id);

            if (!in_array($rebalancing->status, ['pending', 'approved'])) {
                throw new \Exception('Only pending or approved rebalancing requests can be cancelled');
            }

            $rebalancing->update(['status' => 'cancelled']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rebalancing request cancelled',
                'data' => $rebalancing,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel rebalancing: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark rebalancing as completed
     */
    public function complete($id)
    {
        DB::beginTransaction();
        try {
            $rebalancing = InventoryRebalancing::with('dispatch')->findOrFail($id);

            if ($rebalancing->status !== 'approved') {
                throw new \Exception('Only approved rebalancing requests can be completed');
            }

            // Check if the dispatch is completed
            if ($rebalancing->dispatch && $rebalancing->dispatch->status !== 'delivered') {
                throw new \Exception('Dispatch must be delivered before completing rebalancing');
            }

            $rebalancing->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Rebalancing completed successfully',
                'data' => $rebalancing,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete rebalancing: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get statistics for rebalancing operations
     */
    public function getStatistics(Request $request)
    {
        try {
            $total = InventoryRebalancing::count();
            $pending = InventoryRebalancing::where('status', 'pending')->count();
            $approved = InventoryRebalancing::where('status', 'approved')->count();
            $completed = InventoryRebalancing::where('status', 'completed')->count();
            $rejected = InventoryRebalancing::where('status', 'rejected')->count();
            $cancelled = InventoryRebalancing::where('status', 'cancelled')->count();

            // Recent rebalancing activity
            $recentActivity = InventoryRebalancing::with(['product', 'sourceStore', 'destinationStore'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'by_status' => [
                        'pending' => $pending,
                        'approved' => $approved,
                        'completed' => $completed,
                        'rejected' => $rejected,
                        'cancelled' => $cancelled,
                    ],
                    'recent_activity' => $recentActivity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage(),
            ], 500);
        }
    }
}
