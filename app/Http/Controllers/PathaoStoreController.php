<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Xenon\LaravelBDSms\Facades\PathaoCourier;

class PathaoStoreController extends Controller
{
    /**
     * Get Pathao cities
     */
    public function getCities()
    {
        try {
            $response = PathaoCourier::area()->city();
            
            if ($response && isset($response['data']['data'])) {
                return response()->json([
                    'success' => true,
                    'data' => $response['data']['data']
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cities from Pathao'
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching cities: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Pathao zones by city ID
     */
    public function getZones($cityId)
    {
        try {
            $response = PathaoCourier::area()->zone($cityId);
            
            if ($response && isset($response['data']['data'])) {
                return response()->json([
                    'success' => true,
                    'data' => $response['data']['data']
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch zones from Pathao'
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching zones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Pathao areas by zone ID
     */
    public function getAreas($zoneId)
    {
        try {
            $response = PathaoCourier::area()->area($zoneId);
            
            if ($response && isset($response['data']['data'])) {
                return response()->json([
                    'success' => true,
                    'data' => $response['data']['data']
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch areas from Pathao'
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching areas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Register a store with Pathao
     */
    public function registerStore(Request $request, $storeId)
    {
        $validator = Validator::make($request->all(), [
            'pathao_contact_name' => 'required|string|max:255',
            'pathao_contact_number' => 'required|string|max:20',
            'pathao_secondary_contact' => 'nullable|string|max:20',
            'pathao_city_id' => 'required|integer',
            'pathao_zone_id' => 'required|integer',
            'pathao_area_id' => 'required|integer',
            'address' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $store = Store::findOrFail($storeId);

            // Register store with Pathao API
            $pathaoData = [
                'name' => $store->name,
                'contact_name' => $request->pathao_contact_name,
                'contact_number' => $request->pathao_contact_number,
                'secondary_contact' => $request->pathao_secondary_contact ?? '',
                'address' => $request->address,
                'city_id' => $request->pathao_city_id,
                'zone_id' => $request->pathao_zone_id,
                'area_id' => $request->pathao_area_id,
            ];

            $response = PathaoCourier::store()->create($pathaoData);

            if ($response && isset($response['data'])) {
                // Update store with Pathao store ID
                $store->update([
                    'pathao_store_id' => $response['data']['store_id'],
                    'pathao_contact_name' => $request->pathao_contact_name,
                    'pathao_contact_number' => $request->pathao_contact_number,
                    'pathao_secondary_contact' => $request->pathao_secondary_contact,
                    'pathao_city_id' => $request->pathao_city_id,
                    'pathao_zone_id' => $request->pathao_zone_id,
                    'pathao_area_id' => $request->pathao_area_id,
                    'pathao_registered' => true,
                    'pathao_registered_at' => now()
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Store registered with Pathao successfully',
                    'data' => [
                        'pathao_store_id' => $response['data']['store_id'],
                        'store' => $store->fresh()
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to register store with Pathao',
                'error' => $response['message'] ?? 'Unknown error'
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error registering store: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update store Pathao configuration
     */
    public function updateStoreConfig(Request $request, $storeId)
    {
        $validator = Validator::make($request->all(), [
            'pathao_contact_name' => 'nullable|string|max:255',
            'pathao_contact_number' => 'nullable|string|max:20',
            'pathao_secondary_contact' => 'nullable|string|max:20',
            'pathao_city_id' => 'nullable|integer',
            'pathao_zone_id' => 'nullable|integer',
            'pathao_area_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $store = Store::findOrFail($storeId);
            $store->update($request->only([
                'pathao_contact_name',
                'pathao_contact_number',
                'pathao_secondary_contact',
                'pathao_city_id',
                'pathao_zone_id',
                'pathao_area_id'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Store Pathao configuration updated',
                'data' => $store->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating store: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get store Pathao status
     */
    public function getStoreStatus($storeId)
    {
        try {
            $store = Store::findOrFail($storeId);

            return response()->json([
                'success' => true,
                'data' => [
                    'is_registered' => (bool) $store->pathao_registered,
                    'pathao_store_id' => $store->pathao_store_id,
                    'registered_at' => $store->pathao_registered_at,
                    'config' => [
                        'contact_name' => $store->pathao_contact_name,
                        'contact_number' => $store->pathao_contact_number,
                        'secondary_contact' => $store->pathao_secondary_contact,
                        'city_id' => $store->pathao_city_id,
                        'zone_id' => $store->pathao_zone_id,
                        'area_id' => $store->pathao_area_id,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching store status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if a single order was sent via Pathao
     * 
     * GET /api/pathao/orders/lookup/{orderNumber}
     */
    public function checkOrderPathaoStatus($orderNumber)
    {
        try {
            $order = \App\Models\Order::where('order_number', $orderNumber)->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Check if order has any shipment with Pathao consignment ID
            $shipment = $order->shipments()
                ->whereNotNull('pathao_consignment_id')
                ->first();

            $isSentViaPathao = !is_null($shipment);

            return response()->json([
                'success' => true,
                'data' => [
                    'order_number' => $order->order_number,
                    'order_id' => $order->id,
                    'is_sent_via_pathao' => $isSentViaPathao,
                    'pathao_consignment_id' => $shipment ? $shipment->pathao_consignment_id : null,
                    'pathao_status' => $shipment ? $shipment->pathao_status : null,
                    'shipment_status' => $shipment ? $shipment->status : null,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking order status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if multiple orders were sent via Pathao (bulk lookup)
     * 
     * POST /api/pathao/orders/lookup/bulk
     * Body: { "order_numbers": ["ORD-001", "ORD-002", ...] }
     */
    public function bulkCheckOrderPathaoStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_numbers' => 'required|array|min:1|max:100',
            'order_numbers.*' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $orderNumbers = $request->order_numbers;

            // Fetch all orders with their shipments
            $orders = \App\Models\Order::with(['shipments' => function($query) {
                $query->whereNotNull('pathao_consignment_id');
            }])
            ->whereIn('order_number', $orderNumbers)
            ->get();

            // Build result array
            $results = [];
            foreach ($orderNumbers as $orderNumber) {
                $order = $orders->firstWhere('order_number', $orderNumber);

                if (!$order) {
                    $results[] = [
                        'order_number' => $orderNumber,
                        'order_id' => null,
                        'is_sent_via_pathao' => false,
                        'found' => false,
                        'error' => 'Order not found'
                    ];
                    continue;
                }

                $shipment = $order->shipments->first();
                $isSentViaPathao = !is_null($shipment);

                $results[] = [
                    'order_number' => $order->order_number,
                    'order_id' => $order->id,
                    'is_sent_via_pathao' => $isSentViaPathao,
                    'found' => true,
                    'pathao_consignment_id' => $shipment ? $shipment->pathao_consignment_id : null,
                    'pathao_status' => $shipment ? $shipment->pathao_status : null,
                    'shipment_status' => $shipment ? $shipment->status : null,
                ];
            }

            return response()->json([
                'success' => true,
                'total_requested' => count($orderNumbers),
                'total_found' => $orders->count(),
                'data' => $results
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error performing bulk lookup: ' . $e->getMessage()
            ], 500);
        }
    }
}
