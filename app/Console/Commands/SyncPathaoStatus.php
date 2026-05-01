<?php

namespace App\Console\Commands;

use App\Models\Shipment;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\PathaoService;
use Codeboxr\PathaoCourier\Facade\PathaoCourier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncPathaoStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pathao:sync-status 
                            {--limit=100 : Maximum number of shipments to sync}
                            {--days=30 : Only sync shipments created within these days}
                            {--force : Force sync all pending shipments regardless of last sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync shipment status and payment info from Pathao API';

    /**
     * Pathao status to local status mapping
     */
    protected $statusMap = [
        'Pending' => 'pending',
        'Pickup_Pending' => 'pickup_requested',
        'Pickup_Request_Accepted' => 'pickup_requested',
        'Picked_up' => 'picked_up',
        'Reached_at_Pathao_Warehouse' => 'picked_up',
        'In_transit' => 'in_transit',
        'Out_For_Delivery' => 'in_transit',
        'Delivered' => 'delivered',
        'Partial_Delivery' => 'delivered',
        'Return' => 'returned',
        'Return_In_Transit' => 'returned',
        'Returned' => 'returned',
        'Cancelled' => 'cancelled',
        'Hold' => 'pending',
    ];

    /**
     * Terminal statuses that don't need further syncing
     */
    protected $terminalStatuses = ['delivered', 'cancelled', 'returned'];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting Pathao status sync...');
        
        $limit = (int) $this->option('limit');
        $days = (int) $this->option('days');
        $force = $this->option('force');

        // Query for shipments that need syncing
        $query = Shipment::whereNotNull('pathao_consignment_id')
            ->where('created_at', '>=', now()->subDays($days));

        // Exclude terminal statuses unless forced
        if (!$force) {
            $query->whereNotIn('status', $this->terminalStatuses);
        }

        $shipments = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($shipments->isEmpty()) {
            $this->info('No shipments to sync.');
            return Command::SUCCESS;
        }

        $this->info("Found {$shipments->count()} shipments to sync");

        $stats = [
            'total' => $shipments->count(),
            'synced' => 0,
            'status_updated' => 0,
            'payment_updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $progressBar = $this->output->createProgressBar($shipments->count());
        $progressBar->start();

        foreach ($shipments as $shipment) {
            try {
                $result = $this->syncShipment($shipment);
                
                if ($result['success']) {
                    $stats['synced']++;
                    if ($result['status_changed']) {
                        $stats['status_updated']++;
                    }
                    if ($result['payment_updated']) {
                        $stats['payment_updated']++;
                    }
                } else {
                    $stats['failed']++;
                    $stats['errors'][] = [
                        'shipment_id' => $shipment->id,
                        'consignment_id' => $shipment->pathao_consignment_id,
                        'error' => $result['error'],
                    ];
                }

                // Rate limiting - avoid hammering Pathao API
                usleep(200000); // 200ms delay between requests

            } catch (\Exception $e) {
                $stats['failed']++;
                $stats['errors'][] = [
                    'shipment_id' => $shipment->id,
                    'consignment_id' => $shipment->pathao_consignment_id,
                    'error' => $e->getMessage(),
                ];

                Log::error('Pathao sync error', [
                    'shipment_id' => $shipment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Output summary
        $this->info('📊 Sync Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Processed', $stats['total']],
                ['Successfully Synced', $stats['synced']],
                ['Status Updated', $stats['status_updated']],
                ['Payment Updated', $stats['payment_updated']],
                ['Failed', $stats['failed']],
            ]
        );

        if (!empty($stats['errors'])) {
            $this->warn('⚠️  Errors encountered:');
            foreach (array_slice($stats['errors'], 0, 5) as $error) {
                $this->error("  - Shipment #{$error['shipment_id']}: {$error['error']}");
            }
            if (count($stats['errors']) > 5) {
                $this->warn('  ... and ' . (count($stats['errors']) - 5) . ' more errors');
            }
        }

        Log::info('Pathao sync completed', $stats);

        return Command::SUCCESS;
    }

    /**
     * Sync a single shipment with Pathao
     */
    protected function syncShipment(Shipment $shipment): array
    {
        $result = [
            'success' => false,
            'status_changed' => false,
            'payment_updated' => false,
            'error' => null,
        ];

        try {
            // Call Pathao API
            $response = PathaoCourier::order()->orderDetails($shipment->pathao_consignment_id);

            if (!$response || !isset($response->order_status)) {
                // Try alternate response format
                if (is_array($response) && isset($response['order_status'])) {
                    $data = $response;
                } elseif (is_object($response)) {
                    $data = (array) $response;
                } else {
                    $result['error'] = 'Invalid response from Pathao';
                    return $result;
                }
            } else {
                $data = (array) $response;
            }

            $oldPathaoStatus = $shipment->pathao_status;
            $newPathaoStatus = $data['order_status'] ?? $data['status'] ?? $oldPathaoStatus;

            // Update Pathao status
            $shipment->pathao_status = $newPathaoStatus;
            $shipment->pathao_response = $data;

            // Map to local status
            $newLocalStatus = $this->statusMap[$newPathaoStatus] ?? $shipment->status;

            if ($newLocalStatus !== $shipment->status) {
                $shipment->status = $newLocalStatus;
                $shipment->addStatusHistory($newLocalStatus, "Auto-synced from Pathao: {$newPathaoStatus}");
                $result['status_changed'] = true;

                // Update timestamps based on status
                $this->updateTimestamps($shipment, $newLocalStatus);
            }

            // Handle payment status for COD orders
            $paymentUpdated = $this->handlePaymentStatus($shipment, $data);
            $result['payment_updated'] = $paymentUpdated;

            $shipment->save();

            $result['success'] = true;

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Update shipment timestamps based on status
     */
    protected function updateTimestamps(Shipment $shipment, string $status): void
    {
        switch ($status) {
            case 'picked_up':
                if (!$shipment->picked_up_at) {
                    $shipment->picked_up_at = now();
                }
                break;
            case 'in_transit':
                if (!$shipment->shipped_at) {
                    $shipment->shipped_at = now();
                }
                break;
            case 'delivered':
                if (!$shipment->delivered_at) {
                    $shipment->delivered_at = now();
                }
                break;
            case 'returned':
                if (!$shipment->returned_at) {
                    $shipment->returned_at = now();
                }
                break;
            case 'cancelled':
                if (!$shipment->cancelled_at) {
                    $shipment->cancelled_at = now();
                }
                break;
        }
    }

    /**
     * Handle payment status updates for COD orders
     * 
     * When Pathao marks order as Delivered, COD has been collected.
     * We should update the order payment status accordingly.
     */
    protected function handlePaymentStatus(Shipment $shipment, array $pathaoData): bool
    {
        $updated = false;

        // Get Pathao status
        $pathaoStatus = $pathaoData['order_status'] ?? $pathaoData['status'] ?? null;
        
        // Get payment info from Pathao response (if available)
        $collectedAmount = $pathaoData['collected_amount'] ?? $pathaoData['cod_collected'] ?? null;
        $paymentStatus = $pathaoData['payment_status'] ?? null;

        // Update shipment sync timestamp
        $shipment->pathao_last_synced_at = now();
        $shipment->pathao_payment_status = $paymentStatus;

        // Check if this is a COD order (has amount to collect)
        $codAmount = $shipment->cod_amount ?? $shipment->amount_to_collect ?? 0;
        
        // If delivered, COD should be collected
        if (in_array($pathaoStatus, ['Delivered', 'Partial_Delivery'])) {
            
            // Update shipment COD collection status
            if (!$shipment->cod_collected && $codAmount > 0) {
                $shipment->cod_collected = true;
                $shipment->cod_collected_amount = $collectedAmount ?? $codAmount;
                $shipment->cod_collected_at = now();
                $updated = true;
            }

            // Only process order payment if order exists and has COD
            if (!$shipment->order_id || $codAmount <= 0) {
                return $updated;
            }

            $order = Order::find($shipment->order_id);
            if (!$order) {
                return $updated;
            }
            
            // Check if we already recorded this COD payment
            $existingPayment = OrderPayment::where('order_id', $order->id)
                ->where('payment_method_id', $this->getCodPaymentMethodId())
                ->where('reference_number', 'like', 'PATHAO-COD-%')
                ->where('reference_number', 'like', '%' . $shipment->pathao_consignment_id . '%')
                ->first();

            if (!$existingPayment) {
                // Create COD payment record
                DB::beginTransaction();
                try {
                    $paymentAmount = $collectedAmount ?? $codAmount;

                    $payment = OrderPayment::create([
                        'order_id' => $order->id,
                        'payment_method_id' => $this->getCodPaymentMethodId(),
                        'amount' => $paymentAmount,
                        'status' => 'completed',
                        'reference_number' => 'PATHAO-COD-' . $shipment->pathao_consignment_id,
                        'notes' => 'COD collected by Pathao - Auto synced',
                        'metadata' => [
                            'source' => 'pathao_sync',
                            'pathao_consignment_id' => $shipment->pathao_consignment_id,
                            'pathao_status' => $pathaoStatus,
                            'synced_at' => now()->toISOString(),
                        ],
                    ]);

                    // Update order payment status
                    $order->updatePaymentStatus();

                    DB::commit();
                    $updated = true;

                    Log::info('COD payment recorded from Pathao sync', [
                        'order_id' => $order->id,
                        'shipment_id' => $shipment->id,
                        'consignment_id' => $shipment->pathao_consignment_id,
                        'amount' => $paymentAmount,
                    ]);

                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Failed to record COD payment', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Handle return/cancelled - may need to mark COD as not collected
        if (in_array($pathaoStatus, ['Return', 'Returned', 'Cancelled'])) {
            // Mark COD as not collected
            if ($codAmount > 0) {
                $shipment->cod_collected = false;
                $shipment->cod_collected_amount = null;
                $updated = true;
            }

            // Store return info in shipment metadata
            $metadata = $shipment->metadata ?? [];
            $metadata['cod_not_collected'] = true;
            $metadata['return_reason'] = $pathaoData['return_reason'] ?? 'Unknown';
            $metadata['pathao_return_status'] = $pathaoStatus;
            $shipment->metadata = $metadata;
        }

        return $updated;
    }

    /**
     * Get or create COD payment method ID
     */
    protected function getCodPaymentMethodId(): int
    {
        $codMethod = \App\Models\PaymentMethod::firstOrCreate(
            ['code' => 'cod'],
            [
                'name' => 'Cash on Delivery',
                'code' => 'cod',
                'description' => 'Payment collected upon delivery',
                'is_active' => true,
            ]
        );

        return $codMethod->id;
    }
}