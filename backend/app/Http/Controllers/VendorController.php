<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    use DatabaseAgnosticSearch;

    public function createVendor(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'type' => 'required|string|in:manufacturer,distributor',
            'email' => 'nullable|email|unique:vendors,email',
            'contact_person' => 'nullable|string',
            'website' => 'nullable|url',
            'credit_limit' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $vendor = Vendor::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Vendor created successfully',
            'data' => $vendor
        ], 201);
    }

    public function updateVendor(Request $request, $id)
    {
        $vendor = Vendor::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'type' => 'sometimes|required|string|in:manufacturer,distributor',
            'email' => ['sometimes', 'nullable', 'email', Rule::unique('vendors')->ignore($vendor->id)],
            'contact_person' => 'nullable|string',
            'website' => 'nullable|url',
            'credit_limit' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $vendor->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Vendor updated successfully',
            'data' => $vendor
        ]);
    }

    public function deleteVendor($id)
    {
        $vendor = Vendor::findOrFail($id);

        // Soft delete by setting is_active to false
        $vendor->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor deactivated successfully'
        ]);
    }

    public function activateVendor($id)
    {
        $vendor = Vendor::findOrFail($id);

        $vendor->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor activated successfully',
            'data' => $vendor
        ]);
    }

    public function deactivateVendor($id)
    {
        $vendor = Vendor::findOrFail($id);

        $vendor->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Vendor deactivated successfully'
        ]);
    }

    public function getVendors(Request $request)
    {
        $query = Vendor::query();

        // Filters
        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['name', 'email', 'contact_person', 'phone'], $search);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        $allowedSortFields = ['name', 'email', 'type', 'credit_limit', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        $vendors = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $vendors
        ]);
    }

    public function getVendor($id)
    {
        $vendor = Vendor::with(['products' => function($query) {
            $query->latest()->limit(10);
        }])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $vendor
        ]);
    }

    public function getVendorsByType($type)
    {
        $vendors = Vendor::where('type', $type)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $vendors,
            'type' => $type
        ]);
    }

    public function getVendorStats()
    {
        $stats = [
            'total_vendors' => Vendor::count(),
            'active_vendors' => Vendor::where('is_active', true)->count(),
            'inactive_vendors' => Vendor::where('is_active', false)->count(),
            'by_type' => Vendor::where('is_active', true)
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->get(),
            'total_credit_limit' => Vendor::where('is_active', true)->sum('credit_limit'),
            'recent_vendors' => Vendor::orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['name', 'type', 'created_at'])
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'vendor_ids' => 'required|array',
            'vendor_ids.*' => 'exists:vendors,id',
            'is_active' => 'required|boolean',
        ]);

        $count = Vendor::whereIn('id', $validated['vendor_ids'])
            ->update(['is_active' => $validated['is_active']]);

        return response()->json([
            'success' => true,
            'message' => "Updated {$count} vendors successfully"
        ]);
    }

    /**
     * Get comprehensive vendor analytics
     */
    public function getVendorAnalytics(Request $request, $id)
    {
        $vendor = Vendor::with(['purchaseOrders', 'payments'])->findOrFail($id);

        // Date range filter
        $fromDate = $request->get('from_date');
        $toDate = $request->get('to_date');

        $purchaseOrdersQuery = $vendor->purchaseOrders();
        $paymentsQuery = $vendor->payments()->where('status', 'completed');

        if ($fromDate && $toDate) {
            $purchaseOrdersQuery->whereBetween('created_at', [$fromDate, $toDate]);
            $paymentsQuery->whereBetween('payment_date', [$fromDate, $toDate]);
        }

        $purchaseOrders = $purchaseOrdersQuery->get();
        $payments = $paymentsQuery->get();

        $analytics = [
            'vendor_info' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'type' => $vendor->type,
                'credit_limit' => $vendor->credit_limit,
                'payment_terms' => $vendor->payment_terms,
                'is_active' => $vendor->is_active,
            ],
            
            'purchase_orders' => [
                'total_orders' => $purchaseOrders->count(),
                'total_value' => $purchaseOrders->sum('total_amount'),
                'by_status' => $purchaseOrders->groupBy('status')->map(function($group) {
                    return [
                        'count' => $group->count(),
                        'total_value' => $group->sum('total_amount')
                    ];
                }),
                'average_order_value' => $purchaseOrders->avg('total_amount'),
                'largest_order' => $purchaseOrders->max('total_amount'),
                'smallest_order' => $purchaseOrders->min('total_amount'),
            ],

            'payments' => [
                'total_paid' => $payments->sum('amount'),
                'total_transactions' => $payments->count(),
                'by_payment_type' => $payments->groupBy('payment_type')->map(function($group) {
                    return [
                        'count' => $group->count(),
                        'total_amount' => $group->sum('amount')
                    ];
                }),
                'average_payment' => $payments->avg('amount'),
                'largest_payment' => $payments->max('amount'),
                'payment_methods' => $payments->groupBy('payment_method_id')->map(function($group) {
                    return [
                        'count' => $group->count(),
                        'total' => $group->sum('amount')
                    ];
                }),
            ],

            'outstanding' => [
                'total_outstanding' => $vendor->getTotalOutstanding(),
                'total_paid' => $vendor->purchaseOrders->sum('paid_amount'),
                'payment_completion_rate' => $vendor->purchaseOrders->sum('total_amount') > 0 
                    ? ($vendor->purchaseOrders->sum('paid_amount') / $vendor->purchaseOrders->sum('total_amount')) * 100 
                    : 0,
                'credit_utilization' => $vendor->credit_limit > 0 
                    ? ($vendor->getTotalOutstanding() / $vendor->credit_limit) * 100 
                    : 0,
                'exceeded_credit_limit' => $vendor->hasExceededCreditLimit(),
            ],

            'products' => [
                'total_products_supplied' => $vendor->products()->count(),
                'active_products' => $vendor->products()->where('is_active', true)->count(),
                'total_quantity_purchased' => $purchaseOrders->sum(function($po) {
                    return $po->items->sum('quantity_ordered');
                }),
                'total_quantity_received' => $purchaseOrders->sum(function($po) {
                    return $po->items->sum('quantity_received');
                }),
            ],

            'timeline' => [
                'first_purchase_date' => $vendor->purchaseOrders()->min('created_at'),
                'last_purchase_date' => $vendor->purchaseOrders()->max('created_at'),
                'last_payment_date' => $vendor->payments()->where('status', 'completed')->max('payment_date'),
                'relationship_duration_days' => $vendor->created_at->diffInDays(now()),
            ],

            'performance' => [
                'on_time_deliveries' => $purchaseOrders->where('status', 'received')
                    ->filter(function($po) {
                        return $po->actual_delivery_date <= $po->expected_delivery_date;
                    })->count(),
                'late_deliveries' => $purchaseOrders->where('status', 'received')
                    ->filter(function($po) {
                        return $po->actual_delivery_date > $po->expected_delivery_date;
                    })->count(),
                'cancelled_orders' => $purchaseOrders->where('status', 'cancelled')->count(),
                'fulfillment_rate' => $purchaseOrders->where('status', 'received')->count() / 
                    max($purchaseOrders->whereIn('status', ['received', 'cancelled'])->count(), 1) * 100,
            ],

            'monthly_breakdown' => $purchaseOrders->groupBy(function($po) {
                return $po->created_at->format('Y-m');
            })->map(function($monthOrders) {
                return [
                    'orders' => $monthOrders->count(),
                    'total_value' => $monthOrders->sum('total_amount'),
                    'paid_amount' => $monthOrders->sum('paid_amount'),
                ];
            })->sortKeys(),
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    /**
     * Get all vendors analytics (comparison)
     */
    public function getAllVendorsAnalytics(Request $request)
    {
        $query = Vendor::with(['purchaseOrders', 'payments']);

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $vendors = $query->get();

        $analytics = [
            'summary' => [
                'total_vendors' => $vendors->count(),
                'active_vendors' => $vendors->where('is_active', true)->count(),
                'total_credit_limit' => $vendors->sum('credit_limit'),
                'total_outstanding' => $vendors->sum(fn($v) => $v->getTotalOutstanding()),
                'total_paid' => $vendors->sum(fn($v) => $v->getTotalPaid()),
            ],

            'by_type' => $vendors->groupBy('type')->map(function($group) {
                return [
                    'count' => $group->count(),
                    'total_purchases' => $group->sum(fn($v) => $v->purchaseOrders->sum('total_amount')),
                    'total_paid' => $group->sum(fn($v) => $v->getTotalPaid()),
                ];
            }),

            'top_vendors_by_volume' => $vendors->sortByDesc(function($vendor) {
                return $vendor->purchaseOrders->sum('total_amount');
            })->take(10)->values()->map(function($vendor) {
                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'type' => $vendor->type,
                    'total_purchases' => $vendor->purchaseOrders->sum('total_amount'),
                    'total_orders' => $vendor->purchaseOrders->count(),
                    'outstanding' => $vendor->getTotalOutstanding(),
                ];
            }),

            'top_vendors_by_orders' => $vendors->sortByDesc(function($vendor) {
                return $vendor->purchaseOrders->count();
            })->take(10)->values()->map(function($vendor) {
                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'type' => $vendor->type,
                    'total_orders' => $vendor->purchaseOrders->count(),
                    'total_value' => $vendor->purchaseOrders->sum('total_amount'),
                ];
            }),

            'vendors_exceeding_credit' => $vendors->filter(function($vendor) {
                return $vendor->hasExceededCreditLimit();
            })->values()->map(function($vendor) {
                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'credit_limit' => $vendor->credit_limit,
                    'outstanding' => $vendor->getTotalOutstanding(),
                    'exceeded_by' => $vendor->getTotalOutstanding() - $vendor->credit_limit,
                ];
            }),

            'payment_performance' => [
                'vendors_fully_paid' => $vendors->filter(fn($v) => $v->getTotalOutstanding() == 0)->count(),
                'vendors_with_outstanding' => $vendors->filter(fn($v) => $v->getTotalOutstanding() > 0)->count(),
                'average_outstanding_per_vendor' => $vendors->avg(fn($v) => $v->getTotalOutstanding()),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $analytics
        ]);
    }

    /**
     * Get vendor purchase history
     */
    public function getPurchaseHistory(Request $request, $id)
    {
        $vendor = Vendor::findOrFail($id);

        $query = $vendor->purchaseOrders()->with(['items.product', 'store']);

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('created_at', [$request->from_date, $request->to_date]);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $purchaseOrders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $purchaseOrders
        ]);
    }

    /**
     * Get vendor payment history
     */
    public function getPaymentHistory(Request $request, $id)
    {
        $vendor = Vendor::findOrFail($id);

        $query = $vendor->payments()->with(['paymentMethod', 'employee', 'paymentItems.purchaseOrder']);

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('payment_date', [$request->from_date, $request->to_date]);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $payments = $query->orderBy('payment_date', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $payments
        ]);
    }
}
