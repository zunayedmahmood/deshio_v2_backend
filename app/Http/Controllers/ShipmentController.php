<?php

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Models\Order;
use App\Models\Store;
use App\Models\PathaoBulkBatch;
use App\Traits\DatabaseAgnosticSearch;
use App\Services\PathaoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ShipmentController extends Controller
{
    use DatabaseAgnosticSearch;

    private const PATHAO_RATE_LIMIT_PER_MINUTE = 19;
    private const PATHAO_SPACING_MS = 3158; // 60,000 / 19, rounded up.
    private const PATHAO_BULK_ORDER_TYPES = ['social_commerce', 'ecommerce'];
    private const PATHAO_BULK_BLOCKED_ORDER_STATUSES = ['cancelled', 'refunded', 'returned', 'delivered'];
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
        $internalCall = $shipmentOrId instanceof Shipment;
        $shipment = $internalCall
            ? $shipmentOrId
            : Shipment::with(['order.items.product', 'store', 'customer'])->findOrFail($shipmentOrId);

        try {
            $sent = $this->sendShipmentToPathao($shipment);

            if ($internalCall) {
                return $sent;
            }

            return response()->json([
                'success' => true,
                'message' => 'Shipment sent to Pathao successfully',
                'data' => $sent->fresh(['order.customer', 'store']),
            ]);
        } catch (\Exception $e) {
            Log::error('Pathao API Error - Send Shipment', [
                'shipment_id' => $shipment->id ?? null,
                'error' => $e->getMessage(),
            ]);

            if ($internalCall) {
                throw $e;
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to send to Pathao: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Actual Pathao send implementation used by single-send and bulk tick.
     * Kept small enough that bulk endpoints never attempt to send a whole batch
     * inside one HTTP request.
     */
    protected function sendShipmentToPathao(Shipment $shipment): Shipment
    {
        $shipment->loadMissing(['order.items.product', 'store', 'customer']);

        if (!$shipment->isPending()) {
            throw new \Exception('Only pending shipments can be sent to Pathao');
        }

        if ($shipment->pathao_consignment_id) {
            throw new \Exception('Shipment already sent to Pathao');
        }

        $order = $shipment->order;
        $store = $shipment->store;
        $deliveryAddress = is_array($shipment->delivery_address) ? $shipment->delivery_address : [];

        if (!$order) {
            throw new \Exception('Shipment has no linked order');
        }

        if (!$store || !$store->pathao_store_id) {
            throw new \Exception('Store not registered with Pathao. Please configure store Pathao details first.');
        }

        // Ensure COD amount is fresh before Pathao payload is created.
        if ($shipment->cod_amount === null) {
            $shipment->cod_amount = $order->outstanding_amount !== null
                ? (float) $order->outstanding_amount
                : max(0, (float) ($order->total_amount ?? 0) - (float) ($order->paid_amount ?? 0));
            $shipment->save();
        }

        $recipientAddress = $this->buildPathaoRecipientAddress($shipment);
        if ($recipientAddress === '') {
            throw new \Exception('Delivery address is empty. Please provide a full address.');
        }
        if (mb_strlen($recipientAddress) < 10) {
            throw new \Exception('Recipient address is too short. Minimum 10 characters required for Pathao.');
        }

        $autoLocation = config('services.pathao.auto_location', true);
        $hasLocationIds = !empty($deliveryAddress['pathao_city_id'])
            && !empty($deliveryAddress['pathao_zone_id'])
            && !empty($deliveryAddress['pathao_area_id']);

        if (!$autoLocation && !$hasLocationIds) {
            throw new \Exception('Delivery address missing Pathao city/zone/area IDs.');
        }

        $totalWeight = $order->items->sum(function ($item) {
            return ($item->product->weight ?? 0.5) * $item->quantity;
        });
        $totalWeight = max((float) $totalWeight, 0.5);

        $pathaoData = [
            'store_id' => (int) $store->pathao_store_id,
            'merchant_order_id' => $order->order_number,
            'recipient_name' => $shipment->recipient_name ?: ($order->customer->name ?? 'Customer'),
            'recipient_phone' => $shipment->recipient_phone ?: ($order->customer->phone ?? ''),
            'recipient_address' => $recipientAddress,
            'delivery_type' => $shipment->delivery_type === 'express' ? 12 : 48,
            'item_type' => 2,
            'special_instruction' => $shipment->special_instructions ?? '',
            'item_quantity' => (int) $order->items->sum('quantity'),
            'item_weight' => $totalWeight,
            'amount_to_collect' => (int) round((float) str_replace(',', '', (string) ($shipment->cod_amount ?? 0))),
            'item_description' => $shipment->getPackageDescription(),
        ];

        if ($hasLocationIds) {
            $pathaoData['recipient_city'] = $deliveryAddress['pathao_city_id'];
            $pathaoData['recipient_zone'] = $deliveryAddress['pathao_zone_id'];
            $pathaoData['recipient_area'] = $deliveryAddress['pathao_area_id'];
        }

        $pathaoService = new PathaoService();
        $pathaoService->setStoreId($store->pathao_store_id);
        $result = $pathaoService->createOrder($pathaoData);

        if (empty($result['success'])) {
            $err = $result['error'] ?? 'Unknown Pathao error';
            if (is_array($err)) {
                $err = json_encode($err);
            }
            throw new \Exception((string) $err);
        }

        $data = $result['data'] ?? [];
        $consignmentId = $data['consignment_id'] ?? null;

        $shipment->pathao_consignment_id = $consignmentId;
        $shipment->pathao_tracking_number = $data['invoice_id'] ?? ($data['tracking_number'] ?? null);
        $shipment->pathao_status = 'pickup_requested';
        $shipment->pathao_response = $result['response'] ?? $result;
        $shipment->status = 'pickup_requested';
        $shipment->pickup_requested_at = now();

        if (isset($data['delivery_fee'])) {
            $shipment->delivery_fee = $data['delivery_fee'];
        }

        $shipment->addStatusHistory('pickup_requested', 'Sent to Pathao. Consignment ID: ' . ($consignmentId ?: 'N/A'));
        $shipment->save();

        // Keep order page and reports aligned with courier submission.
        $order->forceFill([
            'status' => 'shipped',
            'shipped_at' => now(),
            'tracking_number' => $consignmentId,
            'carrier_name' => 'Pathao',
            'intended_courier' => 'pathao',
        ])->save();

        return $shipment;
    }

    protected function buildPathaoRecipientAddress(Shipment $shipment): string
    {
        $deliveryAddress = is_array($shipment->delivery_address) ? $shipment->delivery_address : [];
        $orderAddress = is_array($shipment->order?->shipping_address ?? null) ? $shipment->order->shipping_address : [];

        $parts = [
            $this->firstScalar($deliveryAddress, ['address_line_1', 'address_line1', 'street', 'address'])
                ?: $this->firstScalar($orderAddress, ['address_line_1', 'address_line1', 'street', 'address']),
            $this->firstScalar($deliveryAddress, ['address_line_2', 'address_line2'])
                ?: $this->firstScalar($orderAddress, ['address_line_2', 'address_line2']),
            $this->firstScalar($deliveryAddress, ['landmark'])
                ?: $this->firstScalar($orderAddress, ['landmark']),
            $this->firstScalar($deliveryAddress, ['area', 'thana', 'upazila'])
                ?: $this->firstScalar($orderAddress, ['area', 'thana', 'upazila']),
            $this->firstScalar($deliveryAddress, ['city', 'district'])
                ?: $this->firstScalar($orderAddress, ['city', 'district']),
            $this->firstScalar($deliveryAddress, ['postal_code', 'zip'])
                ?: $this->firstScalar($orderAddress, ['postal_code', 'zip']),
        ];

        return trim(implode(', ', array_values(array_filter($parts))));
    }

    protected function firstScalar(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source) || !is_scalar($source[$key])) {
                continue;
            }
            $value = trim((string) $source[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Bulk send shipments to Pathao.
     * This now creates a resumable batch instead of sending all shipments in one request.
     */
    public function bulkSendToPathao(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shipment_ids' => 'required|array|min:1|max:500',
            'shipment_ids.*' => 'integer|exists:shipments,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $shipments = Shipment::with(['order', 'store'])
            ->whereIn('id', $request->shipment_ids)
            ->get()
            ->keyBy('id');

        $queuedShipmentIds = [];
        $immediateFailures = [];

        foreach ($request->shipment_ids as $shipmentId) {
            $shipment = $shipments->get((int) $shipmentId);
            if (!$shipment) {
                $immediateFailures[] = $this->bulkFailure(null, null, null, "Shipment {$shipmentId} not found");
                continue;
            }

            $failure = $this->validateShipmentForPathaoBulk($shipment);
            if ($failure) {
                $immediateFailures[] = $this->bulkFailure($shipment->order?->id, $shipment->order?->order_number, $shipment->shipment_number, $failure);
                continue;
            }

            $queuedShipmentIds[] = $shipment->id;
        }

        $batch = $this->createPathaoBulkBatch($queuedShipmentIds, $immediateFailures, []);

        return response()->json([
            'success' => true,
            'message' => count($queuedShipmentIds) . ' shipment(s) queued for Pathao at 19 orders/minute.',
            'data' => $this->formatBulkStartResponse($batch, $immediateFailures),
        ]);
    }

    /**
     * Bulk send selected packed social-commerce/e-commerce orders to Pathao.
     * Selected rows come from the /orders page filters/select-all; backend still guards eligibility.
     */
    public function bulkSendOrdersToPathao(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_ids' => 'required|array|min:1|max:500',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $orderIds = array_values(array_unique(array_map('intval', $request->order_ids)));
        $orders = Order::with(['customer', 'store', 'items.product', 'shipments'])
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        $queuedShipmentIds = [];
        $immediateFailures = [];

        DB::beginTransaction();
        try {
            foreach ($orderIds as $orderId) {
                $order = $orders->get($orderId);
                if (!$order) {
                    $immediateFailures[] = $this->bulkFailure($orderId, null, null, "Order {$orderId} not found");
                    continue;
                }

                $failure = $this->validateOrderForPathaoBulk($order);
                if ($failure) {
                    $immediateFailures[] = $this->bulkFailure($order->id, $order->order_number, null, $failure);
                    continue;
                }

                $alreadySent = $order->shipments->first(fn ($shipment) => !empty($shipment->pathao_consignment_id));
                if ($alreadySent) {
                    $immediateFailures[] = $this->bulkFailure(
                        $order->id,
                        $order->order_number,
                        $alreadySent->shipment_number,
                        'Already sent to Pathao. Consignment ID: ' . $alreadySent->pathao_consignment_id
                    );
                    continue;
                }

                $activeShipment = $order->shipments
                    ->filter(fn ($shipment) => !in_array($shipment->status, ['cancelled', 'delivered', 'returned']))
                    ->sortByDesc('created_at')
                    ->first();

                if ($activeShipment && !$activeShipment->isPending()) {
                    $immediateFailures[] = $this->bulkFailure(
                        $order->id,
                        $order->order_number,
                        $activeShipment->shipment_number,
                        "Shipment status is {$activeShipment->status}, expected pending"
                    );
                    continue;
                }

                $shipment = $activeShipment ?: Shipment::createFromOrder($order, [
                    'delivery_type' => 'home_delivery',
                    'package_weight' => 1.0,
                    'created_by' => Auth::id(),
                ]);

                $queuedShipmentIds[] = $shipment->id;
            }

            $batch = $this->createPathaoBulkBatch($queuedShipmentIds, $immediateFailures, $orderIds);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to prepare Pathao bulk batch: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => count($queuedShipmentIds) . ' order(s) queued for Pathao at 19 orders/minute.',
            'data' => $this->formatBulkStartResponse($batch, $immediateFailures),
        ]);
    }

    /**
     * Process at most one due Pathao shipment for a batch. The endpoint is idempotent
     * and intentionally tiny so the frontend never waits for a full bulk send request.
     */
    public function runPathaoQueueTick(Request $request)
    {
        $batchCode = $request->input('batch_code');

        $reservation = DB::transaction(function () use ($batchCode) {
            $query = PathaoBulkBatch::whereIn('status', ['pending', 'processing'])
                ->orderBy('created_at')
                ->lockForUpdate();

            if ($batchCode) {
                $query->where('batch_code', $batchCode);
            }

            $batch = $query->first();
            if (!$batch) {
                return ['batch_id' => null, 'shipment_id' => null, 'message' => 'No Pathao batch is due right now.'];
            }

            if ($batch->status === 'pending') {
                $batch->markAsProcessing();
                $batch->refresh();
            }

            if ($batch->total_shipments <= 0) {
                $batch->update(['status' => 'completed', 'completed_at' => now()]);
                return ['batch_id' => $batch->id, 'shipment_id' => null, 'message' => 'Batch completed.'];
            }

            $state = $this->getBatchState($batch);
            $this->expireInProgressReservations($state);

            $nextAvailableAt = !empty($state['next_available_at']) ? Carbon::parse($state['next_available_at']) : Carbon::now();
            if (Carbon::now()->lt($nextAvailableAt)) {
                $this->saveBatchState($batch, $state);
                return ['batch_id' => $batch->id, 'shipment_id' => null, 'message' => 'Next Pathao send is not due yet.'];
            }

            $shipmentId = $this->nextPendingShipmentId($batch, $state);
            if (!$shipmentId) {
                $this->saveBatchState($batch, $state);
                $batch->checkCompletion();
                return ['batch_id' => $batch->id, 'shipment_id' => null, 'message' => 'Batch completed.'];
            }

            // Reserve the shipment and the rate slot before the external API call.
            // The row lock prevents duplicate reservations from browser polling + scheduler overlap.
            $now = Carbon::now();
            $state['last_attempted_at'] = $now->toISOString();
            $state['next_available_at'] = $now->copy()->addMilliseconds(self::PATHAO_SPACING_MS)->toISOString();
            $state['in_progress'][(string) $shipmentId] = $now->copy()->addMinutes(3)->toISOString();
            $this->saveBatchState($batch, $state);

            return ['batch_id' => $batch->id, 'shipment_id' => $shipmentId, 'message' => 'Reserved one Pathao shipment.'];
        });

        if (!$reservation['batch_id']) {
            return response()->json([
                'success' => true,
                'message' => $reservation['message'],
                'data' => null,
            ]);
        }

        $batch = PathaoBulkBatch::findOrFail($reservation['batch_id']);
        $shipmentId = $reservation['shipment_id'];

        if (!$shipmentId) {
            return response()->json([
                'success' => true,
                'message' => $reservation['message'],
                'data' => $this->formatBulkSummary($batch->fresh()),
            ]);
        }

        try {
            $shipment = Shipment::with(['order.items.product', 'store', 'customer'])->find($shipmentId);
            if (!$shipment) {
                throw new \Exception('Shipment not found');
            }

            $failure = $this->validateShipmentForPathaoBulk($shipment);
            if ($failure) {
                throw new \Exception($failure);
            }

            $sent = $this->sendShipmentToPathao($shipment);
            $this->releaseInProgressReservation($batch, (int) $shipmentId);
            $batch->recordShipmentResult($shipmentId, true, 'Sent to Pathao successfully', $sent->pathao_consignment_id, 'Order was sent to Pathao successfully.');
        } catch (\Exception $e) {
            Log::error('Pathao bulk tick failed', [
                'batch_code' => $batch->batch_code,
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage(),
            ]);
            $this->releaseInProgressReservation($batch, (int) $shipmentId);
            $batch->recordShipmentResult($shipmentId, false, $e->getMessage(), null, $this->userMessageForPathaoFailure($e->getMessage()));
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatBulkSummary($batch->fresh()),
        ]);
    }

    public function bulkStatus(string $batchCode)
    {
        $batch = PathaoBulkBatch::where('batch_code', $batchCode)->firstOrFail();
        return response()->json([
            'success' => true,
            'data' => $this->formatBulkSummary($batch),
        ]);
    }

    public function bulkStatusDetails(string $batchCode)
    {
        $batch = PathaoBulkBatch::where('batch_code', $batchCode)->firstOrFail();
        $state = $this->getBatchState($batch);
        $immediateFailures = $state['immediate_failures'] ?? [];

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $this->formatBulkSummary($batch),
                'immediate_failures' => $immediateFailures,
                'results' => array_merge($this->formatImmediateFailuresForDetails($immediateFailures), $batch->getDetailedResults()),
            ],
        ]);
    }

    public function listBulkBatches(Request $request)
    {
        $query = PathaoBulkBatch::query()->orderByDesc('created_at');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('days')) {
            $query->where('created_at', '>=', now()->subDays((int) $request->days));
        }

        $batches = $query->paginate((int) $request->input('per_page', 15));
        $batches->getCollection()->transform(fn ($batch) => $this->formatBulkSummary($batch));

        return response()->json([
            'success' => true,
            'data' => $batches,
        ]);
    }

    public function bulkCancel(string $batchCode)
    {
        $batch = PathaoBulkBatch::where('batch_code', $batchCode)->firstOrFail();
        $batch->cancel();
        return response()->json([
            'success' => true,
            'message' => 'Pathao batch cancelled.',
            'data' => $this->formatBulkSummary($batch->fresh()),
        ]);
    }

    public function retryFailedPathaoBatch(string $batchCode)
    {
        $batch = PathaoBulkBatch::where('batch_code', $batchCode)->firstOrFail();
        $failedShipmentIds = collect($batch->results ?? [])
            ->filter(fn ($result) => empty($result['success']))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $newBatch = $this->createPathaoBulkBatch($failedShipmentIds, [], []);

        return response()->json([
            'success' => true,
            'message' => count($failedShipmentIds) . ' failed shipment(s) queued for retry.',
            'data' => $this->formatBulkStartResponse($newBatch, []),
        ]);
    }

    protected function validateOrderForPathaoBulk(Order $order): ?string
    {
        if (!in_array($order->order_type, self::PATHAO_BULK_ORDER_TYPES, true)) {
            return 'Only social-commerce and e-commerce orders can be sent to Pathao in bulk.';
        }

        if ($order->fulfillment_status !== 'fulfilled') {
            return 'Order is not packed/fulfilled yet. Pack it from the Online Order Packing page first.';
        }

        if (in_array($order->status, self::PATHAO_BULK_BLOCKED_ORDER_STATUSES, true)) {
            return "Order status is {$order->status}; it cannot be sent to Pathao.";
        }

        if (!$order->store) {
            return 'Order has no assigned store.';
        }

        if (!$order->store->pathao_store_id) {
            return 'Assigned store is not configured with a Pathao Store ID.';
        }

        return null;
    }

    protected function validateShipmentForPathaoBulk(Shipment $shipment): ?string
    {
        if (!$shipment->isPending()) {
            return "Shipment status is {$shipment->status}, expected pending.";
        }

        if ($shipment->pathao_consignment_id) {
            return 'Already sent to Pathao. Consignment ID: ' . $shipment->pathao_consignment_id;
        }

        if (!$shipment->store || !$shipment->store->pathao_store_id) {
            return 'Shipment store is not configured with a Pathao Store ID.';
        }

        if ($shipment->order) {
            return $this->validateOrderForPathaoBulk($shipment->order);
        }

        return null;
    }

    protected function createPathaoBulkBatch(array $shipmentIds, array $immediateFailures = [], array $orderIds = []): PathaoBulkBatch
    {
        $now = Carbon::now();
        return PathaoBulkBatch::create([
            'created_by' => Auth::id(),
            'status' => count($shipmentIds) > 0 ? 'processing' : 'completed',
            'total_shipments' => count($shipmentIds),
            'processed_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'shipment_ids' => array_values($shipmentIds),
            'results' => [],
            'started_at' => $now,
            'completed_at' => count($shipmentIds) > 0 ? null : $now,
            'error_summary' => json_encode([
                'immediate_failures' => $immediateFailures,
                'selected_order_ids' => $orderIds,
                'rate_limit_per_minute' => self::PATHAO_RATE_LIMIT_PER_MINUTE,
                'spacing_ms' => self::PATHAO_SPACING_MS,
                'last_attempted_at' => null,
                'next_available_at' => $now->toISOString(),
                'in_progress' => [],
            ]),
        ]);
    }

    protected function nextPendingShipmentId(PathaoBulkBatch $batch, ?array $state = null): ?int
    {
        $results = $batch->results ?? [];
        $inProgress = ($state['in_progress'] ?? []);

        foreach (($batch->shipment_ids ?? []) as $shipmentId) {
            $stringId = (string) $shipmentId;
            if (
                !array_key_exists($stringId, $results)
                && !array_key_exists((int) $shipmentId, $results)
                && !array_key_exists($stringId, $inProgress)
                && !array_key_exists((int) $shipmentId, $inProgress)
            ) {
                return (int) $shipmentId;
            }
        }
        return null;
    }

    protected function expireInProgressReservations(array &$state): void
    {
        $inProgress = $state['in_progress'] ?? [];
        $now = Carbon::now();

        foreach ($inProgress as $shipmentId => $reservedUntil) {
            try {
                if ($reservedUntil && Carbon::parse($reservedUntil)->gt($now)) {
                    continue;
                }
            } catch (\Exception $e) {
                // Invalid reservation timestamp should not block the shipment forever.
            }

            unset($inProgress[$shipmentId]);
        }

        $state['in_progress'] = $inProgress;
    }

    protected function releaseInProgressReservation(PathaoBulkBatch $batch, int $shipmentId): void
    {
        $state = $this->getBatchState($batch->fresh());
        unset($state['in_progress'][(string) $shipmentId], $state['in_progress'][$shipmentId]);
        $this->saveBatchState($batch, $state);
    }

    protected function getBatchState(PathaoBulkBatch $batch): array
    {
        $state = [];
        if ($batch->error_summary) {
            $decoded = json_decode($batch->error_summary, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }
        $state['rate_limit_per_minute'] = $state['rate_limit_per_minute'] ?? self::PATHAO_RATE_LIMIT_PER_MINUTE;
        $state['spacing_ms'] = $state['spacing_ms'] ?? self::PATHAO_SPACING_MS;
        $state['next_available_at'] = $state['next_available_at'] ?? Carbon::now()->toISOString();
        $state['immediate_failures'] = $state['immediate_failures'] ?? [];
        $state['in_progress'] = $state['in_progress'] ?? [];
        return $state;
    }

    protected function saveBatchState(PathaoBulkBatch $batch, array $state): void
    {
        $batch->error_summary = json_encode($state);
        $batch->save();
    }

    protected function formatBulkStartResponse(PathaoBulkBatch $batch, array $immediateFailures): array
    {
        return [
            'batch_code' => $batch->batch_code,
            'batch_id' => $batch->id,
            'queued_count' => (int) $batch->total_shipments,
            'immediate_failures' => $immediateFailures,
            'rate_limit_per_minute' => self::PATHAO_RATE_LIMIT_PER_MINUTE,
            'spacing_ms' => self::PATHAO_SPACING_MS,
            'estimated_seconds' => $batch->total_shipments > 0
                ? (int) ceil(max(0, $batch->total_shipments - 1) * 60 / self::PATHAO_RATE_LIMIT_PER_MINUTE)
                : 0,
            'status_url' => url('/api/shipments/bulk-status/' . $batch->batch_code),
        ];
    }

    protected function formatBulkSummary(PathaoBulkBatch $batch): array
    {
        $state = $this->getBatchState($batch);
        $summary = $batch->getSummary();
        $summary['rate_limit_per_minute'] = (int) $state['rate_limit_per_minute'];
        $summary['spacing_ms'] = (int) $state['spacing_ms'];
        $summary['next_available_at'] = $state['next_available_at'] ?? null;
        $summary['immediate_failed'] = count($state['immediate_failures'] ?? []);
        $summary['display_total'] = $summary['total'] + $summary['immediate_failed'];
        $summary['display_processed'] = $summary['processed'] + $summary['immediate_failed'];
        return $summary;
    }

    protected function formatImmediateFailuresForDetails(array $failures): array
    {
        return array_map(function ($failure) {
            return [
                'shipment_id' => null,
                'shipment_number' => $failure['shipment_number'] ?? null,
                'order_number' => $failure['order_number'] ?? null,
                'success' => false,
                'message' => $failure['user_message'] ?? $this->userMessageForPathaoFailure($failure['reason'] ?? 'Failed before queueing'),
                'user_message' => $failure['user_message'] ?? $this->userMessageForPathaoFailure($failure['reason'] ?? 'Failed before queueing'),
                'raw_message' => $failure['reason'] ?? null,
                'consignment_id' => null,
                'processed_at' => null,
            ];
        }, $failures);
    }

    protected function bulkFailure($orderId, $orderNumber, $shipmentNumber, string $reason): array
    {
        return [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'shipment_number' => $shipmentNumber,
            'reason' => $reason,
            'user_message' => $this->userMessageForPathaoFailure($reason),
        ];
    }

    /**
     * Convert backend/Pathao technical errors into messages an operations user can act on.
     * The raw error is still logged/stored, but frontend toasts should show this message.
     */
    protected function userMessageForPathaoFailure(?string $reason): string
    {
        $text = trim((string) $reason);
        $lower = strtolower($text);

        if ($text === '') {
            return 'Pathao did not accept this order. Please review the order details and try again.';
        }

        if (str_contains($lower, 'already sent') || str_contains($lower, 'consignment')) {
            return 'This order was already sent to Pathao, so it was skipped to avoid creating a duplicate parcel.';
        }

        if (str_contains($lower, 'not packed') || str_contains($lower, 'not fulfilled') || str_contains($lower, 'packed/fulfilled')) {
            return 'This order is not packed yet. Pack it from the Online Order Packing page first, then send it to Pathao.';
        }

        if (str_contains($lower, 'only social-commerce') || str_contains($lower, 'e-commerce')) {
            return 'Only social-commerce and e-commerce delivery orders can be sent to Pathao from this bulk action.';
        }

        if (str_contains($lower, 'cancelled') || str_contains($lower, 'refunded') || str_contains($lower, 'returned') || str_contains($lower, 'delivered')) {
            return 'This order is already closed/cancelled/returned, so it was not sent to Pathao.';
        }

        if (str_contains($lower, 'no assigned store')) {
            return 'This order has no assigned store. Assign a store before sending it to Pathao.';
        }

        if (str_contains($lower, 'store') && (str_contains($lower, 'pathao store id') || str_contains($lower, 'not registered') || str_contains($lower, 'not configured'))) {
            return 'The assigned store is missing its Pathao Store ID. Add the Pathao Store ID in Store settings, then retry.';
        }

        if (str_contains($lower, 'shipment status') || str_contains($lower, 'only pending shipments') || str_contains($lower, 'expected pending')) {
            return 'This order already has a shipment that is not ready for Pathao. Review the shipment status before retrying.';
        }

        if (str_contains($lower, 'address') && (str_contains($lower, 'empty') || str_contains($lower, 'short') || str_contains($lower, 'minimum'))) {
            return 'The delivery address is missing or too short for Pathao. Open the order, fix the full delivery address, then retry.';
        }

        if (str_contains($lower, 'city') || str_contains($lower, 'zone') || str_contains($lower, 'area')) {
            return 'Pathao could not identify the delivery area. Fix the city/zone/area or full address in the order, then retry.';
        }

        if (str_contains($lower, 'phone') || str_contains($lower, 'mobile') || str_contains($lower, 'recipient')) {
            return 'The recipient phone/name looks invalid for Pathao. Check the customer delivery details, then retry.';
        }

        if (str_contains($lower, 'weight')) {
            return 'The parcel weight is not acceptable for Pathao. Check product/package weight, then retry.';
        }

        if (str_contains($lower, 'amount') || str_contains($lower, 'cod') || str_contains($lower, 'collect')) {
            return 'The COD/collection amount was not accepted by Pathao. Check the order due amount, then retry.';
        }

        if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out') || str_contains($lower, 'connection') || str_contains($lower, 'network')) {
            return 'Pathao connection was temporarily unavailable. The order can be retried safely; duplicates are blocked.';
        }

        if (str_contains($lower, 'unauthorized') || str_contains($lower, 'token') || str_contains($lower, 'auth')) {
            return 'Pathao login/token failed. Refresh the Pathao API credentials, then retry this order.';
        }

        if (str_contains($lower, 'duplicate') || str_contains($lower, 'merchant_order_id') || str_contains($lower, 'merchant order')) {
            return 'Pathao says this order number already exists. Check Pathao before retrying to avoid a duplicate parcel.';
        }

        return 'Pathao did not accept this order. Please review delivery address, customer phone, store Pathao settings, and COD amount, then retry.';
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
