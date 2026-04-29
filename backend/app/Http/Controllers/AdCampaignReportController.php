<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Models\OrderItemCampaignCredit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AdCampaignReportController extends Controller
{
    /**
     * Campaign Leaderboard
     * 
     * GET /api/ad-campaigns/reports/leaderboard
     */
    public function leaderboard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'mode' => 'required|in:FULL,SPLIT',
            'sort' => 'nullable|in:revenue,profit,units,orders',
            'platform' => 'nullable|in:facebook,instagram,google,tiktok,youtube,other',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $mode = $request->mode;
        $sortBy = $request->sort ?? 'revenue';
        
        $query = OrderItemCampaignCredit::active()
            ->inDateRange($request->from, $request->to)
            ->where('credit_mode', $mode)
            ->select([
                'campaign_id',
                DB::raw('SUM(credited_qty) as total_units'),
                DB::raw('SUM(credited_revenue) as total_revenue'),
                DB::raw('SUM(credited_profit) as total_profit'),
                DB::raw('COUNT(DISTINCT order_id) as order_count'),
            ])
            ->groupBy('campaign_id');
        
        // Sort
        $sortColumn = match($sortBy) {
            'revenue' => 'total_revenue',
            'profit' => 'total_profit',
            'units' => 'total_units',
            'orders' => 'order_count',
            default => 'total_revenue',
        };
        
        $query->orderBy($sortColumn, 'desc');
        
        $results = $query->get();
        
        // Enrich with campaign details
        $campaigns = $results->map(function($result) {
            $campaign = AdCampaign::with('createdBy')->find($result->campaign_id);
            
            if (!$campaign) {
                return null;
            }
            
            return [
                'campaign_id' => $result->campaign_id,
                'campaign_name' => $campaign->name,
                'platform' => $campaign->platform,
                'status' => $campaign->status,
                'units' => (float) $result->total_units,
                'revenue' => (float) $result->total_revenue,
                'profit' => (float) $result->total_profit,
                'order_count' => (int) $result->order_count,
                'avg_order_value' => $result->order_count > 0 
                    ? round($result->total_revenue / $result->order_count, 2) 
                    : 0,
            ];
        })->filter(); // Remove null campaigns
        
        // Filter by platform if requested
        if ($request->filled('platform')) {
            $campaigns = $campaigns->where('platform', $request->platform)->values();
        }
        
        return response()->json([
            'success' => true,
            'mode' => $mode,
            'date_range' => [
                'from' => $request->from,
                'to' => $request->to,
            ],
            'data' => $campaigns,
        ]);
    }
    
    /**
     * Campaign Summary
     * 
     * GET /api/ad-campaigns/{id}/reports/summary
     */
    public function summary(Request $request, $id)
    {
        $campaign = AdCampaign::find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'mode' => 'required|in:FULL,SPLIT',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $stats = OrderItemCampaignCredit::active()
            ->byCampaign($id)
            ->inDateRange($request->from, $request->to)
            ->where('credit_mode', $request->mode)
            ->select([
                DB::raw('SUM(credited_qty) as total_units'),
                DB::raw('SUM(credited_revenue) as total_revenue'),
                DB::raw('SUM(credited_profit) as total_profit'),
                DB::raw('COUNT(DISTINCT order_id) as order_count'),
                DB::raw('COUNT(DISTINCT order_item_id) as item_count'),
            ])
            ->first();
        
        return response()->json([
            'success' => true,
            'data' => [
                'campaign' => $campaign,
                'metrics' => [
                    'units' => (float) ($stats->total_units ?? 0),
                    'revenue' => (float) ($stats->total_revenue ?? 0),
                    'profit' => (float) ($stats->total_profit ?? 0),
                    'order_count' => (int) ($stats->order_count ?? 0),
                    'item_count' => (int) ($stats->item_count ?? 0),
                    'avg_order_value' => $stats->order_count > 0 
                        ? round($stats->total_revenue / $stats->order_count, 2) 
                        : 0,
                ],
                'mode' => $request->mode,
                'date_range' => [
                    'from' => $request->from,
                    'to' => $request->to,
                ],
            ],
        ]);
    }
    
    /**
     * Product Breakdown
     * 
     * GET /api/ad-campaigns/{id}/reports/products
     */
    public function productBreakdown(Request $request, $id)
    {
        $campaign = AdCampaign::find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'mode' => 'required|in:FULL,SPLIT',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $results = OrderItemCampaignCredit::active()
            ->byCampaign($id)
            ->inDateRange($request->from, $request->to)
            ->where('credit_mode', $request->mode)
            ->join('order_items', 'order_item_campaign_credits.order_item_id', '=', 'order_items.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->select([
                'products.id as product_id',
                'products.name as product_name',
                'products.sku as product_sku',
                DB::raw('SUM(order_item_campaign_credits.credited_qty) as total_units'),
                DB::raw('SUM(order_item_campaign_credits.credited_revenue) as total_revenue'),
                DB::raw('SUM(order_item_campaign_credits.credited_profit) as total_profit'),
                DB::raw('COUNT(DISTINCT order_item_campaign_credits.order_id) as order_count'),
            ])
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderBy('total_revenue', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }
    
    /**
     * Orders List (Credited Orders)
     * 
     * GET /api/ad-campaigns/{id}/reports/orders
     */
    public function ordersList(Request $request, $id)
    {
        $campaign = AdCampaign::find($id);
        
        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found'
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'mode' => 'required|in:FULL,SPLIT',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        $query = OrderItemCampaignCredit::active()
            ->byCampaign($id)
            ->inDateRange($request->from, $request->to)
            ->where('credit_mode', $request->mode)
            ->with(['order.customer', 'order.store', 'orderItem.product'])
            ->orderBy('sale_time', 'desc');
        
        $credits = $query->paginate($request->get('per_page', 20));
        
        return response()->json([
            'success' => true,
            'data' => $credits,
        ]);
    }
    
    /**
     * Attribution Health Metrics
     * 
     * GET /api/ad-campaigns/reports/health
     */
    public function attributionHealth(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }
        
        // Total order items in date range (use order_date, not created_at based on Order model)
        $totalOrderItems = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.order_date', [$request->from, $request->to])
            ->whereIn('orders.status', ['confirmed', 'processing', 'shipped', 'delivered'])
            ->count();
        
        // Attributed items (SPLIT mode only to avoid double counting)
        $attributedItems = OrderItemCampaignCredit::active()
            ->inDateRange($request->from, $request->to)
            ->where('credit_mode', 'SPLIT')
            ->distinct('order_item_id')
            ->count('order_item_id');
        
        // Attribution distribution (how many campaigns per item)
        $distribution = OrderItemCampaignCredit::active()
            ->inDateRange($request->from, $request->to)
            ->where('credit_mode', 'SPLIT')
            ->select('matched_campaigns_count', DB::raw('COUNT(DISTINCT order_item_id) as item_count'))
            ->groupBy('matched_campaigns_count')
            ->orderBy('matched_campaigns_count')
            ->get();
        
        // Average campaigns per item
        $avgCampaignsPerItem = OrderItemCampaignCredit::active()
            ->inDateRange($request->from, $request->to)
            ->where('credit_mode', 'SPLIT')
            ->avg('matched_campaigns_count');
        
        // Revenue comparison
        $totalRevenue = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.order_date', [$request->from, $request->to])
            ->whereIn('orders.status', ['confirmed', 'processing', 'shipped', 'delivered'])
            ->sum(DB::raw('order_items.unit_price * order_items.quantity - COALESCE(order_items.discount_amount, 0)'));
        
        $attributedRevenue = OrderItemCampaignCredit::active()
            ->inDateRange($request->from, $request->to)
            ->where('credit_mode', 'SPLIT')
            ->sum('credited_revenue');
        
        $unattributedRevenue = $totalRevenue - $attributedRevenue;
        $unattributedItems = $totalOrderItems - $attributedItems;
        
        return response()->json([
            'success' => true,
            'data' => [
                'date_range' => [
                    'from' => $request->from,
                    'to' => $request->to,
                ],
                'items' => [
                    'total' => $totalOrderItems,
                    'attributed' => $attributedItems,
                    'unattributed' => $unattributedItems,
                    'attribution_rate' => $totalOrderItems > 0 
                        ? round(($attributedItems / $totalOrderItems) * 100, 2) 
                        : 0,
                ],
                'revenue' => [
                    'total' => (float) $totalRevenue,
                    'attributed' => (float) $attributedRevenue,
                    'unattributed' => (float) $unattributedRevenue,
                    'attribution_rate' => $totalRevenue > 0 
                        ? round(($attributedRevenue / $totalRevenue) * 100, 2) 
                        : 0,
                ],
                'overlap' => [
                    'avg_campaigns_per_item' => round($avgCampaignsPerItem ?? 0, 2),
                    'distribution' => $distribution->map(function($item) {
                        return [
                            'campaign_count' => (int) $item->matched_campaigns_count,
                            'item_count' => (int) $item->item_count,
                        ];
                    }),
                ],
            ],
        ]);
    }
}
