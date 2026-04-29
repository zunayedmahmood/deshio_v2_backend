<?php

namespace App\Http\Controllers;

use App\Models\ProductBarcode;
use App\Models\Order;
use App\Models\ProductBatch;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class LookupController extends Controller
{
    /**
     * 1. PRODUCT LOOKUP BY BARCODE
     * 
     * Complete lifecycle history of a specific physical product unit:
     * - Purchase Order origin
     * - Initial warehouse/store receipt
     * - All dispatches & store-to-store transfers
     * - Sale records (which customer bought it)
     * - Return records (if returned, when, where)
     * - Re-sale records (if sold again after return)
     * - Defective product marking
     * - Complete activity log with timestamps
     */
    public function productLookup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'barcode' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Find the barcode
        $barcodeRecord = ProductBarcode::with([
            'product.category',
            'product.vendor',
            'batch.store',
            'currentStore',
            'defectiveRecord.identifiedBy'
        ])->where('barcode', $request->barcode)->first();

        if (!$barcodeRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Barcode not found'
            ], 404);
        }

        // 1. Product Information
        $productInfo = [
            'id' => $barcodeRecord->product->id,
            'sku' => $barcodeRecord->product->sku,
            'name' => $barcodeRecord->product->name,
            'description' => $barcodeRecord->product->description,
            'brand' => $barcodeRecord->product->brand,
            'category' => $barcodeRecord->product->category ? [
                'id' => $barcodeRecord->product->category->id,
                'name' => $barcodeRecord->product->category->name,
            ] : null,
            'vendor' => $barcodeRecord->product->vendor ? [
                'id' => $barcodeRecord->product->vendor->id,
                'name' => $barcodeRecord->product->vendor->name,
                'company_name' => $barcodeRecord->product->vendor->company_name,
            ] : null,
        ];

        // 2. Barcode Information
        $barcodeInfo = [
            'barcode' => $barcodeRecord->barcode,
            'type' => $barcodeRecord->type,
            'is_primary' => $barcodeRecord->is_primary,
            'is_active' => $barcodeRecord->is_active,
            'is_defective' => $barcodeRecord->is_defective,
            'generated_at' => $barcodeRecord->generated_at?->format('Y-m-d H:i:s'),
            'current_status' => $barcodeRecord->current_status,
            'location_updated_at' => $barcodeRecord->location_updated_at?->format('Y-m-d H:i:s'),
            'location_metadata' => $barcodeRecord->location_metadata,
        ];

        // 3. Current Location
        $currentLocation = $barcodeRecord->currentStore ? [
            'store_id' => $barcodeRecord->currentStore->id,
            'store_name' => $barcodeRecord->currentStore->name,
            'store_code' => $barcodeRecord->currentStore->store_code,
            'store_type' => $barcodeRecord->currentStore->store_type,
            'address' => $barcodeRecord->currentStore->address,
            'phone' => $barcodeRecord->currentStore->phone,
        ] : null;

        // 4. Batch Information
        $batchInfo = null;
        if ($barcodeRecord->batch) {
            $batchInfo = [
                'id' => $barcodeRecord->batch->id,
                'batch_number' => $barcodeRecord->batch->batch_number,
                'cost_price' => $barcodeRecord->batch->cost_price,
                'sell_price' => $barcodeRecord->batch->sell_price,
                'manufactured_date' => $barcodeRecord->batch->manufactured_date?->format('Y-m-d'),
                'expiry_date' => $barcodeRecord->batch->expiry_date?->format('Y-m-d'),
                'original_store' => $barcodeRecord->batch->store ? [
                    'id' => $barcodeRecord->batch->store->id,
                    'name' => $barcodeRecord->batch->store->name,
                    'store_code' => $barcodeRecord->batch->store->store_code,
                ] : null,
            ];
        }

        // 5. Purchase Order Origin - Enhanced with full PO and Vendor details
        $purchaseOrderOrigin = null;
        $purchaseOrderDetails = null;
        $vendorDetails = null;

        // First check metadata for basic PO info
        if ($barcodeRecord->location_metadata && isset($barcodeRecord->location_metadata['po_number'])) {
            $purchaseOrderOrigin = [
                'po_number' => $barcodeRecord->location_metadata['po_number'],
                'received_date' => $barcodeRecord->location_metadata['received_date'] ?? null,
                'source' => $barcodeRecord->location_metadata['source'] ?? 'purchase_order',
            ];
        }

        // Try to find PO through batch connection (PurchaseOrderItem -> PurchaseOrder)
        $poItem = null;
        if ($barcodeRecord->batch_id) {
            $poItem = PurchaseOrderItem::with(['purchaseOrder.vendor', 'purchaseOrder.store', 'purchaseOrder.createdBy'])
                ->where('product_batch_id', $barcodeRecord->batch_id)
                ->first();
        }

        // If no batch link, try finding PO by product_id (most recent received PO for this product)
        if (!$poItem) {
            $poItem = PurchaseOrderItem::with(['purchaseOrder.vendor', 'purchaseOrder.store', 'purchaseOrder.createdBy'])
                ->where('product_id', $barcodeRecord->product_id)
                ->whereHas('purchaseOrder', function($q) {
                    $q->whereIn('status', ['received', 'partially_received', 'approved']);
                })
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($poItem && $poItem->purchaseOrder) {
            $po = $poItem->purchaseOrder;
            
            $purchaseOrderDetails = [
                'id' => $po->id,
                'po_number' => $po->po_number,
                'order_date' => $po->order_date?->format('Y-m-d'),
                'expected_delivery_date' => $po->expected_delivery_date?->format('Y-m-d'),
                'status' => $po->status,
                'payment_status' => $po->payment_status,
                'total_amount' => $po->total_amount,
                'paid_amount' => $po->paid_amount,
                'outstanding_amount' => $po->outstanding_amount,
                'store' => $po->store ? [
                    'id' => $po->store->id,
                    'name' => $po->store->name,
                    'store_code' => $po->store->store_code,
                ] : null,
                'created_by' => $po->createdBy ? [
                    'id' => $po->createdBy->id,
                    'name' => $po->createdBy->name,
                ] : null,
                'item_details' => [
                    'quantity_ordered' => $poItem->quantity_ordered,
                    'quantity_received' => $poItem->quantity_received,
                    'unit_cost' => $poItem->unit_cost,
                    'unit_sell_price' => $poItem->unit_sell_price,
                    'total_cost' => $poItem->total_cost,
                    'receive_status' => $poItem->receive_status,
                ],
            ];

            // Full vendor details from PO
            if ($po->vendor) {
                $vendor = $po->vendor;
                $vendorDetails = [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'company_name' => $vendor->company_name,
                    'email' => $vendor->email,
                    'phone' => $vendor->phone,
                    'address' => $vendor->address,
                    'city' => $vendor->city,
                    'state' => $vendor->state,
                    'postal_code' => $vendor->postal_code,
                    'country' => $vendor->country,
                    'tax_id' => $vendor->tax_id,
                    'payment_terms' => $vendor->payment_terms,
                    'status' => $vendor->status,
                    'notes' => $vendor->notes,
                    'total_purchase_orders' => $vendor->purchaseOrders()->count(),
                    'total_purchase_amount' => $vendor->purchaseOrders()->sum('total_amount'),
                ];
            }

            // Update origin info if we have better data
            if (!$purchaseOrderOrigin) {
                $purchaseOrderOrigin = [
                    'po_number' => $po->po_number,
                    'received_date' => $po->received_at?->format('Y-m-d H:i:s'),
                    'source' => 'purchase_order',
                ];
            }
        }

        // 6. Get all activity history for this barcode (DB-agnostic: PostgreSQL + MySQL/MariaDB)
        $driver = DB::connection()->getDriverName();
        $activityHistory = Activity::where(function ($query) use ($barcodeRecord, $driver) {
            $query->where(function ($q) use ($barcodeRecord) {
                $q->where('subject_type', 'App\\Models\\ProductBarcode')
                  ->where('subject_id', $barcodeRecord->id);
            })->orWhere(function ($q) use ($barcodeRecord, $driver) {
                // Also get activities where this barcode is mentioned in properties JSON
                if ($driver === 'pgsql') {
                    $q->whereRaw(
                        "(properties::jsonb @> ?::jsonb)",
                        [json_encode(['product_barcode_id' => $barcodeRecord->id])]
                    );
                } else {
                    // MySQL / MariaDB
                    $q->where(function ($mysql) use ($barcodeRecord) {
                        foreach ([
                            '$.product_barcode_id',
                            '$.attributes.product_barcode_id',
                            '$.old.product_barcode_id',
                            '$.new.product_barcode_id',
                        ] as $path) {
                            $mysql->orWhereRaw(
                                'JSON_VALID(properties) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(properties, ?)) AS UNSIGNED) = ?',
                                [$path, (int) $barcodeRecord->id]
                            );
                        }
                    });
                }
            });
        })
        ->with(['causer'])
        ->orderBy('created_at', 'asc')
        ->get()
        ->map(function ($activity) {
            return [
                'id' => $activity->id,
                'event' => $activity->event,
                'description' => $activity->description,
                'timestamp' => $activity->created_at->format('Y-m-d H:i:s'),
                'human_time' => $activity->created_at->diffForHumans(),
                'performed_by' => $activity->causer ? [
                    'id' => $activity->causer->id,
                    'type' => class_basename($activity->causer),
                    'name' => $activity->causer->name ?? $activity->causer->username ?? 'Unknown',
                ] : null,
                'changes' => $this->extractChanges($activity),
            ];
        });

        // 7. Get Sale Records (OrderItems where this barcode was sold)
        $saleRecords = \App\Models\OrderItem::with(['order.customer', 'order.store'])
            ->where('product_barcode_id', $barcodeRecord->id)
            ->get()
            ->map(function ($item) {
                return [
                    'order_id' => $item->order_id,
                    'order_number' => $item->order->order_number,
                    'order_date' => $item->order->order_date?->format('Y-m-d H:i:s'),
                    'order_status' => $item->order->status,
                    'sale_price' => $item->unit_price,
                    'store' => $item->order->store ? [
                        'id' => $item->order->store->id,
                        'name' => $item->order->store->name,
                        'store_code' => $item->order->store->store_code,
                    ] : null,
                    'customer' => $item->order->customer ? [
                        'id' => $item->order->customer->id,
                        'name' => $item->order->customer->name,
                        'phone' => $item->order->customer->phone,
                        'customer_code' => $item->order->customer->customer_code,
                        'customer_type' => $item->order->customer->customer_type,
                    ] : null,
                ];
            });

        // 8. Get Return Records
        $returnRecords = \App\Models\ProductReturn::with(['order', 'customer', 'store'])
            ->whereHas('order.items', function ($query) use ($barcodeRecord) {
                $query->where('product_barcode_id', $barcodeRecord->id);
            })
            ->get()
            ->map(function ($return) {
                return [
                    'return_id' => $return->id,
                    'return_number' => $return->return_number,
                    'return_date' => $return->return_date?->format('Y-m-d H:i:s'),
                    'return_reason' => $return->return_reason,
                    'return_type' => $return->return_type,
                    'status' => $return->status,
                    'refund_amount' => $return->total_refund_amount,
                    'received_at_store' => $return->receivedAtStore ? [
                        'id' => $return->receivedAtStore->id,
                        'name' => $return->receivedAtStore->name,
                    ] : null,
                ];
            });

        // 9. Get Dispatch Records (ProductDispatchItems involving this barcode)
        $dispatchRecords = \App\Models\ProductDispatchItem::with([
            'dispatch.sourceStore',
            'dispatch.destinationStore',
            'dispatch.createdBy'
        ])
        ->whereHas('scannedBarcodes', function ($query) use ($barcodeRecord) {
            $query->where('product_barcode_id', $barcodeRecord->id);
        })
        ->orWhere('product_barcode_id', $barcodeRecord->id)
        ->get()
        ->map(function ($item) {
            return [
                'dispatch_id' => $item->dispatch->id,
                'dispatch_number' => $item->dispatch->dispatch_number,
                'dispatch_date' => $item->dispatch->dispatch_date?->format('Y-m-d H:i:s'),
                'status' => $item->dispatch->status,
                'from_store' => $item->dispatch->sourceStore ? [
                    'id' => $item->dispatch->sourceStore->id,
                    'name' => $item->dispatch->sourceStore->name,
                    'store_code' => $item->dispatch->sourceStore->store_code,
                ] : null,
                'to_store' => $item->dispatch->destinationStore ? [
                    'id' => $item->dispatch->destinationStore->id,
                    'name' => $item->dispatch->destinationStore->name,
                    'store_code' => $item->dispatch->destinationStore->store_code,
                ] : null,
                'dispatched_by' => $item->dispatch->createdBy ? [
                    'id' => $item->dispatch->createdBy->id,
                    'name' => $item->dispatch->createdBy->name,
                ] : null,
            ];
        });

        // 10. Defective Product Record
        $defectiveRecord = null;
        if ($barcodeRecord->defectiveRecord) {
            $defectiveRecord = [
                'id' => $barcodeRecord->defectiveRecord->id,
                'defect_reason' => $barcodeRecord->defectiveRecord->defect_reason,
                'condition' => $barcodeRecord->defectiveRecord->condition,
                'severity' => $barcodeRecord->defectiveRecord->severity,
                'discount_percentage' => $barcodeRecord->defectiveRecord->discount_percentage,
                'identified_date' => $barcodeRecord->defectiveRecord->identified_date?->format('Y-m-d H:i:s'),
                'identified_by' => $barcodeRecord->defectiveRecord->identifiedBy ? [
                    'id' => $barcodeRecord->defectiveRecord->identifiedBy->id,
                    'name' => $barcodeRecord->defectiveRecord->identifiedBy->name,
                ] : null,
                'status' => $barcodeRecord->defectiveRecord->status,
            ];
        }

        // 11. Build complete lifecycle timeline
        $lifecycle = [
            [
                'stage' => 'origin',
                'title' => 'Purchase Order Receipt',
                'timestamp' => $barcodeRecord->generated_at?->format('Y-m-d H:i:s'),
                'data' => $purchaseOrderOrigin,
            ],
            [
                'stage' => 'dispatches',
                'title' => 'Store Transfers',
                'count' => $dispatchRecords->count(),
                'data' => $dispatchRecords,
            ],
            [
                'stage' => 'sales',
                'title' => 'Sales History',
                'count' => $saleRecords->count(),
                'data' => $saleRecords,
            ],
            [
                'stage' => 'returns',
                'title' => 'Return History',
                'count' => $returnRecords->count(),
                'data' => $returnRecords,
            ],
            [
                'stage' => 'defective',
                'title' => 'Defective Status',
                'is_defective' => $barcodeRecord->is_defective,
                'data' => $defectiveRecord,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'product' => $productInfo,
                'barcode' => $barcodeInfo,
                'current_location' => $currentLocation,
                'batch' => $batchInfo,
                'purchase_order_origin' => $purchaseOrderOrigin,
                'purchase_order' => $purchaseOrderDetails,  // NEW: Full PO details
                'vendor' => $vendorDetails,                  // NEW: Full vendor details
                'lifecycle' => $lifecycle,
                'activity_history' => $activityHistory,
                'summary' => [
                    'total_dispatches' => $dispatchRecords->count(),
                    'total_sales' => $saleRecords->count(),
                    'total_returns' => $returnRecords->count(),
                    'is_currently_defective' => $barcodeRecord->is_defective,
                    'is_active' => $barcodeRecord->is_active,
                    'current_status' => $barcodeRecord->current_status,
                    'has_purchase_order' => $purchaseOrderDetails !== null,
                    'has_vendor_info' => $vendorDetails !== null,
                ],
            ]
        ]);
    }

    /**
     * 2. ORDER LOOKUP
     * 
     * Complete order details with all products sold (with barcodes if fulfilled):
     * - Order information
     * - Customer details
     * - All items with specific barcode numbers (if fulfilled)
     * - Payment records
     * - Shipment tracking
     * - Complete timestamped edit history
     */
    public function orderLookup(Request $request, $orderId)
    {
        $order = Order::with([
            'customer',
            'store',
            'items.product',
            'items.batch',
            'items.barcode',
            'payments.paymentMethod',
            'shipments',
            'createdBy',
            'fulfilledBy'
        ])->find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // 1. Order Information
        $orderInfo = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'fulfillment_status' => $order->fulfillment_status,
            'payment_status' => $order->payment_status,
            'order_date' => $order->order_date?->format('Y-m-d H:i:s'),
            'confirmed_at' => $order->confirmed_at?->format('Y-m-d H:i:s'),
            'fulfilled_at' => $order->fulfilled_at?->format('Y-m-d H:i:s'),
            'shipped_at' => $order->shipped_at?->format('Y-m-d H:i:s'),
            'delivered_at' => $order->delivered_at?->format('Y-m-d H:i:s'),
            'cancelled_at' => $order->cancelled_at?->format('Y-m-d H:i:s'),
            'subtotal' => $order->subtotal,
            'tax_amount' => $order->tax_amount,
            'discount_amount' => $order->discount_amount,
            'shipping_amount' => $order->shipping_amount,
            'total_amount' => $order->total_amount,
            'paid_amount' => $order->paid_amount,
            'outstanding_amount' => $order->outstanding_amount,
        ];

        // 2. Customer Information
        $customerInfo = $order->customer ? [
            'id' => $order->customer->id,
            'customer_code' => $order->customer->customer_code,
            'customer_type' => $order->customer->customer_type,
            'name' => $order->customer->name,
            'phone' => $order->customer->phone,
            'email' => $order->customer->email,
            'address' => $order->customer->address,
            'city' => $order->customer->city,
            'total_orders' => $order->customer->total_orders,
            'total_purchases' => $order->customer->total_purchases,
        ] : null;

        // 3. Store Information
        $storeInfo = $order->store ? [
            'id' => $order->store->id,
            'name' => $order->store->name,
            'store_code' => $order->store->store_code,
            'store_type' => $order->store->store_type,
            'address' => $order->store->address,
            'phone' => $order->store->phone,
        ] : null;

        // 4. Order Items with Barcodes
        $orderItems = collect($order->items)->map(function ($item) {
            return [
                'item_id' => $item->id,
                'product' => [
                    'id' => $item->product->id,
                    'sku' => $item->product->sku,
                    'name' => $item->product_name,
                    'brand' => $item->product->brand,
                ],
                'batch' => $item->batch ? [
                    'id' => $item->batch->id,
                    'batch_number' => $item->batch->batch_number,
                    'cost_price' => $item->batch->cost_price,
                    'sell_price' => $item->batch->sell_price,
                ] : null,
                'barcode' => $item->barcode ? [
                    'barcode' => $item->barcode->barcode,
                    'type' => $item->barcode->type,
                    'is_active' => $item->barcode->is_active,
                    'current_status' => $item->barcode->current_status,
                ] : null,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'tax_amount' => $item->tax_amount,
                'total_amount' => $item->total_amount,
                'notes' => $item->notes,
            ];
        });

        // 5. Payment Records
        $paymentRecords = collect($order->payments)->map(function ($payment) {
            return [
                'payment_id' => $payment->id,
                'payment_date' => $payment->payment_date?->format('Y-m-d H:i:s'),
                'amount' => $payment->amount,
                'payment_method' => $payment->paymentMethod ? [
                    'id' => $payment->paymentMethod->id,
                    'name' => $payment->paymentMethod->name,
                    'type' => $payment->paymentMethod->type,
                ] : null,
                'transaction_id' => $payment->transaction_id,
                'status' => $payment->status,
                'notes' => $payment->notes,
            ];
        });

        // 6. Shipment Records
        $shipmentRecords = collect($order->shipments)->map(function ($shipment) {
            return [
                'shipment_id' => $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'carrier_name' => $shipment->carrier_name,
                'status' => $shipment->status,
                'shipped_at' => $shipment->shipped_at?->format('Y-m-d H:i:s'),
                'delivered_at' => $shipment->delivered_at?->format('Y-m-d H:i:s'),
                'shipping_address' => $shipment->shipping_address,
            ];
        });

        // 7. Get Complete Activity History for this Order
        $orderItemIds = collect($order->items)->pluck('id')->toArray();
        $paymentIds = collect($order->payments)->pluck('id')->toArray();
        $shipmentIds = collect($order->shipments)->pluck('id')->toArray();
        
        $activityHistory = Activity::where(function ($query) use ($order, $orderItemIds, $paymentIds, $shipmentIds) {
            $query->where(function ($q) use ($order) {
                // Order activities
                $q->where('subject_type', 'App\\Models\\Order')
                  ->where('subject_id', $order->id);
            })->orWhere(function ($q) use ($orderItemIds) {
                // OrderItem activities
                $q->where('subject_type', 'App\\Models\\OrderItem')
                  ->whereIn('subject_id', $orderItemIds);
            })->orWhere(function ($q) use ($paymentIds) {
                // OrderPayment activities
                $q->where('subject_type', 'App\\Models\\OrderPayment')
                  ->whereIn('subject_id', $paymentIds);
            })->orWhere(function ($q) use ($shipmentIds) {
                // Shipment activities
                $q->where('subject_type', 'App\\Models\\Shipment')
                  ->whereIn('subject_id', $shipmentIds);
            });
        })
        ->with(['causer'])
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($activity) {
            return [
                'id' => $activity->id,
                'event' => $activity->event,
                'subject_type' => class_basename($activity->subject_type),
                'description' => $activity->description,
                'timestamp' => $activity->created_at->format('Y-m-d H:i:s'),
                'human_time' => $activity->created_at->diffForHumans(),
                'performed_by' => $activity->causer ? [
                    'id' => $activity->causer->id,
                    'type' => class_basename($activity->causer),
                    'name' => $activity->causer->name ?? $activity->causer->username ?? 'Unknown',
                ] : null,
                'changes' => $this->extractChanges($activity),
            ];
        });

        // 8. Created By & Fulfilled By
        $createdBy = $order->createdBy ? [
            'id' => $order->createdBy->id,
            'name' => $order->createdBy->name,
            'email' => $order->createdBy->email,
        ] : null;

        $fulfilledBy = $order->fulfilledBy ? [
            'id' => $order->fulfilledBy->id,
            'name' => $order->fulfilledBy->name,
            'email' => $order->fulfilledBy->email,
        ] : null;

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $orderInfo,
                'customer' => $customerInfo,
                'store' => $storeInfo,
                'items' => $orderItems,
                'payments' => $paymentRecords,
                'shipments' => $shipmentRecords,
                'created_by' => $createdBy,
                'fulfilled_by' => $fulfilledBy,
                'activity_history' => $activityHistory,
                'summary' => [
                    'total_items' => count($order->items),
                    'items_with_barcodes' => collect($order->items)->whereNotNull('product_barcode_id')->count(),
                    'total_payments' => count($order->payments),
                    'total_shipments' => count($order->shipments),
                    'is_fulfilled' => $order->fulfillment_status === 'fulfilled',
                    'is_paid' => $order->payment_status === 'paid',
                ],
            ]
        ]);
    }

    /**
     * 3. BATCH LOOKUP
     * 
     * Complete batch information with all products and activity history:
     * - Batch details
     * - All product barcodes in the batch
     * - Current stock status
     * - All movements, dispatches, sales
     * - Complete timestamped edit history
     */
    public function batchLookup(Request $request, $batchId)
    {
        $batch = ProductBatch::with([
            'product.category',
            'product.vendor',
            'store',
            'barcodes.currentStore',
            'barcodes.defectiveRecord'
        ])->find($batchId);

        if (!$batch) {
            return response()->json([
                'success' => false,
                'message' => 'Batch not found'
            ], 404);
        }

        // 1. Batch Information
        $batchInfo = [
            'id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'quantity' => $batch->quantity,
            'cost_price' => $batch->cost_price,
            'sell_price' => $batch->sell_price,
            'tax_percentage' => $batch->tax_percentage,
            'base_price' => $batch->base_price,
            'tax_amount' => $batch->tax_amount,
            'manufactured_date' => $batch->manufactured_date?->format('Y-m-d'),
            'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
            'is_active' => $batch->is_active,
            'availability' => $batch->availability,
            'notes' => $batch->notes,
            'created_at' => $batch->created_at?->format('Y-m-d H:i:s'),
        ];

        // 2. Product Information
        $productInfo = [
            'id' => $batch->product->id,
            'sku' => $batch->product->sku,
            'name' => $batch->product->name,
            'description' => $batch->product->description,
            'brand' => $batch->product->brand,
            'category' => $batch->product->category ? [
                'id' => $batch->product->category->id,
                'name' => $batch->product->category->name,
            ] : null,
            'vendor' => $batch->product->vendor ? [
                'id' => $batch->product->vendor->id,
                'name' => $batch->product->vendor->name,
                'company_name' => $batch->product->vendor->company_name,
            ] : null,
        ];

        // 3. Store Information
        $storeInfo = $batch->store ? [
            'id' => $batch->store->id,
            'name' => $batch->store->name,
            'store_code' => $batch->store->store_code,
            'store_type' => $batch->store->store_type,
            'address' => $batch->store->address,
        ] : null;

        // 4. All Barcodes in this Batch
        $barcodes = collect($batch->barcodes)->map(function ($barcode) {
            return [
                'id' => $barcode->id,
                'barcode' => $barcode->barcode,
                'type' => $barcode->type,
                'is_primary' => $barcode->is_primary,
                'is_active' => $barcode->is_active,
                'is_defective' => $barcode->is_defective,
                'current_status' => $barcode->current_status,
                'generated_at' => $barcode->generated_at?->format('Y-m-d H:i:s'),
                'current_location' => $barcode->currentStore ? [
                    'id' => $barcode->currentStore->id,
                    'name' => $barcode->currentStore->name,
                    'store_code' => $barcode->currentStore->store_code,
                ] : null,
                'defective_record' => $barcode->defectiveRecord ? [
                    'defect_reason' => $barcode->defectiveRecord->defect_reason,
                    'condition' => $barcode->defectiveRecord->condition,
                    'discount_percentage' => $barcode->defectiveRecord->discount_percentage,
                ] : null,
            ];
        });

        // 5. Sales from this Batch
        $salesRecords = \App\Models\OrderItem::with(['order.customer', 'order.store'])
            ->where('product_batch_id', $batch->id)
            ->get()
            ->map(function ($item) {
                return [
                    'order_number' => $item->order->order_number,
                    'order_date' => $item->order->order_date?->format('Y-m-d H:i:s'),
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_amount' => $item->total_amount,
                    'barcode' => $item->barcode?->barcode,
                    'customer' => $item->order->customer ? [
                        'name' => $item->order->customer->name,
                        'customer_code' => $item->order->customer->customer_code,
                    ] : null,
                ];
            });

        // 6. Dispatch Records
        $dispatchRecords = \App\Models\ProductDispatchItem::with([
            'dispatch.sourceStore',
            'dispatch.destinationStore'
        ])
        ->where('product_batch_id', $batch->id)
        ->get()
        ->map(function ($item) {
            return [
                'dispatch_number' => $item->dispatch->dispatch_number,
                'dispatch_date' => $item->dispatch->dispatch_date?->format('Y-m-d H:i:s'),
                'status' => $item->dispatch->status,
                'quantity' => $item->quantity,
                'from_store' => $item->dispatch->sourceStore ? [
                    'name' => $item->dispatch->sourceStore->name,
                    'store_code' => $item->dispatch->sourceStore->store_code,
                ] : null,
                'to_store' => $item->dispatch->destinationStore ? [
                    'name' => $item->dispatch->destinationStore->name,
                    'store_code' => $item->dispatch->destinationStore->store_code,
                ] : null,
            ];
        });

        // 7. Movement Records
        $movementRecords = \App\Models\ProductMovement::with(['fromStore', 'toStore', 'performedBy'])
            ->where('product_batch_id', $batch->id)
            ->get()
            ->map(function ($movement) {
                return [
                    'movement_type' => $movement->movement_type,
                    'quantity' => $movement->quantity,
                    'timestamp' => $movement->created_at?->format('Y-m-d H:i:s'),
                    'from_store' => $movement->fromStore ? [
                        'name' => $movement->fromStore->name,
                        'store_code' => $movement->fromStore->store_code,
                    ] : null,
                    'to_store' => $movement->toStore ? [
                        'name' => $movement->toStore->name,
                        'store_code' => $movement->toStore->store_code,
                    ] : null,
                    'performed_by' => $movement->performedBy ? [
                        'name' => $movement->performedBy->name,
                    ] : null,
                    'notes' => $movement->notes,
                ];
            });

        // 8. Complete Activity History for this Batch
        $barcodeIds = collect($batch->barcodes)->pluck('id')->toArray();
        
        $activityHistory = Activity::where(function ($query) use ($batch, $barcodeIds) {
            $query->where(function ($q) use ($batch) {
                // Batch activities
                $q->where('subject_type', 'App\\Models\\ProductBatch')
                  ->where('subject_id', $batch->id);
            })->orWhere(function ($q) use ($barcodeIds) {
                // Barcode activities for this batch
                $q->where('subject_type', 'App\\Models\\ProductBarcode')
                  ->whereIn('subject_id', $barcodeIds);
            });
        })
        ->with(['causer'])
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($activity) {
            return [
                'id' => $activity->id,
                'event' => $activity->event,
                'subject_type' => class_basename($activity->subject_type),
                'description' => $activity->description,
                'timestamp' => $activity->created_at->format('Y-m-d H:i:s'),
                'human_time' => $activity->created_at->diffForHumans(),
                'performed_by' => $activity->causer ? [
                    'id' => $activity->causer->id,
                    'type' => class_basename($activity->causer),
                    'name' => $activity->causer->name ?? $activity->causer->username ?? 'Unknown',
                ] : null,
                'changes' => $this->extractChanges($activity),
            ];
        });

        $totalBarcodes = count($batch->barcodes);
        $activeBarcodes = collect($batch->barcodes)->where('is_active', true)->count();
        $defectiveBarcodes = collect($batch->barcodes)->where('is_defective', true)->count();
        
        $stockSummary = [
            'total_barcodes_generated' => $totalBarcodes,
            'active_barcodes' => $activeBarcodes,
            'sold_barcodes' => $totalBarcodes - $activeBarcodes,
            'defective_barcodes' => $defectiveBarcodes,
            'current_stock_quantity' => $batch->quantity,
            'total_sales' => $salesRecords->count(),
            'total_dispatches' => $dispatchRecords->count(),
            'total_movements' => $movementRecords->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'batch' => $batchInfo,
                'product' => $productInfo,
                'store' => $storeInfo,
                'barcodes' => $barcodes,
                'sales_records' => $salesRecords,
                'dispatch_records' => $dispatchRecords,
                'movement_records' => $movementRecords,
                'activity_history' => $activityHistory,
                'stock_summary' => $stockSummary,
            ]
        ]);
    }

    /**
     * Helper method to extract before/after changes from activity
     */
    private function extractChanges($activity)
    {
        $changes = [];
        
        if ($activity->event === 'updated' && $activity->properties) {
            $old = $activity->properties['old'] ?? [];
            $new = $activity->properties['attributes'] ?? [];
            
            foreach ($new as $key => $value) {
                if (isset($old[$key]) && $old[$key] !== $value) {
                    $changes[$key] = [
                        'from' => $old[$key],
                        'to' => $value,
                    ];
                }
            }
        }
        
        return $changes;
    }
}
