<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Models\Order;
use App\Models\Store;
use App\Traits\DatabaseAgnosticSearch;
use App\Services\PathaoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ShipmentController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * List all shipments with filters
     * 
     * GET /api/shipments?status=pending&store_id=1
     */
    public function index(Request $request)
    {
        $query = Shipment::with([
            'order.customer',
            'store',
            'createdBy',
            'processedBy',
        ]);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by store
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // Filter by delivery type
        if ($request->filled('delivery_type')) {
            $query->where('delivery_type', $request->delivery_type);
        }

        // Filter by customer
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by order
        if ($request->filled('order_id')) {
            $query->where('order_id', $request->order_id);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        // Search by shipment number
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $this->whereLike($q, 'shipment_number', $request->search);
                $this->orWhereLike($q, 'pathao_consignment_id', $request->search);
                $q->orWhereHas('order', function ($orderQuery) use ($request) {
                    $this->whereLike($orderQuery, 'order_number', $request->search);
                })
                  ->orWhereHas('order.customer', function ($customerQuery) use ($request) {
                    $this->whereLike($customerQuery, 'name', $request->search);
                    $this->orWhereLike($customerQuery, 'phone', $request->search);
                  });
            });
        }

        // Filter pending Pathao submissions
        if ($request->boolean('pending_pathao')) {
            $query->where('status', 'pending')
                  ->whereNull('pathao_consignment_id');
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $shipments = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $shipments
        ]);
    }

    /**
     * Get shipment details
     * 
     * GET /api/shipments/{id}
     */
    public function show($id)
    {
        $shipment = Shipment::with([
            'order.items.product',
            'order.customer',
            'store',
            'createdBy',
            'processedBy',
            'deliveredBy',
        ])->find($id);

        if (!$shipment) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'shipment' => $shipment,
                'products' => $shipment->getPackageProducts(),
                'pickup_address_formatted' => $shipment->getPickupAddressFormatted(),
                'delivery_address_formatted' => $shipment->getDeliveryAddressFormatted(),
                'package_description' => $shipment->getPackageDescription(),
            ]
        ]);
    }

    /**
     * Create shipment from order
     * 
     * POST /api/shipments
     * Body: {
     *   "order_id": 1,
     *   "delivery_type": "home_delivery|express",  // no store_pickup if using Pathao
     *   "package_weight": 2.5,
     *   "special_instructions": "Handle with care",
     *   "send_to_pathao": false  // Set true to immediately send to Pathao
     * }
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'delivery_type' => 'required|in:home_delivery,express',
            'package_weight' => 'nullable|numeric|min:0',
            'package_dimensions' => 'nullable|array',
            'special_instructions' => 'nullable|string',
            'send_to_pathao' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $order = Order::with(['items.batch.barcode', 'customer', 'store', 'shipments'])->findOrFail($request->order_id);

            // Check if order already has active shipment
            $existingShipment = $order->shipments()->whereNotIn('status', ['cancelled', 'delivered'])->first();
            if ($existingShipment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order already has an active shipment',
                    'shipment' => $existingShipment
                ], 400);
            }

            // Collect package barcodes from order items
            $packageBarcodes = [];
            foreach ($order->items as $item) {
                if ($item->batch && $item->batch->barcode) {
                    $packageBarcodes[] = $item->batch->barcode->barcode;
                }
            }

            // Prepare shipment data
            $shipmentData = [
                'delivery_type' => $request->delivery_type,
                'package_weight' => $request->package_weight ?? 1.0,
                'package_dimensions' => $request->package_dimensions,
                'special_instructions' => $request->special_instructions,
                'created_by' => Auth::id(),
            ];

            // Create shipment from order
            $shipment = Shipment::createFromOrder($order, $shipmentData);

            // Immediately send to Pathao if requested
            if ($request->boolean('send_to_pathao')) {
                try {
                    $this->sendToPathao($shipment);
                    $message = 'Shipment created and sent to Pathao successfully';
                } catch (\Exception $e) {
                    $message = 'Shipment created but failed to send to Pathao: ' . $e->getMessage();
                }
            } else {
                $message = 'Shipment created successfully. Send to Pathao when ready.';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $shipment->load(['order.customer', 'store'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create shipment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send shipment to Pathao
     * 
     * POST /api/shipments/{id}/send-to-pathao
     */
    public function sendToPathao($shipmentOrId)
    {
        $shipment = $shipmentOrId instanceof Shipment 
            ? $shipmentOrId 
            : Shipment::with(['order', 'store'])->findOrFail($shipmentOrId);

        if (!$shipment->isPending()) {
            throw new \Exception('Only pending shipments can be sent to Pathao');
        }

        if ($shipment->pathao_consignment_id) {
            throw new \Exception('Shipment already sent to Pathao');
        }

        try {
            $order = $shipment->order;
            $store = $shipment->store;
            $deliveryAddress = is_array($shipment->delivery_address) ? $shipment->delivery_address : [];

            // Auto-location: let Pathao infer city/zone/area from address text
            $autoLocation = config('services.pathao.auto_location', true);

            // Build complete address string for Pathao auto-mapping
            $addressParts = [
                $deliveryAddress['address_line_1'] ?? $deliveryAddress['street'] ?? $deliveryAddress['address'] ?? null,
                $deliveryAddress['address_line_2'] ?? null,
                $deliveryAddress['landmark'] ?? null,
                $deliveryAddress['area'] ?? $deliveryAddress['thana'] ?? $deliveryAddress['upazila'] ?? null,
                $deliveryAddress['city'] ?? $deliveryAddress['district'] ?? null,
                $deliveryAddress['postal_code'] ?? $deliveryAddress['zip'] ?? null,
            ];

            $addressParts = array_values(array_filter(array_map(function ($value) {
                if ($value === null) return null;
                if (is_scalar($value)) {
                    $s = trim((string) $value);
                    return $s === '' ? null : $s;
                }
                return null;
            }, $addressParts)));

            $recipientAddress = implode(', ', $addressParts);

            // Validate Pathao requirements
            $validationErrors = [];
            
            // Check store has Pathao configuration
            if (!$store->pathao_store_id) {
                $validationErrors[] = 'Store not registered with Pathao. Please configure store Pathao details first.';
            }

            if (empty($recipientAddress)) {
                $validationErrors[] = 'Delivery address is empty. Please provide a full address (street + area + city).';
            }

            $hasLocationIds = !empty($deliveryAddress['pathao_city_id'])
                && !empty($deliveryAddress['pathao_zone_id'])
                && !empty($deliveryAddress['pathao_area_id']);

            // If auto-location disabled, require manual IDs
            if (!$autoLocation && !$hasLocationIds) {
                if (empty($deliveryAddress['pathao_city_id'])) {
                    $validationErrors[] = 'Delivery address missing Pathao city ID';
                }
                if (empty($deliveryAddress['pathao_zone_id'])) {
                    $validationErrors[] = 'Delivery address missing Pathao zone ID';
                }
                if (empty($deliveryAddress['pathao_area_id'])) {
                    $validationErrors[] = 'Delivery address missing Pathao area ID';
                }
            }
            
            // If validation errors, throw exception with details
            if (!empty($validationErrors)) {
                throw new \Exception('Cannot send to Pathao: ' . implode('; ', $validationErrors));
            }

            // Calculate total weight from order items
            $totalWeight = $order->items->sum(function($item) {
                return ($item->product->weight ?? 0.5) * $item->quantity;
            });
            $totalWeight = max($totalWeight, 0.5); // Minimum 0.5kg as per Pathao manual

            // Prepare Pathao order data
            $pathaoData = [
                'store_id' => (int) $store->pathao_store_id,
                'merchant_order_id' => $order->order_number,
                'recipient_name' => $shipment->recipient_name,
                'recipient_phone' => $shipment->recipient_phone,
                'recipient_address' => $recipientAddress,  // Complete address for auto-mapping
                'delivery_type' => $shipment->delivery_type === 'express' ? 12 : 48,  // 12=express, 48=normal
                'item_type' => 2,  // 1=document, 2=parcel
                'special_instruction' => $shipment->special_instructions ?? '',
                'item_quantity' => (int) $order->items->sum('quantity'),
                'item_weight' => $totalWeight,
                'amount_to_collect' => (int) round((float) str_replace(',', '', (string) ($shipment->cod_amount ?? 0))),
                'item_description' => $shipment->getPackageDescription(),
            ];

            // If location IDs available (manual selection), include them
            // Otherwise omit so Pathao can infer from recipient_address
            if ($hasLocationIds) {
                $pathaoData['recipient_city'] = $deliveryAddress['pathao_city_id'];
                $pathaoData['recipient_zone'] = $deliveryAddress['pathao_zone_id'];
                $pathaoData['recipient_area'] = $deliveryAddress['pathao_area_id'];
            }

            // Call Pathao API using PathaoService
            $pathaoService = new PathaoService();
            $pathaoService->setStoreId($store->pathao_store_id);
            $result = $pathaoService->createOrder($pathaoData);

            if (empty($result['success'])) {
                $err = $result['error'] ?? 'Unknown error';
                if (is_array($err)) {
                    $err = json_encode($err);
                }
                throw new \Exception((string) $err);
            }

            $data = $result['data'] ?? [];

            $shipment->pathao_consignment_id = $data['consignment_id'] ?? null;
            $shipment->pathao_tracking_number = $data['invoice_id'] ?? null;
            $shipment->pathao_status = 'pickup_requested';
            $shipment->pathao_response = $result['response'];
            $shipment->status = 'pickup_requested';
            $shipment->pickup_requested_at = now();
            
            if (isset($data['delivery_fee'])) {
                $shipment->delivery_fee = $data['delivery_fee'];
            }

            $shipment->addStatusHistory('pickup_requested', 'Sent to Pathao. Consignment ID: ' . ($data['consignment_id'] ?? 'N/A'));
            $shipment->save();

            return $shipment;

        } catch (\Exception $e) {
            \Log::error('Pathao API Error - Send Shipment', [
                'shipment_id' => $shipment->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk send shipments to Pathao
     * 
     * POST /api/shipments/bulk-send-to-pathao
     * Body: {
     *   "shipment_ids": [1, 2, 3, 4]
     * }
     */
    public function bulkSendToPathao(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shipment_ids' => 'required|array|min:1',
            'shipment_ids.*' => 'exists:shipments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        $shipments = Shipment::with(['order', 'store'])
                             ->whereIn('id', $request->shipment_ids)
                             ->get();

        foreach ($shipments as $shipment) {
            try {
                // Check if eligible
                if (!$shipment->isPending()) {
                    $results['failed'][] = [
                        'shipment_id' => $shipment->id,
                        'shipment_number' => $shipment->shipment_number,
                        'reason' => 'Not in pending status'
                    ];
                    continue;
                }

                if ($shipment->pathao_consignment_id) {
                    $results['failed'][] = [
                        'shipment_id' => $shipment->id,
                        'shipment_number' => $shipment->shipment_number,
                        'reason' => 'Already sent to Pathao'
                    ];
                    continue;
                }

                // Send to Pathao
                $this->sendToPathao($shipment);

                $results['success'][] = [
                    'shipment_id' => $shipment->id,
                    'shipment_number' => $shipment->shipment_number,
                    'pathao_consignment_id' => $shipment->pathao_consignment_id
                ];

            } catch (\Exception $e) {
                $results['failed'][] = [
                    'shipment_id' => $shipment->id,
                    'shipment_number' => $shipment->shipment_number,
                    'reason' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => count($results['success']) . ' shipments sent successfully, ' . count($results['failed']) . ' failed',
            'data' => $results
        ]);
    }

    /**
     * Update shipment status from Pathao
     * 
     * GET /api/shipments/{id}/sync-pathao-status
     */
    public function syncPathaoStatus($id)
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->pathao_consignment_id) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment not sent to Pathao yet'
            ], 400);
        }

        try {
            $response = PathaoCourier::order()->orderDetails($shipment->pathao_consignment_id);

            if ($response && isset($response['data'])) {
                $data = $response['data'];
                
                $oldStatus = $shipment->pathao_status;
                $newStatus = $data['status'] ?? $oldStatus;

                $shipment->pathao_status = $newStatus;
                $shipment->pathao_response = $response;

                // Update local status based on Pathao status
                $statusMap = [
                    'Pending' => 'pending',
                    'Pickup_Pending' => 'pickup_requested',
                    'Pickup_Request_Accepted' => 'pickup_requested',
                    'Picked_up' => 'picked_up',
                    'Reached_at_Pathao_Warehouse' => 'picked_up',
                    'In_transit' => 'in_transit',
                    'Delivered' => 'delivered',
                    'Returned' => 'returned',
                    'Cancelled' => 'cancelled',
                ];

                $newLocalStatus = $statusMap[$newStatus] ?? $shipment->status;

                if ($newLocalStatus !== $shipment->status) {
                    $shipment->status = $newLocalStatus;
                    $shipment->addStatusHistory($newLocalStatus, "Status synced from Pathao: {$newStatus}");

                    // Update timestamps
                    if ($newLocalStatus === 'delivered' && !$shipment->delivered_at) {
                        $shipment->delivered_at = now();
                    } elseif ($newLocalStatus === 'returned' && !$shipment->returned_at) {
                        $shipment->returned_at = now();
                    }
                }

                $shipment->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Status synced successfully',
                    'data' => [
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                        'local_status' => $shipment->status
                    ]
                ]);
            }

            throw new \Exception('Invalid response from Pathao');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk sync Pathao status
     * 
     * POST /api/shipments/bulk-sync-pathao-status
     * Body: {
     *   "shipment_ids": [1, 2, 3]  // Optional, sync all if not provided
     * }
     */
    public function bulkSyncPathaoStatus(Request $request)
    {
        $query = Shipment::whereNotNull('pathao_consignment_id')
                         ->whereNotIn('status', ['delivered', 'cancelled', 'returned']);

        if ($request->filled('shipment_ids')) {
            $query->whereIn('id', $request->shipment_ids);
        }

        $shipments = $query->get();

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($shipments as $shipment) {
            try {
                $response = PathaoCourier::order()->orderDetails($shipment->pathao_consignment_id);

                if ($response && isset($response['data'])) {
                    $data = $response['data'];
                    $oldStatus = $shipment->pathao_status;
                    $newStatus = $data['status'] ?? $oldStatus;

                    if ($oldStatus !== $newStatus) {
                        $shipment->pathao_status = $newStatus;
                        $shipment->pathao_response = $response;
                        $shipment->save();

                        $results['success'][] = [
                            'shipment_id' => $shipment->id,
                            'shipment_number' => $shipment->shipment_number,
                            'old_status' => $oldStatus,
                            'new_status' => $newStatus
                        ];
                    }
                }

            } catch (\Exception $e) {
                $results['failed'][] = [
                    'shipment_id' => $shipment->id,
                    'shipment_number' => $shipment->shipment_number,
                    'reason' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => count($results['success']) . ' shipments synced successfully',
            'data' => $results
        ]);
    }

    /**
     * Cancel shipment
     * 
     * PATCH /api/shipments/{id}/cancel
     * Body: {
     *   "reason": "Customer cancelled order"
     * }
     */
    public function cancel($id, Request $request)
    {
        $shipment = Shipment::findOrFail($id);

        if (!$shipment->canBeCancelled()) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment cannot be cancelled'
            ], 400);
        }

        try {
            $shipment->cancel($request->input('reason'));

            return response()->json([
                'success' => true,
                'message' => 'Shipment cancelled successfully',
                'data' => $shipment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel shipment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get shipment statistics
     * 
     * GET /api/shipments/statistics?store_id=1
     */
    public function getStatistics(Request $request)
    {
        $storeId = $request->input('store_id');

        $stats = Shipment::getShipmentStats($storeId);

        // Additional stats
        $query = Shipment::query();
        if ($storeId) {
            $query->where('store_id', $storeId);
        }

        $stats['pending_pathao_submissions'] = (clone $query)
            ->where('status', 'pending')
            ->whereNull('pathao_consignment_id')
            ->count();

        $stats['in_transit_with_pathao'] = (clone $query)
            ->whereNotNull('pathao_consignment_id')
            ->where('status', 'in_transit')
            ->count();

        $stats['total_cod_amount'] = $query->sum('cod_amount');

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get Pathao areas (cities, zones, areas)
     * Helper endpoints for frontend
     */
    public function getPathaoCities()
    {
        try {
            $response = PathaoCourier::area()->city();
            
            // Convert stdClass to array and extract data
            $responseArray = json_decode(json_encode($response), true);
            $cities = $responseArray['data'] ?? [];
            
            return response()->json([
                'success' => true,
                'data' => $cities
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cities: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getPathaoZones($cityId)
    {
        try {
            $response = PathaoCourier::area()->zone($cityId);
            
            // Convert stdClass to array and extract data
            $responseArray = json_decode(json_encode($response), true);
            $zones = $responseArray['data'] ?? [];
            
            return response()->json([
                'success' => true,
                'data' => $zones
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch zones: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getPathaoAreas($zoneId)
    {
        try {
            $response = PathaoCourier::area()->area($zoneId);
            
            // Convert stdClass to array and extract data
            $responseArray = json_decode(json_encode($response), true);
            $areas = $responseArray['data'] ?? [];
            
            return response()->json([
                'success' => true,
                'data' => $areas
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch areas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Pathao stores
     * 
     * GET /api/shipments/pathao/stores
     */
    public function getPathaoStores()
    {
        try {
            $response = PathaoCourier::store()->list();
            
            // Convert stdClass to array and extract data
            $responseArray = json_decode(json_encode($response), true);
            $stores = $responseArray['data'] ?? [];
            
            return response()->json([
                'success' => true,
                'data' => $stores
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stores: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create Pathao store
     * 
     * POST /api/shipments/pathao/stores
     * Body: {
     *   "name": "Main Store",
     *   "contact_name": "John Doe",
     *   "contact_number": "01712345678",
     *   "address": "123 Main St",
     *   "secondary_contact": "01812345678",
     *   "city_id": 1,
     *   "zone_id": 1,
     *   "area_id": 1
     * }
     */
    public function createPathaoStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string',
            'contact_name' => 'required|string',
            'contact_number' => 'required|string',
            'address' => 'required|string',
            'secondary_contact' => 'nullable|string',
            'city_id' => 'required|integer',
            'zone_id' => 'required|integer',
            'area_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $storeData = [
                'name' => $request->name,
                'contact_name' => $request->contact_name,
                'contact_number' => $request->contact_number,
                'address' => $request->address,
                'secondary_contact' => $request->secondary_contact ?? '',
                'city_id' => $request->city_id,
                'zone_id' => $request->zone_id,
                'area_id' => $request->area_id,
            ];

            $response = PathaoCourier::store()->create($storeData);

            return response()->json([
                'success' => true,
                'message' => 'Pathao store created successfully',
                'data' => $response
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create store: ' . $e->getMessage()
            ], 500);
        }
    }
}
