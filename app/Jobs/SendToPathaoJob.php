<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Models\PathaoBulkBatch;
use App\Services\PathaoService;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendToPathaoJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $shipmentId;
    protected ?int $pathaoBulkBatchId;

    /**
     * Number of times the job may be attempted
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying (exponential backoff)
     */
    public array $backoff = [10, 30, 60];

    /**
     * The number of seconds the job can run before timing out
     */
    public int $timeout = 60;

    /**
     * Create a new job instance
     */
    public function __construct(int $shipmentId, ?int $pathaoBulkBatchId = null)
    {
        $this->shipmentId = $shipmentId;
        $this->pathaoBulkBatchId = $pathaoBulkBatchId;
        $this->onQueue('pathao'); // Use dedicated queue for Pathao jobs
    }

    /**
     * Execute the job
     */
    public function handle(PathaoService $pathaoService): void
    {
        // Check if batch was cancelled
        if ($this->batch() && $this->batch()->cancelled()) {
            return;
        }

        $shipment = Shipment::with(['order.items.product', 'store', 'customer'])->find($this->shipmentId);

        if (!$shipment) {
            Log::warning("SendToPathaoJob: Shipment {$this->shipmentId} not found");
            $this->recordResult(false, 'Shipment not found');
            return;
        }

        // Skip if already sent
        if ($shipment->pathao_consignment_id) {
            Log::info("SendToPathaoJob: Shipment {$this->shipmentId} already has consignment ID");
            $this->recordResult(true, 'Already sent to Pathao', $shipment->pathao_consignment_id);
            return;
        }

        // Skip if not pending
        if (!$shipment->isPending()) {
            Log::info("SendToPathaoJob: Shipment {$this->shipmentId} is not in pending status");
            $this->recordResult(false, "Status is {$shipment->status}, expected pending");
            return;
        }

        try {
            $result = $this->sendToPathao($shipment, $pathaoService);

            if ($result['success']) {
                $this->recordResult(true, 'Sent to Pathao successfully', $result['consignment_id']);
                Log::info("SendToPathaoJob: Successfully sent shipment {$this->shipmentId} to Pathao", [
                    'consignment_id' => $result['consignment_id'],
                ]);
            } else {
                throw new \Exception($result['error']);
            }
        } catch (\Exception $e) {
            Log::error("SendToPathaoJob: Failed to send shipment {$this->shipmentId}", [
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Record failure if this is the last attempt
            if ($this->attempts() >= $this->tries) {
                $this->recordResult(false, $e->getMessage());
            }

            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Send shipment to Pathao (extracted from ShipmentController)
     */
    protected function sendToPathao(Shipment $shipment, PathaoService $pathaoService): array
    {
        $order = $shipment->order;
        $store = $shipment->store;
        $deliveryAddress = is_array($shipment->delivery_address) ? $shipment->delivery_address : [];

        // Ensure COD amount is set
        if ($shipment->cod_amount === null) {
            if ($order->outstanding_amount !== null) {
                $shipment->cod_amount = (float) $order->outstanding_amount;
            } else {
                $total = (float) ($order->total_amount ?? 0);
                $paid = (float) ($order->paid_amount ?? 0);
                $shipment->cod_amount = max(0, $total - $paid);
            }
            $shipment->save();
        }

        // Build address string with fallbacks (shipment delivery address -> order shipping address)
        $recipientAddress = $this->buildRecipientAddress($shipment);

        // Validation
        if (!$store->pathao_store_id) {
            return ['success' => false, 'error' => 'Store not registered with Pathao'];
        }

        if (empty($recipientAddress)) {
            return ['success' => false, 'error' => 'Delivery address is empty'];
        }

        if (mb_strlen($recipientAddress) < 10) {
            return [
                'success' => false,
                'error' => 'Recipient address is too short. Minimum 10 characters required for Pathao.',
            ];
        }

        // Calculate weight
        $totalWeight = $order->items->sum(function ($item) {
            return ($item->product->weight ?? 0.5) * $item->quantity;
        });
        $totalWeight = max($totalWeight, 0.1);

        // Prepare Pathao data
        $pathaoData = [
            'store_id' => (int) $store->pathao_store_id,
            'merchant_order_id' => $order->order_number,
            'recipient_name' => $shipment->recipient_name,
            'recipient_phone' => $shipment->recipient_phone,
            'recipient_address' => $recipientAddress,
            'delivery_type' => $shipment->delivery_type === 'express' ? 12 : 48,
            'item_type' => 2,
            'special_instruction' => $shipment->special_instructions ?? '',
            'item_quantity' => (int) $order->items->sum('quantity'),
            'item_weight' => $totalWeight,
            'amount_to_collect' => (int) round((float) ($shipment->cod_amount ?? 0)),
            'item_description' => $shipment->getPackageDescription(),
        ];

        // Add location IDs if available from shipment delivery address
        $hasLocationIds = !empty($deliveryAddress['pathao_city_id'])
            && !empty($deliveryAddress['pathao_zone_id'])
            && !empty($deliveryAddress['pathao_area_id']);

        if ($hasLocationIds) {
            $pathaoData['recipient_city'] = $deliveryAddress['pathao_city_id'];
            $pathaoData['recipient_zone'] = $deliveryAddress['pathao_zone_id'];
            $pathaoData['recipient_area'] = $deliveryAddress['pathao_area_id'];
        }

        // Call Pathao API
        $pathaoService->setStoreId($store->pathao_store_id);
        $result = $pathaoService->createOrder($pathaoData);

        if (empty($result['success'])) {
            $err = $result['error'] ?? 'Unknown error';
            if (is_array($err)) {
                $err = json_encode($err);
            }
            return ['success' => false, 'error' => (string) $err];
        }

        // Update shipment
        $data = $result['data'] ?? [];
        $shipment->pathao_consignment_id = $data['consignment_id'] ?? null;
        $shipment->pathao_tracking_number = $data['invoice_id'] ?? null;
        $shipment->pathao_status = 'pickup_requested';
        $shipment->pathao_response = $result['response'] ?? null;
        $shipment->status = 'pickup_requested';
        $shipment->pickup_requested_at = now();

        if (isset($data['delivery_fee'])) {
            $shipment->delivery_fee = $data['delivery_fee'];
        }

        $shipment->addStatusHistory(
            'pickup_requested',
            'Sent to Pathao via queue. Consignment ID: ' . ($data['consignment_id'] ?? 'N/A')
        );
        $shipment->save();

        return [
            'success' => true,
            'consignment_id' => $data['consignment_id'] ?? null,
        ];
    }

    /**
     * Build recipient address with graceful fallback.
     */
    protected function buildRecipientAddress(Shipment $shipment): string
    {
        $order = $shipment->order;

        $deliveryAddress = is_array($shipment->delivery_address) ? $shipment->delivery_address : [];
        $orderShippingAddress = is_array($order->shipping_address ?? null) ? $order->shipping_address : [];

        $parts = [
            $this->pickValue($deliveryAddress, ['address_line_1', 'address_line1', 'street', 'address']),
            $this->pickValue($deliveryAddress, ['address_line_2', 'address_line2']),
            $this->pickValue($deliveryAddress, ['landmark']),
            $this->pickValue($deliveryAddress, ['area', 'thana', 'upazila']),
            $this->pickValue($deliveryAddress, ['city', 'district']),
            $this->pickValue($deliveryAddress, ['postal_code', 'zip']),
        ];

        // If shipment delivery address is too weak, merge from order shipping address.
        $currentAddress = trim(implode(', ', array_values(array_filter($parts))));
        if ($currentAddress === '' || mb_strlen($currentAddress) < 10) {
            $fallback = [
                $this->pickValue($orderShippingAddress, ['address_line_1', 'address_line1', 'street', 'address']),
                $this->pickValue($orderShippingAddress, ['address_line_2', 'address_line2']),
                $this->pickValue($orderShippingAddress, ['landmark']),
                $this->pickValue($orderShippingAddress, ['area', 'thana', 'upazila']),
                $this->pickValue($orderShippingAddress, ['city', 'district']),
                $this->pickValue($orderShippingAddress, ['postal_code', 'zip']),
            ];

            foreach ($parts as $i => $val) {
                if (($val === null || $val === '') && !empty($fallback[$i])) {
                    $parts[$i] = $fallback[$i];
                }
            }
        }

        return trim(implode(', ', array_values(array_filter($parts))));
    }

    /**
     * Pick first non-empty scalar value from a map of keys.
     */
    protected function pickValue(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Record the result in the batch tracker
     */
    protected function recordResult(bool $success, string $message, ?string $consignmentId = null): void
    {
        if (!$this->pathaoBulkBatchId) {
            return;
        }

        try {
            $batch = PathaoBulkBatch::find($this->pathaoBulkBatchId);
            if ($batch) {
                $batch->recordShipmentResult($this->shipmentId, $success, $message, $consignmentId);
            }
        } catch (\Exception $e) {
            Log::error("SendToPathaoJob: Failed to record result for batch {$this->pathaoBulkBatchId}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("SendToPathaoJob: Job failed permanently for shipment {$this->shipmentId}", [
            'error' => $exception->getMessage(),
        ]);

        $this->recordResult(false, 'Job failed: ' . $exception->getMessage());
    }
}
