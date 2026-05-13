<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductBarcode;
use App\Models\Service;
use App\Models\PaymentMethod;
use App\Models\Employee;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Services\FloatingBarcodeRelabelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ManualSaleRelabelController extends Controller
{
    protected $relabelService;

    public function __construct(FloatingBarcodeRelabelService $relabelService)
    {
        $this->relabelService = $relabelService;
    }

    /**
     * Create a manual POS sale with automatic barcode relabelling.
     * 
     * POST /api/manual-relabel-sale
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'store_id' => 'required|exists:stores,id',
            'order_type' => 'required|in:counter',
            'items' => 'required_without:services|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.batch_id' => 'required_with:items|exists:product_batches,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'salesman_id' => 'required|exists:employees,id',
            'customer' => 'nullable|array',
            'customer.name' => 'required_with:customer|string',
            'customer.phone' => 'required_with:customer|string',
            'customer.address' => 'nullable|string',
            'customer_id' => 'nullable|exists:customers,id',
            'notes' => 'nullable|string',
            'shipping_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'services' => 'required_without:items|array',
            'services.*.service_id' => 'required_with:services|exists:services,id',
            'services.*.quantity' => 'required_with:services|integer|min:1',
            'services.*.unit_price' => 'required_with:services|numeric|min:0',
            'services.*.discount_amount' => 'nullable|numeric|min:0',
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
            // 1. Get or create customer
            $customerId = $this->getOrCreateCustomer($request);

            // 2. Create the order
            $order = Order::create([
                'order_number' => Order::generateOrderNumber('counter'),
                'customer_id' => $customerId,
                'store_id' => $request->store_id,
                'order_type' => 'counter',
                'status' => 'pending',
                'payment_status' => 'pending',
                'subtotal' => 0,
                'total_amount' => 0,
                'notes' => $request->notes,
                'shipping_amount' => $request->shipping_amount ?? 0,
                'discount_amount' => $request->discount_amount ?? 0, // Global discount
                'salesman_id' => $request->salesman_id,
                'created_by' => Auth::id(),
                'order_date' => now(),
            ]);

            // 3. Process items with automatic relabelling
            foreach ($request->items as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                $batch = ProductBatch::findOrFail($itemData['batch_id']);
                $quantity = (int)$itemData['quantity'];
                
                // Validate stock (both local and global)
                if ($batch->quantity < $quantity) {
                    throw new \Exception("Insufficient stock for {$product->name}. Available: {$batch->quantity}");
                }

                // Check global reservation table
                $reservedRecord = \App\Models\ReservedProduct::where('product_id', $product->id)->lockForUpdate()->first();
                $globalAvailable = $reservedRecord ? $reservedRecord->available_inventory : 0;
                
                if ($globalAvailable < $quantity) {
                    throw new \Exception("Cannot sell {$product->name} (Global available inventory: {$globalAvailable}). Stock is reserved for online orders.");
                }

                $unitDiscount = ($itemData['discount_amount'] ?? 0) / $quantity;
                $unitPrice = (float)$itemData['unit_price'];
                $taxPercentage = $batch->tax_percentage ?? 0;

                // Determine if we need to relabel
                if (!empty($itemData['barcode'])) {
                    $barcode = ProductBarcode::where('barcode', $itemData['barcode'])
                        ->where('product_id', $product->id)
                        ->where('batch_id', $batch->id)
                        ->first();

                    if (!$barcode) {
                        throw new \Exception("Barcode {$itemData['barcode']} not found for product {$product->name}");
                    }

                    $this->relabelService->validateBarcodeCanBeSold($barcode, $order);

                    $taxCalculation = $this->calculateTax($unitPrice, $quantity, $taxPercentage);
                    $tax = $taxCalculation['total_tax'];

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_batch_id' => $batch->id,
                        'product_barcode_id' => $barcode->id,
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'discount_amount' => $itemData['discount_amount'] ?? 0,
                        'tax_amount' => $tax,
                        'cogs' => round(($batch->cost_price ?? 0) * $quantity, 2),
                        'total_amount' => ($unitPrice * $quantity) - ($itemData['discount_amount'] ?? 0),
                    ]);
                } else {
                    // Split into individual units for relabelling
                    for ($i = 0; $i < $quantity; $i++) {
                        // Generate a new relabelled barcode
                        $relabel = $this->relabelService->createReplacement([
                            'batch_id' => $batch->id,
                            'product_id' => $product->id,
                            'store_id' => $request->store_id,
                            'reason' => 'pos_manual_entry',
                            'notes' => "Auto-relabelled during POS manual sale #{$order->order_number}",
                        ], Auth::id());

                        $barcode = $relabel->replacementBarcode;

                        // Calculate tax for one unit
                        $taxCalculation = $this->calculateTax($unitPrice, 1, $taxPercentage);
                        $tax = $taxCalculation['total_tax'];

                        // Create OrderItem for this specific unit
                        OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'product_batch_id' => $batch->id,
                            'product_barcode_id' => $barcode->id,
                            'product_name' => $product->name,
                            'product_sku' => $product->sku,
                            'quantity' => 1,
                            'unit_price' => $unitPrice,
                            'discount_amount' => $unitDiscount,
                            'tax_amount' => $tax,
                            'cogs' => $batch->cost_price ?? 0,
                            'total_amount' => $unitPrice - $unitDiscount,
                        ]);
                    }
                }
            }

            // 4. Process services
            if ($request->filled('services')) {
                foreach ($request->services as $serviceData) {
                    $service = Service::findOrFail($serviceData['service_id']);
                    
                    $qty = $serviceData['quantity'];
                    $uPrice = $serviceData['unit_price'];
                    $sDiscount = $serviceData['discount_amount'] ?? 0;
                    $sTotal = ($qty * $uPrice) - $sDiscount;

                    $order->serviceItems()->create([
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                        'service_code' => $service->service_code,
                        'service_description' => $service->description,
                        'quantity' => $qty,
                        'unit_price' => $uPrice,
                        'discount_amount' => $sDiscount,
                        'base_price' => $service->base_price,
                        'total_price' => $sTotal,
                        'status' => 'pending',
                        'scheduled_date' => now(),
                    ]);
                }
            }

            // 5. Calculate totals
            $order->calculateTotals();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Manual sale created with automatic relabelling',
                'data' => $this->formatOrderResponse($order->fresh([
                    'customer',
                    'store',
                    'items.product',
                    'items.barcode',
                    'serviceItems'
                ]), true)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Manual sale relabel error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    private function formatOrderResponse(Order $order, $detailed = false)
    {
        $totalCogs = $order->items->sum('cogs');
        
        $response = [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_type' => $order->order_type,
            'order_type_label' => match($order->order_type) {
                'counter' => 'In-Person Sale',
                'social_commerce' => 'Social Commerce',
                'ecommerce' => 'E-commerce',
                default => $order->order_type,
            },
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'customer' => [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'email' => $order->customer->email,
                'customer_code' => $order->customer->customer_code,
            ],
            'store' => $order->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
                'address' => $order->store->address,
                'phone' => $order->store->phone,
            ] : null,
            'salesman' => $order->createdBy ? [
                'id' => $order->createdBy->id,
                'name' => $order->createdBy->name,
            ] : null,
            'subtotal' => (string)number_format((float)$order->subtotal, 2, '.', ''),
            'tax_amount' => (string)number_format((float)$order->tax_amount, 2, '.', ''),
            'item_discount' => (string)number_format((float)$order->items->sum('discount_amount') + $order->serviceItems->sum('discount_amount'), 2, '.', ''),
            'discount_amount' => (string)number_format((float)$order->discount_amount, 2, '.', ''),
            'total_discount' => (string)number_format((float)($order->items->sum('discount_amount') + $order->serviceItems->sum('discount_amount') + ($order->discount_amount ?? 0)), 2, '.', ''),
            'shipping_amount' => (string)number_format((float)$order->shipping_amount, 2, '.', ''),
            'total_amount' => (string)number_format((float)$order->total_amount, 2, '.', ''),
            'paid_amount' => (string)number_format((float)$order->paid_amount, 2, '.', ''),
            'outstanding_amount' => (string)number_format((float)$order->outstanding_amount, 2, '.', ''),
            'order_date' => $order->order_date->format('Y-m-d H:i:s'),
            'created_at' => $order->created_at->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $response['items'] = $order->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_sku' => $item->product_sku,
                    'batch_id' => $item->product_batch_id,
                    'barcode' => $item->barcode?->barcode,
                    'quantity' => $item->quantity,
                    'unit_price' => (string)number_format((float)$item->unit_price, 2, '.', ''),
                    'discount_amount' => (string)number_format((float)$item->discount_amount, 2, '.', ''),
                    'tax_amount' => (string)number_format((float)$item->tax_amount, 2, '.', ''),
                    'total_amount' => (string)number_format((float)$item->total_amount, 2, '.', ''),
                ];
            });

            $response['services'] = $order->serviceItems->map(function ($item) {
                return [
                    'id' => $item->id,
                    'service_id' => $item->service_id,
                    'service_name' => $item->service_name,
                    'quantity' => $item->quantity,
                    'unit_price' => (string)number_format((float)$item->unit_price, 2, '.', ''),
                    'discount_amount' => (string)number_format((float)$item->discount_amount, 2, '.', ''),
                    'total_amount' => (string)number_format((float)$item->total_price, 2, '.', ''),
                ];
            });
        }

        return $response;
    }

    protected function getOrCreateCustomer(Request $request)
    {
        if ($request->filled('customer_id')) {
            return $request->customer_id;
        }

        if ($request->filled('customer') && !empty($request->customer['phone'])) {
            $customerData = $request->customer;
            $customer = Customer::where('phone', $customerData['phone'])->first();
            if (!$customer) {
                $customer = Customer::create([
                    'name' => $customerData['name'],
                    'phone' => $customerData['phone'],
                    'address' => $customerData['address'] ?? null,
                    'customer_type' => 'counter',
                    'status' => 'active',
                    'created_by' => Auth::id(),
                ]);
            } else {
                // Optionally update address if provided
                if (!empty($customerData['address']) && empty($customer->address)) {
                    $customer->update(['address' => $customerData['address']]);
                }
            }
            return $customer->id;
        }

        // No customer provided - use or create walk-in customer for counter orders
        $customer = Customer::firstOrCreate(
            ['phone' => 'WALK-IN'],
            [
                'name' => 'Walk-in Customer',
                'customer_type' => 'counter',
                'status' => 'active',
                'created_by' => Auth::id(),
            ]
        );

        return $customer->id;
    }

    private function calculateTax(float $unitPrice, int $quantity, float $taxPercentage): array
    {
        $taxMode = config('app.tax_mode', 'inclusive');

        if ($taxPercentage <= 0) {
            return [
                'base_price' => $unitPrice,
                'tax_per_unit' => 0,
                'total_tax' => 0,
            ];
        }

        if ($taxMode === 'inclusive') {
            $basePrice = round($unitPrice / (1 + ($taxPercentage / 100)), 2);
            $taxPerUnit = round($unitPrice - $basePrice, 2);
        } else {
            $basePrice = $unitPrice;
            $taxPerUnit = round($unitPrice * ($taxPercentage / 100), 2);
        }

        return [
            'base_price' => $basePrice,
            'tax_per_unit' => $taxPerUnit,
            'total_tax' => round($taxPerUnit * $quantity, 2),
        ];
    }
}
