<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Models\AdCampaignProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AdCampaignController extends Controller
{
    /**
     * List campaigns with filters
     * 
     * GET /api/ad-campaigns
     */
    public function index(Request $request)
    {
        $query = AdCampaign::with(['createdBy', 'targetedProducts.product'])
            ->orderBy('created_at', 'desc');
        
        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Filter by platform
        if ($request->filled('platform')) {
            $query->where('platform', $request->platform);
        }
        
        // Search by campaign name
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        
        // Date range filter
        if ($request->filled('from')) {
            $query->where('starts_at', '>=', $request->from);
        }
        
        if ($request->filled('to')) {
            $query->where('starts_at', '<=', $request->to);
        }
        
        $campaigns = $query->paginate($request->get('per_page', 15));
        
        return response()->json([
            'success' => true,
            'data' => $campaigns,
        ]);
    }
    
    /**
     * Create campaign
     * 
     * POST /api/ad-campaigns
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'platform' => 'required|in:facebook,instagram,google,tiktok,youtube,other',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'budget_type' => 'nullable|in:DAILY,LIFETIME',
            'budget_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $campaign = AdCampaign::create([
            'name' => $request->name,
            'platform' => $request->platform,
            'starts_at' => $request->starts_at,
            'ends_at' => $request->ends_at,
            'budget_type' => $request->budget_type,
            'budget_amount' => $request->budget_amount,
            'notes' => $request->notes,
            'status' => 'DRAFT',
            'created_by' => auth()->id(),
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Campaign created successfully',
            'data' => $campaign->load('createdBy'),
        ], 201);
    }
    
    /**
     * Show campaign
     * 
     * GET /api/ad-campaigns/{id}
     */
    public function show($id)
    {
        $campaign = AdCampaign::with([
            'createdBy',
            'updatedBy',
            'targetedProducts.product',
            'targetedProducts.createdBy'
        ])->find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => $campaign,
        ]);
    }
    
    /**
     * Update campaign
     * 
     * PUT /api/ad-campaigns/{id}
     */
    public function update(Request $request, $id)
    {
        $campaign = AdCampaign::find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'platform' => 'sometimes|in:facebook,instagram,google,tiktok,youtube,other',
            'starts_at' => 'sometimes|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'budget_type' => 'nullable|in:DAILY,LIFETIME',
            'budget_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $updateData = [];
        if ($request->has('name')) $updateData['name'] = $request->name;
        if ($request->has('platform')) $updateData['platform'] = $request->platform;
        if ($request->has('starts_at')) $updateData['starts_at'] = $request->starts_at;
        if ($request->has('ends_at')) $updateData['ends_at'] = $request->ends_at;
        if ($request->has('budget_type')) $updateData['budget_type'] = $request->budget_type;
        if ($request->has('budget_amount')) $updateData['budget_amount'] = $request->budget_amount;
        if ($request->has('notes')) $updateData['notes'] = $request->notes;
        $updateData['updated_by'] = auth()->id();
        
        $campaign->update($updateData);
        
        return response()->json([
            'success' => true,
            'message' => 'Campaign updated successfully',
            'data' => $campaign->fresh(['createdBy', 'updatedBy']),
        ]);
    }
    
    /**
     * Change campaign status
     * 
     * PATCH /api/ad-campaigns/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $campaign = AdCampaign::find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:DRAFT,RUNNING,PAUSED,ENDED',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $newStatus = $request->status;
        
        // Validate status transition
        if (!$campaign->canTransitionTo($newStatus)) {
            return response()->json([
                'success' => false,
                'message' => "Cannot transition from {$campaign->status} to {$newStatus}"
            ], 422);
        }
        
        $campaign->status = $newStatus;
        $campaign->updated_by = auth()->id();
        $campaign->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Campaign status updated successfully',
            'data' => $campaign,
        ]);
    }
    
    /**
     * Delete campaign
     * 
     * DELETE /api/ad-campaigns/{id}
     */
    public function destroy($id)
    {
        $campaign = AdCampaign::find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        // Only allow deletion of DRAFT campaigns
        if ($campaign->status !== 'DRAFT') {
            return response()->json([
                'success' => false,
                'message' => 'Can only delete DRAFT campaigns. Set status to ENDED instead.'
            ], 422);
        }
        
        $campaign->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Campaign deleted successfully',
        ]);
    }
    
    /**
     * Add products to campaign
     * 
     * POST /api/ad-campaigns/{id}/products
     */
    public function addProducts(Request $request, $id)
    {
        $campaign = AdCampaign::find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'required|exists:products,id',
            'effective_from' => 'nullable|date',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $effectiveFrom = $request->effective_from 
            ? new \DateTime($request->effective_from) 
            : now();
        
        // Validate effective_from is not before campaign starts
        if ($effectiveFrom < $campaign->starts_at) {
            return response()->json([
                'success' => false,
                'message' => 'effective_from cannot be before campaign starts_at'
            ], 422);
        }
        
        $added = [];
        $skipped = [];
        
        DB::transaction(function() use ($campaign, $request, $effectiveFrom, &$added, &$skipped) {
            foreach ($request->product_ids as $productId) {
                // Check if already exists with no effective_to (still active)
                $existing = AdCampaignProduct::where('campaign_id', $campaign->id)
                    ->where('product_id', $productId)
                    ->whereNull('effective_to')
                    ->first();
                
                if ($existing) {
                    $skipped[] = $productId;
                    continue;
                }
                
                $mapping = AdCampaignProduct::create([
                    'campaign_id' => $campaign->id,
                    'product_id' => $productId,
                    'effective_from' => $effectiveFrom,
                    'created_by' => auth()->id(),
                ]);
                
                $added[] = $mapping->load('product');
            }
        });
        
        return response()->json([
            'success' => true,
            'message' => count($added) . ' product(s) added, ' . count($skipped) . ' skipped (already exists)',
            'data' => [
                'added' => $added,
                'skipped_product_ids' => $skipped,
            ],
        ]);
    }
    
    /**
     * List targeted products
     * 
     * GET /api/ad-campaigns/{id}/products
     */
    public function listProducts($id)
    {
        $campaign = AdCampaign::find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        $products = $campaign->targetedProducts()
            ->with(['product', 'createdBy'])
            ->orderBy('effective_from', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }
    
    /**
     * Remove product from campaign (soft remove via effective_to)
     * 
     * DELETE /api/ad-campaigns/{id}/products/{mappingId}
     */
    public function removeProduct($id, $mappingId)
    {
        $campaign = AdCampaign::find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        $mapping = AdCampaignProduct::where('campaign_id', $campaign->id)
            ->where('id', $mappingId)
            ->first();
        
        if (!$mapping) {
            return response()->json([
                'success' => false,
                'message' => 'Product mapping not found'
            ], 404);
        }
        
        // Set effective_to to now (soft remove)
        $mapping->effective_to = now();
        $mapping->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Product removed from campaign',
            'data' => $mapping,
        ]);
    }
}
