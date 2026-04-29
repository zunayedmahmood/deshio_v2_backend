<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use App\Models\Store;
use App\Services\PathaoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

/**
 * MultiStoreShipmentController
 *
 * Handles Pathao shipment creation for multi-store orders.
 *
 * When an order has items from multiple stores:
 * - Create separate Pathao shipment for each store
 * - Each shipment uses the store's pathao_store_id
 * - Each shipment contains only items from that store
 *
 * Example: Order with 3 items from 3 stores
 * - Create Shipment 1: Store A's items → Pathao with Store A's pathao_store_id
 * - Create Shipment 2: Store B's items → Pathao with Store B's pathao_store_id
 * - Create Shipment 3: Store C's items → Pathao with Store C's pathao_store_id
 */
class MultiStoreShipmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Create Pathao shipments for multi-store order
     *
     * Analyzes order items, groups by store, creates separate shipment for each store
     *
     * POST /api/multi-store-shipments/orders/{orderId}/create-shipments
     */
    public function createMultiStoreShipments(Request $request, $orderId): JsonResponse
    {
        try {
            $order = Order::with(['items.store', 'items.product', 'customer'])->findOrFail($orderId);

            // Validate order is fulfilled and ready for shipment
            if (!in_array($order->status, ['confirmed', 'multi_store_assigned'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order must be confirmed before creating shipments',
                    'current_status' => $order->status,
                ], 400);
            }

            if ($order->fulfillment_status !== 'fulfilled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Order must be fulfilled before creating shipments',
                    'fulfillment_status' => $order->fulfillment_status,
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'recipient_name'      => 'required|string|max:255',
                'recipient_phone'     => 'required|string|max:20',
                'recipient_address'   => 'required|string',
                'recipient_city'      => 'required|integer', // Pathao city ID
                'recipient_zone'      => 'required|integer', // Pathao zone ID
                'recipient_area'      => 'required|integer', // Pathao area ID
                'delivery_type'       => 'nullable|in:Normal,On Demand',
                'item_type'           => 'nullable|in:Parcel,Document',
                'special_instruction' => 'nullable|string|max:500',
                'item_weight'         => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            // Group items by store
            $itemsByStore = $order->items->groupBy('store_id');

            if ($itemsByStore->count() === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order has no items assigned to stores',
                ], 400);
            }

            DB::beginTransaction();

            $createdShipments = [];
            $failedStores     = [];

            foreach ($itemsByStore as $storeId => $items) {
                if (!$storeId) {
                    $failedStores[] = [
                        'store_id' => null,
                        'reason'   => 'Some items not assigned to any store',
                        'items'    => $items->map(fn($i) => $i->product->name ?? $i->name ?? 'Unknown')->toArray(),
                    ];
                    continue;
                }

                $store = Store::find($storeId);

                if (!$store) {
                    $failedStores[] = [
                        'store_id' => $storeId,
                        'reason'   => 'Store not found',
                    ];
                    continue;
                }

                if (!$store->pathao_store_id) {
                    $failedStores[] = [
                        'store_id'   => $storeId,
                        'store_name' => $store->name,
                        'reason'     => 'Store does not have Pathao Store ID configured',
                    ];
                    continue;
                }

                // Calculate item value for this store's items
                $itemValue = $items->sum(function ($item) {
                    return $item->unit_price * $item->quantity;
                });

                // Calculate weight (distribute total weight proportionally or use provided)
                $itemWeight = $request->item_weight
                    ? ($request->item_weight / $itemsByStore->count())
                    : 0.5; // Default 0.5 kg per store

                // Cast amount to integer — Pathao requires integer, no decimals
                $amountToCollect = (int) round((float) str_replace(',', '', (string) ($order->payment_method === 'cod' ? $itemValue : 0)));
                $itemQuantity    = (int) $items->sum('quantity');

                // Build item description using product relation, not non-existent product_name column
                $itemDescription = (function ($items) {
                    $desc = $items->map(function ($item) {
                        $name = $item->product->name ?? $item->name ?? 'Unknown Item';
                        return "{$name} qty:{$item->quantity}";
                    })->join(', ');
                    return mb_strlen($desc) > 250 ? mb_substr($desc, 0, 247) . '...' : $desc;
                })($items);

                // Prepare Pathao API request
                $pathaoData = [
                    'store_id'            => (int) $store->pathao_store_id,
                    'merchant_order_id'   => $order->order_number . '-STORE-' . $store->id,
                    'recipient_name'      => $request->recipient_name,
                    'recipient_phone'     => $request->recipient_phone,
                    'recipient_address'   => $request->recipient_address,
                    'recipient_city'      => $request->recipient_city,
                    'recipient_zone'      => $request->recipient_zone,
                    'recipient_area'      => $request->recipient_area,
                    'delivery_type'       => ($request->delivery_type ?? 'Normal') === 'On Demand' ? 48 : 12,
                    'item_type'           => ($request->item_type ?? 'Parcel') === 'Document' ? 1 : 2,
                    'special_instruction' => $request->special_instruction,
                    'item_quantity'       => $itemQuantity,
                    'item_weight'         => $itemWeight,
                    'amount_to_collect'   => $amountToCollect,
                    'item_description'    => $itemDescription,
                ];

                try {
                    // Call Pathao API
                    $pathaoResponse = $this->createPathaoShipment($pathaoData);

                    if (!$pathaoResponse['success']) {
                        $failedStores[] = [
                            'store_id'    => $storeId,
                            'store_name'  => $store->name,
                            'reason'      => $pathaoResponse['message'] ?? 'Pathao API error',
                            'pathao_error' => $pathaoResponse['error'] ?? null,
                        ];
                        continue;
                    }

                    // Create shipment record
                    $shipment = Shipment::create([
                        'order_id'                => $order->id,
                        'store_id'                => $store->id,
                        'shipment_number'         => 'SHIP-' . $order->order_number . '-' . $store->id . '-' . time(),
                        'carrier_name'            => 'Pathao',
                        'pathao_consignment_id'   => $pathaoResponse['data']['consignment_id'] ?? null,
                        'pathao_tracking_number'  => $pathaoResponse['data']['merchant_order_id'] ?? null,
                        'status'                  => 'pending',
                        'shipped_at'              => now(),
                        'recipient_name'          => $request->recipient_name,
                        'recipient_phone'         => $request->recipient_phone,
                        'recipient_address'       => $request->recipient_address,
                        'item_quantity'           => $itemQuantity,
                        'item_weight'             => $itemWeight,
                        'amount_to_collect'       => $amountToCollect,
                        'metadata'                => [
                            'pathao_store_id'  => $store->pathao_store_id,
                            'items'            => $items->map(fn($item) => [
                                'order_item_id' => $item->id,
                                'product_name'  => $item->product->name ?? $item->name ?? 'Unknown Item',
                                'quantity'      => $item->quantity,
                            ])->toArray(),
                            'pathao_response'  => $pathaoResponse['data'] ?? null,
                        ],
                    ]);

                    $createdShipments[] = [
                        'shipment_id'            => $shipment->id,
                        'shipment_number'        => $shipment->shipment_number,
                        'store_id'               => $store->id,
                        'store_name'             => $store->name,
                        'pathao_consignment_id'  => $shipment->pathao_consignment_id,
                        'pathao_tracking_number' => $shipment->pathao_tracking_number,
                        'items_count'            => $items->count(),
                        'items'                  => $items->map(fn($item) => [
                            'product_name' => $item->product->name ?? $item->name ?? 'Unknown Item',
                            'quantity'     => $item->quantity,
                        ])->toArray(),
                        'amount_to_collect'      => $amountToCollect,
                    ];

                } catch (\Exception $e) {
                    $failedStores[] = [
                        'store_id'   => $storeId,
                        'store_name' => $store->name,
                        'reason'     => 'Exception during Pathao API call',
                        'error'      => $e->getMessage(),
                    ];
                }
            }

            if (!empty($failedStores) && empty($createdShipments)) {
                DB::rollBack();
                return response()->json([
                    'success'       => false,
                    'message'       => 'Failed to create any shipments',
                    'failed_stores' => $failedStores,
                ], 500);
            }

            // Partial success is allowed - some stores created, some failed
            if (!empty($createdShipments)) {
                // Update order status
                $order->update([
                    'status'     => 'shipped',
                    'shipped_at' => now(),
                ]);
            }

            DB::commit();

            $response = [
                'success' => true,
                'message' => count($createdShipments) > 0
                    ? 'Shipments created successfully'
                    : 'Some shipments failed',
                'data'    => [
                    'order_id'              => $order->id,
                    'order_number'          => $order->order_number,
                    'total_stores'          => $itemsByStore->count(),
                    'successful_shipments'  => count($createdShipments),
                    'failed_shipments'      => count($failedStores),
                    'shipments'             => $createdShipments,
                ],
            ];

            if (!empty($failedStores)) {
                $response['warnings'] = [
                    'message'       => 'Some shipments could not be created',
                    'failed_stores' => $failedStores,
                ];
            }

            return response()->json($response, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create shipments',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get shipment summary for multi-store order
     * Shows all shipments created for an order (one per store)
     *
     * GET /api/multi-store-shipments/orders/{orderId}/shipments
     */
    public function getOrderShipments($orderId): JsonResponse
    {
        try {
            $order = Order::with(['shipments.store'])->findOrFail($orderId);

            $shipmentsData = $order->shipments->map(function ($shipment) {
                return [
                    'shipment_id'            => $shipment->id,
                    'shipment_number'        => $shipment->shipment_number,
                    'store_id'               => $shipment->store_id,
                    'store_name'             => $shipment->store->name ?? 'Unknown',
                    'carrier_name'           => $shipment->carrier_name,
                    'pathao_consignment_id'  => $shipment->pathao_consignment_id,
                    'pathao_tracking_number' => $shipment->pathao_tracking_number,
                    'status'                 => $shipment->status,
                    'shipped_at'             => $shipment->shipped_at?->format('Y-m-d H:i:s'),
                    'delivered_at'           => $shipment->delivered_at?->format('Y-m-d H:i:s'),
                    'item_quantity'          => $shipment->item_quantity,
                    'amount_to_collect'      => $shipment->amount_to_collect,
                    'items'                  => $shipment->metadata['items'] ?? [],
                ];
            });

            $storesInvolved = $order->shipments->pluck('store_id')->unique()->count();
            $isMultiStore   = $storesInvolved > 1;

            return response()->json([
                'success' => true,
                'data'    => [
                    'order_id'         => $order->id,
                    'order_number'     => $order->order_number,
                    'is_multi_store'   => $isMultiStore,
                    'total_shipments'  => $order->shipments->count(),
                    'stores_involved'  => $storesInvolved,
                    'shipments'        => $shipmentsData,
                    'summary'          => [
                        'pending'    => $order->shipments->where('status', 'pending')->count(),
                        'picked_up'  => $order->shipments->where('status', 'picked_up')->count(),
                        'in_transit' => $order->shipments->where('status', 'in_transit')->count(),
                        'delivered'  => $order->shipments->where('status', 'delivered')->count(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get order shipments',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Track all shipments for a multi-store order
     *
     * GET /api/multi-store-shipments/orders/{orderId}/track-all
     */
    public function trackAllShipments($orderId): JsonResponse
    {
        try {
            $order = Order::with(['shipments.store'])->findOrFail($orderId);

            $trackingData = [];

            foreach ($order->shipments as $shipment) {
                if (!$shipment->pathao_consignment_id) {
                    $trackingData[] = [
                        'shipment_id' => $shipment->id,
                        'store_name'  => $shipment->store->name ?? 'Unknown',
                        'status'      => $shipment->status,
                        'error'       => 'No Pathao consignment ID',
                    ];
                    continue;
                }

                try {
                    $tracking = $this->trackPathaoShipment($shipment->pathao_consignment_id);

                    $trackingData[] = [
                        'shipment_id'            => $shipment->id,
                        'shipment_number'        => $shipment->shipment_number,
                        'store_id'               => $shipment->store_id,
                        'store_name'             => $shipment->store->name,
                        'pathao_consignment_id'  => $shipment->pathao_consignment_id,
                        'current_status'         => $tracking['status'] ?? $shipment->status,
                        'tracking_details'       => $tracking['data'] ?? null,
                    ];

                    // Update shipment status if changed
                    if (isset($tracking['status']) && $tracking['status'] !== $shipment->status) {
                        $shipment->update(['status' => $tracking['status']]);
                    }

                } catch (\Exception $e) {
                    $trackingData[] = [
                        'shipment_id' => $shipment->id,
                        'store_name'  => $shipment->store->name ?? 'Unknown',
                        'status'      => $shipment->status,
                        'error'       => $e->getMessage(),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'order_id'         => $order->id,
                    'order_number'     => $order->order_number,
                    'total_shipments'  => count($trackingData),
                    'tracking'         => $trackingData,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to track shipments',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Call Pathao API to create shipment.
     * Uses PathaoService to obtain a proper OAuth access token.
     * The old $accessToken parameter (which was just pathao_key, not a real token)
     * has been removed — auth is now handled centrally via PathaoService.
     */
    private function createPathaoShipment(array $data): array
    {
        try {
            $pathaoService = new PathaoService();
            $token = $pathaoService->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])->post('https://api-hermes.pathao.com/aladdin/api/v1/orders', $data);

            Log::info('Pathao MultiStore Create Order', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'payload' => $data,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json()['data'] ?? $response->json(),
                ];
            }

            return [
                'success'     => false,
                'message'     => 'Pathao API returned error',
                'error'       => $response->json()['message'] ?? $response->body() ?? 'Unknown error',
                'status_code' => $response->status(),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Exception calling Pathao API',
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Track Pathao shipment.
     * Uses PathaoService for auth instead of passing pathao_key as a token.
     */
    private function trackPathaoShipment(string $consignmentId): array
    {
        try {
            $pathaoService = new PathaoService();
            $token = $pathaoService->getAccessToken();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ])->get("https://api-hermes.pathao.com/aladdin/api/v1/orders/{$consignmentId}");

            if ($response->successful()) {
                $data = $response->json()['data'] ?? $response->json();

                return [
                    'status' => $this->mapPathaoStatus($data['order_status'] ?? 'pending'),
                    'data'   => $data,
                ];
            }

            throw new \Exception('Pathao API error: ' . ($response->json()['message'] ?? 'Unknown'));

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Map Pathao status to our internal status
     */
    private function mapPathaoStatus(string $pathaoStatus): string
    {
        $statusMap = [
            'Pending'          => 'pending',
            'Pickup_Requested' => 'pickup_requested',
            'Picked_Up'        => 'picked_up',
            'In_Transit'       => 'in_transit',
            'Delivered'        => 'delivered',
            'Returned'         => 'returned',
            'Cancelled'        => 'cancelled',
        ];

        return $statusMap[$pathaoStatus] ?? 'pending';
    }
}