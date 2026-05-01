<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Business History Controller
 *
 * Provides detailed audit trails for critical business operations
 * Shows WHO did WHAT and WHEN with before/after data
 */
class BusinessHistoryController extends Controller
{
    /**
     * Get Product Dispatch History
     * Shows all changes to dispatches including status changes, approvals, items
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProductDispatchHistory(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $dispatchId = $request->get('dispatch_id');
        $status = $request->get('status');
        $sourceStoreId = $request->get('source_store_id');
        $destinationStoreId = $request->get('destination_store_id');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = Activity::where('subject_type', 'App\\Models\\ProductDispatch')
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');

        // Filter by specific dispatch
        if ($dispatchId) {
            $query->where('subject_id', $dispatchId);
        }

        // Filter by date range
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        // Filter by specific events (created, updated, deleted)
        if ($request->has('event')) {
            $query->where('event', $request->get('event'));
        }

        // Advanced filters using properties JSON
        if ($status) {
            $query->where(function ($q) use ($status) {
                $q->whereJsonContains('properties->attributes->status', $status)
                    ->orWhereJsonContains('properties->old->status', $status);
            });
        }

        if ($sourceStoreId) {
            $query->where(function ($q) use ($sourceStoreId) {
                $q->whereJsonContains('properties->attributes->source_store_id', (int) $sourceStoreId)
                    ->orWhereJsonContains('properties->old->source_store_id', (int) $sourceStoreId);
            });
        }

        if ($destinationStoreId) {
            $query->where(function ($q) use ($destinationStoreId) {
                $q->whereJsonContains('properties->attributes->destination_store_id', (int) $destinationStoreId)
                    ->orWhereJsonContains('properties->old->destination_store_id', (int) $destinationStoreId);
            });
        }

        $activities = $query->paginate($perPage);

        // Format the response
        $formattedActivities = $activities->getCollection()->map(function ($activity) {
            return $this->formatActivity($activity, 'dispatch');
        });

        return response()->json([
            'success' => true,
            'data' => [
                'activities' => $formattedActivities,
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'last_page' => $activities->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Get Order History (includes Order, OrderItems, Customer changes)
     * Comprehensive order lifecycle tracking
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrderHistory(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $orderId = $request->get('order_id');
        $customerId = $request->get('customer_id');
        $orderNumber = $request->get('order_number');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        // Get order, order items, and related customer activities
        $query = Activity::whereIn('subject_type', [
            'App\\Models\\Order',
            'App\\Models\\OrderItem',
            'App\\Models\\Customer',
        ])
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');

        // Filter by specific order
        if ($orderId) {
            $query->where(function ($q) use ($orderId) {
                $q->where(function ($subQ) use ($orderId) {
                    $subQ->where('subject_type', 'App\\Models\\Order')
                        ->where('subject_id', $orderId);
                })
                    ->orWhere(function ($subQ) use ($orderId) {
                        $subQ->where('subject_type', 'App\\Models\\OrderItem')
                            ->whereJsonContains('properties->attributes->order_id', (int) $orderId);
                    })
                    ->orWhere(function ($subQ) use ($orderId) {
                        // Include customer changes during order timeline
                        $subQ->where('subject_type', 'App\\Models\\Customer')
                            ->where('description', 'like', '%order%');
                    });
            });
        }

        // Filter by customer
        if ($customerId) {
            $query->where(function ($q) use ($customerId) {
                $q->where(function ($subQ) use ($customerId) {
                    $subQ->where('subject_type', 'App\\Models\\Customer')
                        ->where('subject_id', $customerId);
                })
                    ->orWhere(function ($subQ) use ($customerId) {
                        $subQ->where('subject_type', 'App\\Models\\Order')
                            ->whereJsonContains('properties->attributes->customer_id', (int) $customerId);
                    });
            });
        }

        // Filter by order number
        if ($orderNumber) {
            $query->where(function ($q) use ($orderNumber) {
                $q->whereJsonContains('properties->attributes->order_number', $orderNumber)
                    ->orWhereJsonContains('properties->old->order_number', $orderNumber);
            });
        }

        // Date range
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $activities = $query->paginate($perPage);

        // Format the response
        $formattedActivities = $activities->getCollection()->map(function ($activity) {
            return $this->formatActivity($activity, 'order');
        });

        return response()->json([
            'success' => true,
            'data' => [
                'activities' => $formattedActivities,
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'last_page' => $activities->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Get Purchase Order History
     * Tracks all PO edits, updates, status changes, approvals
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPurchaseOrderHistory(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $purchaseOrderId = $request->get('purchase_order_id');
        $poNumber = $request->get('po_number');
        $vendorId = $request->get('vendor_id');
        $status = $request->get('status');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        $query = Activity::where('subject_type', 'App\\Models\\PurchaseOrder')
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');

        // Filter by specific PO
        if ($purchaseOrderId) {
            $query->where('subject_id', $purchaseOrderId);
        }

        // Filter by PO number
        if ($poNumber) {
            $query->where(function ($q) use ($poNumber) {
                $q->whereJsonContains('properties->attributes->po_number', $poNumber)
                    ->orWhereJsonContains('properties->old->po_number', $poNumber);
            });
        }

        // Filter by vendor
        if ($vendorId) {
            $query->where(function ($q) use ($vendorId) {
                $q->whereJsonContains('properties->attributes->vendor_id', (int) $vendorId)
                    ->orWhereJsonContains('properties->old->vendor_id', (int) $vendorId);
            });
        }

        // Filter by status
        if ($status) {
            $query->where(function ($q) use ($status) {
                $q->whereJsonContains('properties->attributes->status', $status)
                    ->orWhereJsonContains('properties->old->status', $status);
            });
        }

        // Date range
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $activities = $query->paginate($perPage);

        // Format the response
        $formattedActivities = $activities->getCollection()->map(function ($activity) {
            return $this->formatActivity($activity, 'purchase_order');
        });

        return response()->json([
            'success' => true,
            'data' => [
                'activities' => $formattedActivities,
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'last_page' => $activities->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Get Store Assignment History
     * Tracks when orders are assigned to stores
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStoreAssignmentHistory(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $orderId = $request->get('order_id');
        $storeId = $request->get('store_id');
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        /**
         * MariaDB 11.4 FIX:
         * - Removed PostgreSQL JSON operators: properties->'old'->>'store_id'
         * - Removed IS DISTINCT FROM (Postgres-style)
         * - Replaced with JSON_EXTRACT/JSON_UNQUOTE + NULL-safe comparator (<=>)
         */
        $query = Activity::where('subject_type', 'App\\Models\\Order')
            ->where('event', 'updated')
            ->where(function ($q) {
                $old = "JSON_UNQUOTE(JSON_EXTRACT(properties, '$.old.store_id'))";
                $new = "JSON_UNQUOTE(JSON_EXTRACT(properties, '$.attributes.store_id'))";

                // Equivalent of: old.store_id IS DISTINCT FROM new.store_id
                $q->whereRaw("NOT ($old <=> $new)");

                // Ensure new store_id exists (keeps your original intention)
                $q->whereRaw("JSON_EXTRACT(properties, '$.attributes.store_id') IS NOT NULL");
            })
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');

        // Filter by specific order
        if ($orderId) {
            $query->where('subject_id', $orderId);
        }

        // Filter by store (use JSON_EXTRACT equality for scalar store_id)
        if ($storeId) {
            $query->whereRaw(
                "JSON_EXTRACT(properties, '$.attributes.store_id') = ?",
                [(int) $storeId]
            );
        }

        // Date range
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $activities = $query->paginate($perPage);

        // Format the response
        $formattedActivities = $activities->getCollection()->map(function ($activity) {
            $formatted = $this->formatActivity($activity, 'store_assignment');

            // Add store details
            $oldStoreId = $activity->properties['old']['store_id'] ?? null;
            $newStoreId = $activity->properties['attributes']['store_id'] ?? null;

            if ($oldStoreId) {
                $oldStore = \App\Models\Store::find($oldStoreId);
                $formatted['old_store'] = $oldStore ? [
                    'id' => $oldStore->id,
                    'name' => $oldStore->name,
                ] : null;
            }

            if ($newStoreId) {
                $newStore = \App\Models\Store::find($newStoreId);
                $formatted['new_store'] = $newStore ? [
                    'id' => $newStore->id,
                    'name' => $newStore->name,
                ] : null;
            }

            return $formatted;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'activities' => $formattedActivities,
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'last_page' => $activities->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Get Product History (especially defective product tracking)
     * Tracks product edits and when marked as defective
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProductHistory(Request $request)
    {
        $perPage = $request->get('per_page', 15);
        $productId = $request->get('product_id');
        $sku = $request->get('sku');
        $isDefective = $request->get('is_defective'); // Filter for defective products
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');

        // Include both Product and DefectiveProduct activities
        $query = Activity::whereIn('subject_type', [
            'App\\Models\\Product',
            'App\\Models\\DefectiveProduct',
        ])
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');

        // Filter by specific product
        if ($productId) {
            $query->where(function ($q) use ($productId) {
                $q->where(function ($subQ) use ($productId) {
                    $subQ->where('subject_type', 'App\\Models\\Product')
                        ->where('subject_id', $productId);
                })
                    ->orWhere(function ($subQ) use ($productId) {
                        $subQ->where('subject_type', 'App\\Models\\DefectiveProduct')
                            ->whereJsonContains('properties->attributes->product_id', (int) $productId);
                    });
            });
        }

        // Filter by SKU
        if ($sku) {
            $query->where(function ($q) use ($sku) {
                $q->whereJsonContains('properties->attributes->sku', $sku)
                    ->orWhereJsonContains('properties->old->sku', $sku);
            });
        }

        // Filter for defective product markings
        if ($isDefective === 'true' || $isDefective === true) {
            $query->where('subject_type', 'App\\Models\\DefectiveProduct');
        }

        // Date range
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $activities = $query->paginate($perPage);

        // Format the response
        $formattedActivities = $activities->getCollection()->map(function ($activity) {
            $formatted = $this->formatActivity($activity, 'product');

            // Add special flag for defective product markings
            if ($activity->subject_type === 'App\\Models\\DefectiveProduct') {
                $formatted['marked_as_defective'] = true;
                $formatted['defect_details'] = [
                    'reason' => $activity->properties['attributes']['defect_reason'] ?? null,
                    'condition' => $activity->properties['attributes']['condition'] ?? null,
                    'discount_percentage' => $activity->properties['attributes']['discount_percentage'] ?? null,
                ];
            }

            return $formatted;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'activities' => $formattedActivities,
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'last_page' => $activities->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Get comprehensive history for a specific order
     * Includes order, items, payments, shipments, customer
     *
     * @param int $orderId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getOrderComprehensiveHistory($orderId, Request $request)
    {
        $perPage = $request->get('per_page', 20);

        // Get the order first
        $order = \App\Models\Order::findOrFail($orderId);

        // Get all related activities
        $query = Activity::where(function ($q) use ($orderId, $order) {
            $q->where(function ($subQ) use ($orderId) {
                // Order changes
                $subQ->where('subject_type', 'App\\Models\\Order')
                    ->where('subject_id', $orderId);
            })
                ->orWhere(function ($subQ) use ($orderId) {
                    // Order items
                    $subQ->where('subject_type', 'App\\Models\\OrderItem')
                        ->whereJsonContains('properties->attributes->order_id', (int) $orderId);
                })
                ->orWhere(function ($subQ) use ($orderId) {
                    // Order payments
                    $subQ->where('subject_type', 'App\\Models\\OrderPayment')
                        ->whereJsonContains('properties->attributes->order_id', (int) $orderId);
                })
                ->orWhere(function ($subQ) use ($orderId) {
                    // Shipments
                    $subQ->where('subject_type', 'App\\Models\\Shipment')
                        ->whereJsonContains('properties->attributes->order_id', (int) $orderId);
                })
                ->orWhere(function ($subQ) use ($order) {
                    // Customer changes during order period
                    $subQ->where('subject_type', 'App\\Models\\Customer')
                        ->where('subject_id', $order->customer_id);
                });
        })
            ->with(['causer', 'subject'])
            ->orderBy('created_at', 'desc');

        $activities = $query->paginate($perPage);

        // Group activities by type
        $groupedActivities = $activities->getCollection()->groupBy('subject_type')->map(function ($group) {
            return $group->map(function ($activity) {
                return $this->formatActivity($activity, 'comprehensive');
            });
        });

        return response()->json([
            'success' => true,
            'data' => [
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer' => $order->customer,
                    'status' => $order->status,
                ],
                'activities_by_type' => $groupedActivities,
                'activities_timeline' => $activities->getCollection()->map(function ($activity) {
                    return $this->formatActivity($activity, 'comprehensive');
                }),
                'pagination' => [
                    'current_page' => $activities->currentPage(),
                    'per_page' => $activities->perPage(),
                    'total' => $activities->total(),
                    'last_page' => $activities->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Format activity for consistent response structure
     *
     * @param Activity $activity
     * @param string $context
     * @return array
     */
    private function formatActivity($activity, $context = 'general')
    {
        $changes = $this->extractChanges($activity);

        return [
            'id' => $activity->id,
            'event' => $activity->event, // created, updated, deleted
            'description' => $activity->description,
            'subject_type' => class_basename($activity->subject_type),
            'subject_id' => $activity->subject_id,
            'subject' => $activity->subject,

            // WHO
            'who' => [
                'id' => $activity->causer_id,
                'type' => $activity->causer_type ? class_basename($activity->causer_type) : null,
                'name' => $activity->causer ? $activity->causer->name : 'System',
                'email' => $activity->causer ? $activity->causer->email : null,
            ],

            // WHEN
            'when' => [
                'timestamp' => $activity->created_at->toISOString(),
                'formatted' => $activity->created_at->format('Y-m-d H:i:s'),
                'human' => $activity->created_at->diffForHumans(),
            ],

            // WHAT (changes)
            'what' => $changes,

            // Original properties for detailed inspection
            'properties' => $activity->properties,
        ];
    }

    /**
     * Extract meaningful changes from activity properties
     *
     * @param Activity $activity
     * @return array
     */
    private function extractChanges($activity)
    {
        $properties = $activity->properties;
        $changes = [];

        if ($activity->event === 'created') {
            $changes['action'] = 'created';
            $changes['new_data'] = $properties['attributes'] ?? [];
        } elseif ($activity->event === 'updated') {
            $changes['action'] = 'updated';
            $old = is_array($properties['old'] ?? []) ? $properties['old'] : (array) ($properties['old'] ?? []);
            $new = is_array($properties['attributes'] ?? []) ? $properties['attributes'] : (array) ($properties['attributes'] ?? []);

            // Safe comparison that handles nested arrays/objects
            $diffResult = $this->safeDiff($old, $new);
            $changes['fields_changed'] = $diffResult['fields_changed'];
            $changes['changes'] = $diffResult['changes'];
        } elseif ($activity->event === 'deleted') {
            $changes['action'] = 'deleted';
            $changes['deleted_data'] = $properties['attributes'] ?? [];
        }

        return $changes;
    }

    /**
     * Safely compare two arrays that may contain nested arrays/objects
     *
     * @param array $old
     * @param array $new
     * @return array
     */
    private function safeDiff(array $old, array $new): array
    {
        $changes = [];
        $fieldsChanged = [];

        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allKeys as $key) {
            $oldVal = $old[$key] ?? null;
            $newVal = $new[$key] ?? null;

            // Normalize to comparable strings for comparison ONLY
            $oldNorm = $this->normalizeForDiff($oldVal);
            $newNorm = $this->normalizeForDiff($newVal);

            if ($oldNorm !== $newNorm) {
                $fieldsChanged[] = $key;
                $changes[$key] = [
                    'from' => $oldVal,
                    'to' => $newVal,
                ];
            }
        }

        return [
            'fields_changed' => $fieldsChanged,
            'changes' => $changes,
        ];
    }

    /**
     * Normalize a value to a comparable string representation
     *
     * @param mixed $value
     * @return string
     */
    private function normalizeForDiff($value): string
    {
        if (is_null($value)) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_int($value) || is_float($value) || is_string($value)) return (string) $value;

        // Laravel collections / models / objects → array
        if ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->toArray();
        } elseif ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif (is_object($value)) {
            // try common model toArray()
            if (method_exists($value, 'toArray')) {
                $value = $value->toArray();
            } else {
                $value = (array) $value;
            }
        }

        // Arrays (nested) → stable JSON string
        if (is_array($value)) {
            $this->ksortRecursive($value);
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    /**
     * Recursively sort an array by keys for stable comparison
     *
     * @param array $arr
     * @return void
     */
    private function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }

    /**
     * Get history statistics
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHistoryStatistics(Request $request)
    {
        $startDate = $request->get('start_date', now()->subDays(30));
        $endDate = $request->get('end_date', now());

        $stats = [
            'total_activities' => Activity::whereBetween('created_at', [$startDate, $endDate])->count(),
            'by_model' => Activity::select('subject_type', DB::raw('count(*) as count'))
                ->whereBetween('created_at', [$startDate, $endDate])
                ->groupBy('subject_type')
                ->get()
                ->mapWithKeys(function ($item) {
                    return [class_basename($item->subject_type) => $item->count];
                }),
            'by_event' => Activity::select('event', DB::raw('count(*) as count'))
                ->whereBetween('created_at', [$startDate, $endDate])
                ->groupBy('event')
                ->get()
                ->pluck('count', 'event'),
            'most_active_users' => Activity::select('causer_id', 'causer_type', DB::raw('count(*) as activity_count'))
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('causer_id')
                ->groupBy('causer_id', 'causer_type')
                ->orderByDesc('activity_count')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    $causer = $item->causer_type ? $item->causer_type::find($item->causer_id) : null;
                    return [
                        'id' => $item->causer_id,
                        'name' => $causer ? $causer->name : 'Unknown',
                        'activity_count' => $item->activity_count,
                    ];
                }),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}