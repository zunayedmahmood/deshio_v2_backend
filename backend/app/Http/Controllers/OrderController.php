<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Store;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Models\ReservedProduct;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    use DatabaseAgnosticSearch;
    /**
     * List all orders with filters
     * 
     * GET /api/orders?order_type=counter&status=pending&payment_status=partially_paid
     */
    public function index(Request $request)
    {
        $query = Order::with([
            'customer',
            'store', // Nullable - E-commerce orders have no store until manually assigned
            'items.product',
            'items.batch',
            'payments.paymentMethod',
        ]);

        // Filter by order type (counter, social_commerce, ecommerce)
        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment status
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by fulfillment status
        if ($request->filled('fulfillment_status')) {
            $query->where('fulfillment_status', $request->fulfillment_status);
        }

        // Filter by store
        if ($request->filled('store_id')) {
            if ($request->store_id === 'unassigned' || $request->store_id === 'null') {
                $query->whereNull('store_id');
            } else {
                $query->where('store_id', $request->store_id);
            }
        }

        // Filter unassigned orders (pending store assignment)
        // Includes both ecommerce and social_commerce orders
        if ($request->boolean('pending_assignment')) {
            $status = $request->input('status', 'pending_assignment');
            $query->whereNull('store_id')
                  ->whereIn('order_type', ['ecommerce', 'social_commerce'])
                  ->where('status', $status);
        }

        // Filter by customer
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by salesman/employee
        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('order_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('order_date', '<=', $request->date_to);
        }

        // Search by order number or customer name
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $this->whereLike($q, 'order_number', $request->search);
                $q->orWhereHas('customer', function ($customerQuery) use ($request) {
                    $this->whereLike($customerQuery, 'name', $request->search);
                    $this->orWhereLike($customerQuery, 'phone', $request->search);
                });
            });
        }

        // Filter overdue payments
        if ($request->boolean('overdue')) {
            $query->where('payment_status', 'overdue');
        }

        // Filter installment orders
        if ($request->boolean('installment_only')) {
            $query->where('is_installment_payment', true);
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $orders = $query->paginate($request->input('per_page', 20));

        $formattedOrders = [];
        foreach ($orders as $order) {
            $formattedOrders[] = $this->formatOrderResponse($order);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $orders->currentPage(),
                'data' => $formattedOrders,
                'first_page_url' => $orders->url(1),
                'from' => $orders->firstItem(),
                'last_page' => $orders->lastPage(),
                'last_page_url' => $orders->url($orders->lastPage()),
                'next_page_url' => $orders->nextPageUrl(),
                'path' => $orders->path(),
                'per_page' => $orders->perPage(),
                'prev_page_url' => $orders->previousPageUrl(),
                'to' => $orders->lastItem(),
                'total' => $orders->total(),
            ]
        ]);
    }

    /**
     * Get specific order details
     * 
     * GET /api/orders/{id}
     */
    public function show($id)
    {
        $order = Order::with([
            'customer',
            'store',
            'items.product.reservedProduct',
            'items.batch',
            'items.barcode',
            'payments.paymentMethod',
            'payments.processedBy',
            'payments.paymentSplits.paymentMethod',
            'payments.cashDenominations',
        ])->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->formatOrderResponse($order, true)
        ]);
    }

    /**
     * Create new order
     * Handles all 3 sales channels: counter, social_commerce, ecommerce
     * 
     * POST /api/orders
     * Body: {
     *   "order_type": "counter|social_commerce|ecommerce",
     *   "customer_id": 1,  // Or create on-the-fly
     *   "customer": {...},  // If customer doesn't exist
     *   "store_id": 1,
     *   "items": [
     *     {
     *       "product_id": 1,
     *       "batch_id": 1,  // Specific batch to sell from
     *       "quantity": 2,
     *       "unit_price": 750.00,
     *       "discount_amount": 50.00
     *     }
     *   ],
     *   "discount_amount": 100.00,
     *   "shipping_amount": 50.00,
     *   "notes": "Customer wants delivery tomorrow",
     *   "shipping_address": {...},
     *   "payment": {  // Optional: immediate payment
     *     "payment_method_id": 1,
     *     "amount": 1000.00,
     *     "payment_type": "partial|full|installment"
     *   },
     *   "installment_plan": {  // Optional: setup installments
     *     "total_installments": 3,
     *     "installment_amount": 500.00,
     *     "start_date": "2024-12-01"
     *   }
     * }
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_type' => 'required|in:counter,social_commerce,ecommerce',
            'customer_id' => 'nullable|exists:customers,id',
            'customer' => 'nullable|array',  // Made optional - will use walk-in customer if not provided
            'customer.name' => 'required_with:customer|string',
            'customer.phone' => 'required_with:customer|string',
            'customer.email' => 'nullable|email',
            'customer.address' => 'nullable|string',
            'store_id' => 'nullable|exists:stores,id',  // Required for counter, optional for social_commerce/ecommerce
            'salesman_id' => 'nullable|exists:employees,id',  // Manual salesman entry for POS
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.batch_id' => 'nullable|exists:product_batches,id',  // Optional for pre-orders
            'items.*.barcode' => 'nullable|string|exists:product_barcodes,barcode',  // Optional barcode for tracking
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'shipping_address' => 'nullable|array',
            'payment' => 'nullable|array',
            'payment.payment_method_id' => 'required_with:payment|exists:payment_methods,id',
            'payment.amount' => 'required_with:payment|numeric|min:0.01',
            'payment.payment_type' => 'nullable|in:full,partial,installment,advance',
            'installment_plan' => 'nullable|array',
            'installment_plan.total_installments' => 'required_with:installment_plan|integer|min:2',
            'installment_plan.installment_amount' => 'required_with:installment_plan|numeric|min:0.01',
            'installment_plan.start_date' => 'nullable|date',
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
            // Determine store_id based on order type
            $storeId = $request->store_id;
            
            // For counter/POS orders: require store_id (from employee's store or explicitly provided)
            if ($request->order_type === 'counter') {
                if (!$storeId) {
                    // Get store from authenticated employee
                    $employee = Auth::user();
                    if (!$employee || !$employee->store_id) {
                        throw new \Exception('Counter orders require a store. Employee must be assigned to a store or store_id must be provided.');
                    }
                    $storeId = $employee->store_id;
                }
            }
            
            // For social_commerce and ecommerce: store_id should be NULL (assigned later)
            // If provided, we'll use it, but it's optional
            if (in_array($request->order_type, ['social_commerce', 'ecommerce'])) {
                // Allow store_id to be null - will be assigned during fulfillment
                $storeId = $storeId ?? null;
            }
            
            // Get or create customer
            if ($request->filled('customer_id')) {
                $customer = Customer::findOrFail($request->customer_id);
            } elseif ($request->filled('customer')) {
                // Create customer on-the-fly based on order type
                $customerData = $request->customer;
                $customerData['created_by'] = Auth::id();
                
                // Check if customer exists by phone
                $existing = Customer::where('phone', $customerData['phone'])->first();
                if ($existing) {
                    $customer = $existing;
                } else {
                    if ($request->order_type === 'counter') {
                        $customer = Customer::create([
                            'name' => $customerData['name'],
                            'phone' => $customerData['phone'],
                            'email' => $customerData['email'] ?? null,
                            'address' => $customerData['address'] ?? null,
                            'customer_type' => 'counter',
                            'status' => 'active',
                            'created_by' => Auth::id(),
                        ]);
                    } elseif ($request->order_type === 'social_commerce') {
                        $customer = Customer::create([
                            'name' => $customerData['name'],
                            'phone' => $customerData['phone'],
                            'email' => $customerData['email'] ?? null,
                            'address' => $customerData['address'] ?? null,
                            'customer_type' => 'social_commerce',
                            'status' => 'active',
                            'created_by' => Auth::id(),
                        ]);
                    } else {
                        $customer = Customer::create([
                            'name' => $customerData['name'],
                            'phone' => $customerData['phone'],
                            'email' => $customerData['email'] ?? null,
                            'address' => $customerData['address'] ?? null,
                            'customer_type' => 'ecommerce',
                            'status' => 'active',
                            'created_by' => Auth::id(),
                        ]);
                    }
                }
            } else {
                // No customer provided - use or create walk-in customer for counter orders
                if ($request->order_type === 'counter') {
                    $customer = Customer::firstOrCreate(
                        ['phone' => 'WALK-IN'],
                        [
                            'name' => 'Walk-in Customer',
                            'customer_type' => 'counter',
                            'status' => 'active',
                            'created_by' => Auth::id(),
                        ]
                    );
                } else {
                    // For non-counter orders, customer is required
                    throw new \Exception('Customer information is required for ' . $request->order_type . ' orders');
                }
            }

            // Get salesman (employee receiving the sale credit)
            // Keep created_by as the authenticated actor for audit purposes.
            if ($request->filled('salesman_id')) {
                $salesmanId = (int) $request->salesman_id;
                $salesman = Employee::findOrFail($salesmanId);
            } else {
                $salesmanId = (int) Auth::id();
                $salesman = Employee::find($salesmanId);
            }

            $actorId = (int) Auth::id();

            // Determine fulfillment status based on order type
            // Counter orders: immediate fulfillment (barcode scanned at POS)
            // Social/Ecommerce: deferred fulfillment (warehouse scans barcodes later)
            $fulfillmentStatus = null;
            if (in_array($request->order_type, ['social_commerce', 'ecommerce'])) {
                $fulfillmentStatus = 'pending_fulfillment';
            }

            // Determine initial status based on order type and store assignment
            // Orders without store need assignment first
            $initialStatus = 'pending';
            if (in_array($request->order_type, ['social_commerce', 'ecommerce'])) {
                if ($storeId === null) {
                    $initialStatus = 'pending_assignment'; // Waiting for store assignment
                } elseif ($request->order_type === 'social_commerce') {
                    $initialStatus = 'assigned_to_store'; // Direct store assignment
                }
            }

            // Create order
            $order = Order::create([
                'customer_id' => $customer->id,
                'store_id' => $storeId,  // Use calculated store_id (null for social_commerce/ecommerce)
                'order_type' => $request->order_type,
                'status' => $initialStatus,
                'payment_status' => 'pending',
                'fulfillment_status' => $fulfillmentStatus,
                'discount_amount' => $request->discount_amount ?? 0,
                'shipping_amount' => $request->shipping_amount ?? 0,
                'notes' => $request->notes,
                'shipping_address' => $request->shipping_address,
                'created_by' => $actorId,
                'salesman_id' => $salesmanId,
                'order_date' => now(),
            ]);

            // Save shipping address to customer_addresses table if provided
            // This ensures Pathao integration data is stored for later use
            if ($request->filled('shipping_address') && is_array($request->shipping_address)) {
                $shippingData = $request->shipping_address;
                
                // Only create if we have essential address info (NOT NULL columns must have values)
                if (!empty($shippingData['address_line_1']) && !empty($shippingData['city'])) {
                    // Check if this exact address already exists for the customer
                    $existingAddress = \App\Models\CustomerAddress::where('customer_id', $customer->id)
                        ->where('address_line_1', $shippingData['address_line_1'])
                        ->where('city', $shippingData['city'])
                        ->first();
                    
                    if (!$existingAddress) {
                        \App\Models\CustomerAddress::create([
                            'customer_id' => $customer->id,
                            'type' => 'shipping',
                            'name' => $shippingData['name'] ?? $customer->name,
                            'phone' => $shippingData['phone'] ?? $customer->phone,
                            'address_line_1' => $shippingData['address_line_1'],
                            'address_line_2' => $shippingData['address_line_2'] ?? null,
                            'city' => $shippingData['city'],
                            'state' => $shippingData['state'] ?? '',  // NOT NULL - use empty string
                            'postal_code' => $shippingData['postal_code'] ?? '',  // NOT NULL - use empty string
                            'country' => $shippingData['country'] ?? 'Bangladesh',
                            'pathao_city_id' => $shippingData['pathao_city_id'] ?? null,
                            'pathao_zone_id' => $shippingData['pathao_zone_id'] ?? null,
                            'pathao_area_id' => $shippingData['pathao_area_id'] ?? null,
                            'landmark' => $shippingData['landmark'] ?? null,
                            'delivery_instructions' => $shippingData['delivery_instructions'] ?? null,
                            'is_default_shipping' => false,  // Don't override existing default
                            'is_default_billing' => false,
                        ]);
                    }
                }
            }

            // Add items
            $subtotal = 0;
            $taxTotal = 0;
            $totalItemDiscount = 0;
            $hasPreOrderItems = false;  // Track if any items don't have batches

            foreach ($request->items as $itemData) {
                $product = Product::findOrFail($itemData['product_id']);
                
                // Batch is optional for pre-orders
                $batch = !empty($itemData['batch_id']) 
                    ? ProductBatch::findOrFail($itemData['batch_id']) 
                    : null;

                // Mark as pre-order if any item has no batch
                if (!$batch) {
                    $hasPreOrderItems = true;
                }

                // Validate stock availability only if batch exists (not a pre-order)
                if ($batch) {
                    if ($batch->quantity < $itemData['quantity']) {
                        throw new \Exception("Insufficient local stock for {$product->name}. Available: {$batch->quantity}");
                    }
                    
                    // NEW LOGIC: Check global reservation table
                    $reservedRecord = \App\Models\ReservedProduct::where('product_id', $product->id)->lockForUpdate()->first();
                    $globalAvailable = $reservedRecord ? $reservedRecord->available_inventory : 0;
                    
                    if ($globalAvailable < $itemData['quantity']) {
                        throw new \Exception("Cannot sell {$product->name} (Global available inventory: {$globalAvailable}). Stock is reserved for online orders.");
                    }
                } elseif ($request->order_type === 'social_commerce' && $request->store_id) {
                    // Check store-level stock for specific store assignment without batch
                    $storeStock = ProductBatch::where('product_id', $product->id)
                        ->where('store_id', $request->store_id)
                        ->sum('quantity');
                    
                    if ($storeStock < $itemData['quantity']) {
                        throw new \Exception("Insufficient stock for {$product->name} at the selected branch. Available: {$storeStock}");
                    }

                    // Check global available inventory too
                    $reservedRecord = \App\Models\ReservedProduct::where('product_id', $product->id)->lockForUpdate()->first();
                    $globalAvailable = $reservedRecord ? $reservedRecord->available_inventory : 0;
                    
                    // Online orders (social commerce) ARE blocked by global reservations
                    if ($globalAvailable < $itemData['quantity']) {
                        throw new \Exception("Cannot sell {$product->name} (Global available inventory: {$globalAvailable}). Stock is reserved for online orders.");
                    }
                }

                // Validate batch belongs to the store (only if batch exists AND store_id is provided)
                // For social_commerce/ecommerce without store_id, skip this validation (store assigned later)
                if ($batch && $request->store_id && $batch->store_id != $request->store_id) {
                    throw new \Exception("Product batch not available at this store");
                }

                // Handle barcode if provided (optional for backward compatibility)
                $barcodeId = null;
                if (!empty($itemData['barcode']) && $batch) {
                    $barcode = \App\Models\ProductBarcode::where('barcode', $itemData['barcode'])
                        ->where('product_id', $product->id)
                        ->where('batch_id', $batch->id)
                        ->first();
                    
                    if (!$barcode) {
                        throw new \Exception("Barcode {$itemData['barcode']} not found for product {$product->name}");
                    }
                    
                    // Check if barcode is already sold
                    if (in_array($barcode->current_status, ['sold', 'with_customer'])) {
                        throw new \Exception("Barcode {$itemData['barcode']} has already been sold");
                    }
                    
                    if ($barcode->is_defective) {
                        throw new \Exception("Barcode {$itemData['barcode']} is marked as defective");
                    }
                    
                    $barcodeId = $barcode->id;
                }
                
                // Debug: Log barcode capture
                Log::info('Order item barcode capture', [
                    'barcode_value' => $itemData['barcode'] ?? 'NOT_PROVIDED',
                    'barcode_id' => $barcodeId,
                    'product_id' => $product->id,
                    'batch_id' => $batch?->id
                ]);

                $quantity = $itemData['quantity'];
                $unitPrice = $itemData['unit_price'];
                $discount = $itemData['discount_amount'] ?? 0;
                
                // Calculate tax using the helper method (respects TAX_MODE)
                $taxPercentage = $batch?->tax_percentage ?? 0;
                $taxCalculation = $this->calculateTax($unitPrice, $quantity, $taxPercentage);
                $tax = $taxCalculation['total_tax'];
                
                // For inclusive mode: subtotal includes tax
                // For exclusive mode: subtotal is base, tax added separately
                $itemSubtotal = $quantity * $unitPrice;
                $itemTotal = $itemSubtotal - $discount;

                // Calculate COGS from batch cost price (0 if no batch - pre-order)
                $cogs = $batch ? round(($batch->cost_price ?? 0) * $quantity, 2) : 0;
                
                // Log COGS during order creation for debugging
                Log::info('Order Item COGS at Creation', [
                    'product_name' => $product->name,
                    'batch_id' => $batch?->id,
                    'batch_cost_price' => $batch?->cost_price,
                    'quantity' => $quantity,
                    'calculated_cogs' => $cogs,
                    'is_preorder' => !$batch,
                ]);

                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_batch_id' => $batch?->id,  // Nullable for pre-orders
                    'product_barcode_id' => $barcodeId,  // NEW: Store barcode if provided
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                    'cogs' => $cogs,
                    'total_amount' => $itemTotal,
                ]);

                $subtotal += $itemSubtotal;
                $taxTotal += $tax;
                $totalItemDiscount += $discount;

                // Stock deduction is now centralizing in OrderController@complete
                // Reservations are handled by OrderItemObserver
                Log::info('Order item created, reservation handled by observer', [
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ]);
            }

            // Calculate order totals based on tax mode
            $taxMode = config('app.tax_mode', 'inclusive');
            $orderDiscount = $request->discount_amount ?? 0;
            $shippingAmount = $request->shipping_amount ?? 0;
            
            if ($taxMode === 'inclusive') {
                // Inclusive: tax already in subtotal
                $totalAmount = $subtotal - max($orderDiscount, $totalItemDiscount) + $shippingAmount;
            } else {
                // Exclusive: add tax to subtotal
                $totalAmount = $subtotal + $taxTotal - max($orderDiscount, $totalItemDiscount) + $shippingAmount;
            }

            $order->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxTotal,
                'total_amount' => $totalAmount,
                'outstanding_amount' => $totalAmount,
                'is_preorder' => $hasPreOrderItems,  // Mark order as pre-order if any items lack batches
            ]);

            // Setup installment plan if requested
            if ($request->filled('installment_plan')) {
                $plan = $request->installment_plan;
                $order->setupInstallmentPlan(
                    $plan['total_installments'],
                    $plan['installment_amount'],
                    $plan['start_date'] ?? null
                );
            }

            // Process immediate payment if provided
            if ($request->filled('payment')) {
                $paymentMethod = PaymentMethod::findOrFail($request->payment['payment_method_id']);
                $payment = $order->addPayment(
                    $paymentMethod,
                    $request->payment['amount'],
                    [],
                    $salesman
                );

                $payment->update([
                    'payment_type' => $request->payment['payment_type'] ?? 'partial',
                ]);

                // Update order payment status
                $order->updatePaymentStatus();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => $this->formatOrderResponse($order->fresh([
                    'customer',
                    'store',
                    'items.product',
                    'items.batch',
                    'payments.paymentMethod'
                ]), true)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update order details (before completion/fulfillment)
     * 
     * PATCH /api/orders/{id}
     * 
     * Allowed updates:
     * - Customer information (name, phone, address)
     * - Shipping address
     * - Discount amount
     * - Shipping amount
     * - Notes
     * 
     * Cannot update:
     * - Items (use addItem/updateItem/removeItem)
     * - Status/payment after fulfillment
     * - Order type
     */
    public function update(Request $request, $id)
    {
        $order = Order::with('items')->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // Only allow updates for pending/confirmed orders
        if (!in_array($order->status, ['pending', 'pending_assignment', 'confirmed', 'assigned_to_store', 'picking'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update order in current status: ' . $order->status
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_email' => 'nullable|email',
            'customer_address' => 'nullable|string',
            'shipping_address' => 'nullable|array',
            'shipping_address.address_line1' => 'required_with:shipping_address|string',
            'shipping_address.address_line2' => 'nullable|string',
            'shipping_address.city' => 'required_with:shipping_address|string',
            'shipping_address.state' => 'nullable|string',
            'shipping_address.postal_code' => 'nullable|string',
            'shipping_address.country' => 'required_with:shipping_address|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'shipping_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
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
            // Update customer information if provided
            if ($request->has('customer_name') || $request->has('customer_phone') || 
                $request->has('customer_email') || $request->has('customer_address')) {
                
                $customer = $order->customer;
                if ($customer && $customer->phone !== 'WALK-IN') {
                    if ($request->filled('customer_name')) {
                        $customer->name = $request->customer_name;
                    }
                    if ($request->filled('customer_phone')) {
                        $customer->phone = $request->customer_phone;
                    }
                    if ($request->filled('customer_email')) {
                        $customer->email = $request->customer_email;
                    }
                    if ($request->filled('customer_address')) {
                        $customer->address = $request->customer_address;
                    }
                    $customer->save();
                }
            }

            // Update order fields
            if ($request->has('shipping_address')) {
                $order->shipping_address = $request->shipping_address;
            }

            if ($request->has('discount_amount')) {
                $oldDiscount = $order->discount_amount;
                $order->discount_amount = $request->discount_amount;
                
                $totalItemDiscount = $order->items->sum('discount_amount');
                $taxTotal = $order->items->sum('tax_amount');
                $taxMode = config('app.tax_mode', 'inclusive');

                if ($taxMode === 'inclusive') {
                    $order->total_amount = $order->subtotal - $request->discount_amount - $totalItemDiscount + $order->shipping_amount;
                } else {
                    $order->total_amount = $order->subtotal + $taxTotal - $request->discount_amount - $totalItemDiscount + $order->shipping_amount;
                }
                $order->outstanding_amount = $order->total_amount - $order->paid_amount;
            }

            if ($request->has('shipping_amount')) {
                $oldShipping = $order->shipping_amount;
                $order->shipping_amount = $request->shipping_amount;
                
                $totalItemDiscount = $order->items->sum('discount_amount');
                $taxTotal = $order->items->sum('tax_amount');
                $taxMode = config('app.tax_mode', 'inclusive');

                if ($taxMode === 'inclusive') {
                    $order->total_amount = $order->subtotal - $order->discount_amount - $totalItemDiscount + $request->shipping_amount;
                } else {
                    $order->total_amount = $order->subtotal + $taxTotal - $order->discount_amount - $totalItemDiscount + $request->shipping_amount;
                }
                $order->outstanding_amount = $order->total_amount - $order->paid_amount;
            }

            if ($request->has('notes')) {
                $order->notes = $request->notes;
            }
            
            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $order->load(['customer', 'items.product', 'payments'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add item to existing order (before completion)
     * 
     * UPDATED: Supports both barcode scanning (for counter orders) and product selection (for social/ecommerce)
     * 
     * POST /api/orders/{id}/items
     * Body for COUNTER orders (barcode scanning):
     * {
     *   "barcode": "789012345023"  // Scan individual unit barcode
     *   OR
     *   "barcodes": ["789012345023", "789012345024"]  // Multiple units
     * }
     * 
     * Body for SOCIAL_COMMERCE/ECOMMERCE orders (product selection):
     * {
     *   "product_id": 1,
     *   "batch_id": 5,  // Optional - will use oldest batch if not provided
     *   "quantity": 2,
     *   "unit_price": 750.00,  // Optional - will use batch price
     *   "discount_amount": 0
     * }
     */
    public function addItem(Request $request, $id)
    {
        $order = Order::find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // Can only add items to pending orders
        if (!in_array($order->status, ['pending', 'pending_assignment', 'confirmed', 'assigned_to_store', 'picking'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot add items to ' . $order->status . ' orders'
            ], 422);
        }

        // NEW: Support BOTH barcode scanning AND product/batch selection
        // Counter orders: use barcodes
        // Social/Ecommerce orders: use product_id + batch_id + quantity
        $validator = Validator::make($request->all(), [
            // Barcode scanning (for counter orders)
            'barcode' => 'nullable|string|exists:product_barcodes,barcode',
            'barcodes' => 'nullable|array|min:1',
            'barcodes.*' => 'string|exists:product_barcodes,barcode',
            
            // Product selection (for social_commerce/ecommerce orders)
            'product_id' => 'nullable|exists:products,id',
            'batch_id' => 'nullable|exists:product_batches,id',
            'quantity' => 'required_with:product_id|integer|min:1',
            
            'unit_price' => 'nullable|numeric|min:0',  // Optional, use batch price if not provided
            'discount_amount' => 'nullable|numeric|min:0',
        ], [
            'barcode.exists' => 'Invalid barcode',
            'product_id.exists' => 'Product not found',
            'batch_id.exists' => 'Batch not found',
            'quantity.required_with' => 'Quantity is required when adding by product',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate that at least one method is provided
        $hasBarcode = $request->filled('barcode') || $request->filled('barcodes');
        $hasProduct = $request->filled('product_id');
        
        if (!$hasBarcode && !$hasProduct) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide either barcode(s) or product_id to add item'
            ], 422);
        }

        if ($hasBarcode && $hasProduct) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot provide both barcode and product_id. Choose one method.'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $addedItems = [];
            
            // METHOD 1: Add by barcode (counter orders)
            if ($hasBarcode) {
                // Normalize to array
                $barcodesToAdd = $request->has('barcodes') 
                    ? $request->barcodes 
                    : [$request->barcode];
                
                foreach ($barcodesToAdd as $barcodeValue) {
                    $barcode = \App\Models\ProductBarcode::where('barcode', $barcodeValue)
                        ->with(['product', 'batch'])
                        ->first();

                    if (!$barcode) {
                        throw new \Exception("Barcode {$barcodeValue} not found");
                    }

                    // Validate barcode is available (not already sold/with customer)
                    if (in_array($barcode->current_status, ['sold', 'with_customer'])) {
                        throw new \Exception("Barcode {$barcodeValue} has already been sold and is not available");
                    }

                    // Validate barcode is not defective
                    if ($barcode->is_defective) {
                        throw new \Exception("Barcode {$barcodeValue} is marked as defective");
                    }

                    // Validate batch exists and has stock
                    if (!$barcode->batch) {
                        throw new \Exception("Barcode {$barcodeValue} is not associated with any batch");
                    }

                    $batch = $barcode->batch;
                    $product = $barcode->product;

                    // Validate batch has stock
                    if ($batch->quantity < 1) {
                        throw new \Exception("Product batch {$batch->batch_number} has no stock available");
                    }

                    // Validate store
                    if ($batch->store_id != $order->store_id) {
                        throw new \Exception("Product from batch {$batch->batch_number} not available at this store");
                    }

                    // Use provided price or batch price
                    $unitPrice = $request->unit_price ?? $batch->sell_price;
                    $discount = $request->discount_amount ?? 0;

                    // Calculate tax using the helper method (respects TAX_MODE)
                    $taxPercentage = $batch->tax_percentage ?? 0;
                    $taxCalculation = $this->calculateTax($unitPrice, 1, $taxPercentage);

                    // Create order item with barcode tracking
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_batch_id' => $batch->id,
                        'product_barcode_id' => $barcode->id,  // NEW: Track specific barcode
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'quantity' => 1,  // Always 1 per barcode
                        'unit_price' => $unitPrice,
                        'discount_amount' => $discount,
                        'tax_amount' => $taxCalculation['total_tax'],
                        'cogs' => round(($batch->cost_price ?? 0) * 1, 2),
                        'total_amount' => $unitPrice - $discount,  // For inclusive, total = unitPrice - discount
                    ]);

                    $addedItems[] = $orderItem;
                }
            }
            
            // METHOD 2: Add by product_id (social_commerce/ecommerce orders)
            if ($hasProduct) {
                $product = Product::findOrFail($request->product_id);
                $quantity = $request->quantity;
                
                // 1. ALWAYS verify global available inventory first
                $reserved = \App\Models\ReservedProduct::where('product_id', $product->id)->first();
                $availableGlobal = $reserved ? $reserved->available_inventory : 0;
                
                if ($availableGlobal < $quantity) {
                    throw new \Exception("Insufficient global stock for product '{$product->name}'. Available: {$availableGlobal}, requested: {$quantity}");
                }

                $batch = null;
                // 2. Optional: Select a batch if possible (prioritize the order's store if set, else any batch)
                // This is a "soft" selection - even if no batch is found here, we allow adding the item
                // because fulfillment scanning will eventually pick a batch at the branch.
                if ($request->filled('batch_id')) {
                    $batch = ProductBatch::find($request->batch_id);
                    // Use it even if it's from another store, but respect its quantity if selected explicitly
                    if ($batch && $batch->quantity < $quantity) {
                        Log::warning("Manually selected batch {$batch->id} has less stock than requested. Proceeding as global assignment.");
                    }
                } else {
                    // Optimized batch selection: try current store first (FIFO), else any store (FIFO)
                    $batchQuery = ProductBatch::where('product_id', $product->id)
                        ->where('quantity', '>=', $quantity)
                        ->where('availability', true)
                        ->where(function($q) {
                            $q->whereNull('expiry_date')->orWhere('expiry_date', '>', now());
                        })
                        ->orderBy('expiry_date', 'asc');
                    
                    if ($order->store_id) {
                        $batch = (clone $batchQuery)->where('store_id', $order->store_id)->first() ?: $batchQuery->first();
                    } else {
                        $batch = $batchQuery->first();
                    }
                }

                if ($batch) {
                    Log::info("Auto-selected batch {$batch->id} for added item in order {$order->id}");
                } else {
                    Log::info("Adding item to order {$order->id} without pre-assigned batch (will be assigned at fulfillment)");
                }
                
                // Use provided price or batch price or product base price
                $unitPrice = $request->unit_price ?? ($batch ? $batch->sell_price : $product->base_price ?? 0);
                $discount = $request->discount_amount ?? 0;
                
                // Calculate tax using the helper method (respects TAX_MODE)
                $taxPercentage = $batch ? ($batch->tax_percentage ?? 0) : 0;
                $taxCalculation = $this->calculateTax($unitPrice, $quantity, $taxPercentage);
                
                // Check if this product already exists in the order
                $existingItem = OrderItem::where('order_id', $order->id)
                    ->where('product_id', $product->id)
                    ->where('product_batch_id', $batch ? $batch->id : null)
                    ->first();
                
                if ($existingItem) {
                    // Update existing item quantity
                    $existingItem->quantity += $quantity;
                    $existingItem->tax_amount = $this->calculateTax($existingItem->unit_price, $existingItem->quantity, $taxPercentage)['total_tax'];
                    $existingItem->total_amount = ($existingItem->unit_price * $existingItem->quantity) - $existingItem->discount_amount + $existingItem->tax_amount;
                    $existingItem->cogs = $batch ? round(($batch->cost_price ?? 0) * $existingItem->quantity, 2) : 0;
                    $existingItem->save();
                    
                    $orderItem = $existingItem;
                } else {
                    // Create new order item (without barcode - will be assigned during fulfillment)
                    $orderItem = OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'product_batch_id' => $batch ? $batch->id : null,
                        'product_barcode_id' => null,  // No barcode yet - assigned during fulfillment
                        'product_name' => $product->name,
                        'product_sku' => $product->sku,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'discount_amount' => $discount,
                        'tax_amount' => $taxCalculation['total_tax'],
                        'cogs' => $batch ? round(($batch->cost_price ?? 0) * $quantity, 2) : 0,
                        'total_amount' => ($unitPrice * $quantity) - $discount + $taxCalculation['total_tax'],
                    ]);
                }
                
                $addedItems[] = $orderItem;
            }

            // Reset status to pending_assignment on edit
            $order->status = 'pending_assignment';

            // Recalculate order totals
            $order->calculateTotals();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($addedItems) . ' item(s) added successfully',
                'data' => [
                    'item' => [
                        'id' => $orderItem->id,
                        'product_name' => $orderItem->product_name,
                        'quantity' => $orderItem->quantity,
                        'unit_price' => number_format((float)$orderItem->unit_price, 2),
                        'total' => number_format((float)$orderItem->total_amount, 2),
                    ],
                    'order_totals' => [
                        'subtotal' => number_format((float)$order->fresh()->subtotal, 2),
                        'total_amount' => number_format((float)$order->fresh()->total_amount, 2),
                        'outstanding_amount' => number_format((float)$order->fresh()->outstanding_amount, 2),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Update item quantity/price
     * 
     * PUT /api/orders/{orderId}/items/{itemId}
     */
    public function updateItem(Request $request, $orderId, $itemId)
    {
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        if (!in_array($order->status, ['pending', 'pending_assignment', 'confirmed', 'assigned_to_store', 'picking'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update items in ' . $order->status . ' orders'
            ], 422);
        }

        $item = OrderItem::where('order_id', $orderId)->find($itemId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'quantity' => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
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
            if ($request->filled('quantity')) {
                $newQuantity = $request->quantity;
                $diff = $newQuantity - $item->quantity;

                // For increases, validate global available inventory
                if ($diff > 0) {
                    $reserved = \App\Models\ReservedProduct::where('product_id', $item->product_id)->lockForUpdate()->first();
                    $available = $reserved ? $reserved->available_inventory : 0;
                    
                    if ($available < $diff) {
                        throw new \Exception("Insufficient global stock to increase quantity for '{$item->product_name}'. Available: {$available}, needed: {$diff}");
                    }
                }

                // Recalculate tax for the new quantity
                $batch = $item->batch;
                $taxPercentage = $batch ? ($batch->tax_percentage ?? 0) : 0;
                $unitPrice = $request->unit_price ?? $item->unit_price;
                $item->tax_amount = $this->calculateTax($unitPrice, $newQuantity, $taxPercentage)['total_tax'];
                
                $item->updateQuantity($newQuantity);
            }

            if ($request->filled('unit_price')) {
                $item->unit_price = $request->unit_price;
            }

            if ($request->filled('discount_amount')) {
                $item->applyDiscount($request->discount_amount);
            }

            $item->save();

            // Reset status to pending_assignment on edit
            $order->status = 'pending_assignment';

            $order->calculateTotals();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Item updated successfully',
                'data' => [
                    'item' => [
                        'id' => $item->id,
                        'quantity' => $item->quantity,
                        'unit_price' => number_format((float)$item->unit_price, 2),
                        'total' => number_format((float)$item->total_amount, 2),
                    ],
                    'order_totals' => [
                        'total_amount' => number_format((float)$order->fresh()->total_amount, 2),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Remove item from order
     * 
     * DELETE /api/orders/{orderId}/items/{itemId}
     */
    public function removeItem($orderId, $itemId)
    {
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        if (!in_array($order->status, ['pending', 'pending_assignment', 'confirmed', 'assigned_to_store', 'picking'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove items from ' . $order->status . ' orders'
            ], 422);
        }

        $item = OrderItem::where('order_id', $orderId)->find($itemId);

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found'
            ], 404);
        }

        DB::beginTransaction();
        try {
            $item->delete();
            
            // Reset status to pending_assignment on edit
            $order->status = 'pending_assignment';
            
            $order->calculateTotals();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Item removed successfully',
                'data' => [
                    'order_totals' => [
                        'total_amount' => number_format((float)$order->fresh()->total_amount, 2),
                        'outstanding_amount' => number_format((float)$order->fresh()->outstanding_amount, 2),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Complete order and reduce inventory
     * 
     * UPDATED: Handles both barcode-tracked and non-barcode orders
     * For barcode-tracked items: marks individual barcodes as sold
     * For non-barcode items: just reduces batch quantity
     * This is called after payment is complete or for credit sales
     * 
     * NEW: Validates fulfillment requirement for social/ecommerce orders
     * 
     * PATCH /api/orders/{id}/complete
     */
    public function complete($id)
    {
        $order = Order::with(['items.batch', 'items.barcode'])->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        if (!in_array($order->status, ['pending', 'pending_assignment', 'assigned_to_store', 'confirmed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending, pending_assignment, confirmed or store-assigned orders can be completed'
            ], 422);
        }

        // Validate fulfillment requirement for social commerce and ecommerce
        if ($order->needsFulfillment() && !$order->isFulfilled()) {
            return response()->json([
                'success' => false,
                'message' => 'Order must be fulfilled before completion. Please scan barcodes at warehouse first.',
                'hint' => 'Call POST /api/orders/' . $order->id . '/fulfill with barcode scans'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Reduce inventory for each item
            foreach ($order->items as $item) {
                $batch = $item->batch;

                if (!$batch) {
                    throw new \Exception("Batch not found for item {$item->product_name}");
                }

                if ($batch->quantity < $item->quantity) {
                    throw new \Exception("Insufficient stock for {$item->product_name}. Available: {$batch->quantity}");
                }

                // Handle barcode-tracked items (check if barcode exists and is not null)
                if ($item->product_barcode_id && $item->barcode) {
                    $barcode = $item->barcode;
                    
                    // Validate barcode is still available (not already sold)
                    if ($barcode->current_status === 'sold' || $barcode->current_status === 'with_customer') {
                        throw new \Exception("Barcode {$barcode->barcode} for {$item->product_name} has already been sold.");
                    }

                    // Mark barcode as sold but keep it active for history/returns/refunds
                    // IMPORTANT: is_active stays TRUE to preserve history for returns/refunds/defects
                    $barcode->update([
                        'is_active' => true, // Keep active for history tracking
                        'current_status' => 'with_customer', // Tracks lifecycle state
                        'location_updated_at' => now(),
                        'location_metadata' => [
                            'sold_via' => 'order',
                            'order_number' => $order->order_number,
                            'order_id' => $order->id,
                            'sale_date' => now()->toISOString(),
                            'sold_by' => auth()->id(),
                        ]
                    ]);

                    // Log barcode sale
                    $note = sprintf(
                        "[%s] Sold 1 unit (Barcode: %s) via Order #%s",
                        now()->format('Y-m-d H:i:s'),
                        $barcode->barcode,
                        $order->order_number
                    );
                } else {
                    // Log non-barcode sale
                    $note = sprintf(
                        "[%s] Sold %d unit(s) (No barcode tracking) via Order #%s",
                        now()->format('Y-m-d H:i:s'),
                        $item->quantity,
                        $order->order_number
                    );
                }

                // Ensure COGS is stored/updated at the time of completion
                $calculatedCogs = ($batch ? ($batch->cost_price ?? 0) * $item->quantity : 0);
                
                // Log COGS calculation for debugging
                Log::info('COGS Calculation', [
                    'order_item_id' => $item->id,
                    'product_name' => $item->product_name,
                    'batch_id' => $batch ? $batch->id : null,
                    'batch_cost_price' => $batch ? $batch->cost_price : null,
                    'quantity' => $item->quantity,
                    'calculated_cogs' => round($calculatedCogs, 2),
                    'existing_cogs' => $item->cogs,
                ]);
                
                $item->update(['cogs' => round($calculatedCogs, 2)]);

                // Stock deduction is now centralizing here in OrderController@complete
                // We always deduct now because early deduction was removed from Create and Scan
                $alreadyDeducted = false; 
                
                if (!$alreadyDeducted) {
                    $batch->removeStock($item->quantity);

                    // RELEASE RESERVATION concurrently to keep available_stock (Total - Reserved) consistent
                    if ($reservedRecord = ReservedProduct::where('product_id', $item->product_id)->first()) {
                        $reservedRecord->decrement('reserved_inventory', $item->quantity);
                        $reservedRecord->refresh();
                        $reservedRecord->available_inventory = $reservedRecord->total_inventory - $reservedRecord->reserved_inventory;
                        $reservedRecord->save();

                        Log::info('Reservation released at order completion', [
                            'order_id' => $order->id,
                            'product_id' => $item->product_id,
                            'quantity' => $item->quantity,
                        ]);
                    }
                }
                
                $batch->update([
                    'notes' => ($batch->notes ? $batch->notes . "\n" : '') . $note
                ]);
            }

            // Update order status to confirmed (delivered will be set when shipment is delivered)
            $order->update([
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            // Update customer purchase stats
            $order->customer->recordPurchase($order->total_amount, $order->id);

            // Create COGS accounting transactions
            // This posts the Cost of Goods Sold to the accounting system:
            // - Debit: COGS (Expense) - increases expense
            // - Credit: Inventory (Asset) - decreases inventory value
            try {
                $orderWithItems = $order->fresh(['items']);
                Transaction::createFromOrderCOGS($orderWithItems);
                $totalCogs = collect($orderWithItems->items)->sum('cogs');
                Log::info('COGS Transactions Created', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_cogs' => $totalCogs,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to create COGS transactions', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Don't fail the order completion if COGS transaction fails
                // Just log the error for manual correction
            }

            DB::commit();

            $message = 'Order completed successfully. Inventory updated.';
            $items = collect($order->items);
            $trackedCount = $items->filter(fn($item) => $item->product_barcode_id && $item->barcode)->count();
            $untrackedCount = $items->count() - $trackedCount;
            
            if ($trackedCount > 0) {
                $message .= " {$trackedCount} item(s) tracked with barcodes.";
            }
            if ($untrackedCount > 0) {
                $message .= " {$untrackedCount} item(s) completed without barcode tracking.";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $this->formatOrderResponse($order->fresh([
                    'customer',
                    'store',
                    'items.product',
                    'items.batch',
                    'payments'
                ]), true)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Cancel order
     * 
     * PATCH /api/orders/{id}/cancel
     */
    public function cancel(Request $request, $id)
    {
        $order = Order::find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        if ($order->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel completed orders. Use returns instead.'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string'
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
            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'notes' => ($order->notes ? $order->notes . "\n" : '') . 'Cancelled: ' . ($request->reason ?? 'No reason provided'),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled successfully',
                'data' => $this->formatOrderResponse($order->fresh(), true)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Get order statistics
     * 
     * GET /api/orders/statistics
     */
    public function getStatistics(Request $request)
    {
        $query = Order::query();

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('order_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('order_date', '<=', $request->date_to);
        }

        // Filter by store
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // Filter by salesman
        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        $stats = [
            'total_orders' => $query->count(),
            'by_type' => [
                'counter' => (clone $query)->where('order_type', 'counter')->count(),
                'social_commerce' => (clone $query)->where('order_type', 'social_commerce')->count(),
                'ecommerce' => (clone $query)->where('order_type', 'ecommerce')->count(),
            ],
            'by_status' => [
                'pending' => (clone $query)->where('status', 'pending')->count(),
                'confirmed' => (clone $query)->where('status', 'confirmed')->count(),
                'completed' => (clone $query)->where('status', 'completed')->count(),
                'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
            ],
            'by_payment_status' => [
                'pending' => (clone $query)->where('payment_status', 'pending')->count(),
                'partially_paid' => (clone $query)->where('payment_status', 'partially_paid')->count(),
                'paid' => (clone $query)->where('payment_status', 'paid')->count(),
                'overdue' => (clone $query)->where('payment_status', 'overdue')->count(),
            ],
            'total_revenue' => (clone $query)->where('status', 'completed')->sum('total_amount'),
            'total_outstanding' => (clone $query)->whereIn('status', ['pending', 'confirmed', 'completed'])->sum('outstanding_amount'),
            'installment_orders' => (clone $query)->where('is_installment_payment', true)->count(),
        ];

        // Top salesmen
        if (!$request->filled('created_by')) {
            $stats['top_salesmen'] = Order::select('created_by')
                ->selectRaw('COUNT(*) as order_count')
                ->selectRaw('SUM(total_amount) as total_sales')
                ->with('createdBy:id,name')
                ->groupBy('created_by')
                ->orderByDesc('total_sales')
                ->limit(10)
                ->get()
                ->map(function ($item) {
                    return [
                        'employee_id' => $item->created_by,
                        'employee_name' => $item->createdBy->name ?? 'Unknown',
                        'order_count' => $item->order_count,
                        'total_sales' => number_format((float)$item->total_sales, 2),
                    ];
                });
        }

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Helper function to format order response
     */
    private function formatOrderResponse(Order $order, $detailed = false)
    {
        // Calculate COGS and gross margin for all responses
        $totalCogs = $order->items->sum(function ($i) {
            return $i->cogs ?? (($i->batch?->cost_price ?? 0) * $i->quantity);
        });
        $grossMargin = (float)$order->total_amount - $totalCogs;

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
            'subtotal' => number_format((float)$order->subtotal, 2),
            'tax_amount' => number_format((float)$order->tax_amount, 2),
            'discount_amount' => number_format((float)$order->discount_amount, 2),
            'shipping_amount' => number_format((float)$order->shipping_amount, 2),
            'total_amount' => number_format((float)$order->total_amount, 2),
            'paid_amount' => number_format((float)$order->paid_amount, 2),
            'outstanding_amount' => number_format((float)$order->outstanding_amount, 2),
            'total_cogs' => number_format($totalCogs, 2),
            'gross_margin' => number_format($grossMargin, 2),
            'gross_margin_percentage' => $order->total_amount > 0 ? number_format(($grossMargin / (float)$order->total_amount) * 100, 2) : '0.00',
            'is_installment' => $order->is_installment_payment,
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
                    'batch_number' => $item->batch?->batch_number,
                    'barcode_id' => $item->product_barcode_id,
                    'barcode' => $item->barcode?->barcode,
                    'quantity' => $item->quantity,
                    'global_available' => $item->product?->reservedProduct?->available_inventory ?? 0,
                    'unit_price' => number_format((float)$item->unit_price, 2),
                    'discount_amount' => number_format((float)$item->discount_amount, 2),
                    'tax_amount' => number_format((float)$item->tax_amount, 2),
                    'total_amount' => number_format((float)$item->total_amount, 2),
                    'cogs' => number_format((float)($item->cogs ?? (($item->batch?->cost_price ?? 0) * $item->quantity)), 2),
                    'item_gross_margin' => number_format((float)$item->total_amount - (float)($item->cogs ?? (($item->batch?->cost_price ?? 0) * $item->quantity)), 2),
                ];
            });

            $response['payments'] = $order->payments->map(function ($payment) {
                $paymentData = [
                    'id' => $payment->id,
                    'amount' => number_format((float)$payment->amount, 2),
                    'payment_method' => $payment->payment_method_name,
                    'payment_type' => $payment->payment_type,
                    'status' => $payment->status,
                    'processed_by' => $payment->processedBy?->name,
                    'created_at' => $payment->created_at->format('Y-m-d H:i:s'),
                ];

                // Include split details if it's a split payment
                if ($payment->isSplitPayment()) {
                    $paymentData['splits'] = $payment->paymentSplits->map(function ($split) {
                        return [
                            'payment_method' => $split->paymentMethod->name,
                            'amount' => number_format((float)$split->amount, 2),
                            'status' => $split->status,
                        ];
                    });
                }

                return $paymentData;
            });

            if ($order->is_installment_payment) {
                $response['installment_info'] = [
                    'total_installments' => $order->total_installments,
                    'paid_installments' => $order->paid_installments,
                    'installment_amount' => number_format((float)$order->installment_amount, 2),
                    'next_payment_due' => $order->next_payment_due ? date('Y-m-d', strtotime($order->next_payment_due)) : null,
                    'is_overdue' => $order->isPaymentOverdue(),
                    'days_overdue' => $order->getDaysOverdue(),
                ];
            }

            $response['notes'] = $order->notes;
            $response['shipping_address'] = $order->shipping_address;
            $response['confirmed_at'] = $order->confirmed_at?->format('Y-m-d H:i:s');
        }

        return $response;
    }

    /**
     * Fulfill order by scanning barcodes (for social commerce/ecommerce)
     * 
     * This is the NEW step requested by client:
     * - Social commerce employee creates order WITHOUT barcodes (works from home)
     * - At end of day, warehouse staff scans barcodes to fulfill the order
     * - This assigns specific physical units (barcodes) to order items
     * - After fulfillment, order can be shipped via Pathao
     * 
     * POST /api/orders/{id}/fulfill
     * Body: {
     *   "fulfillments": [
     *     {
     *       "order_item_id": 123,
     *       "barcodes": ["BARCODE-001", "BARCODE-002"]  // Scan actual units
     *     },
     *     {
     *       "order_item_id": 124,
     *       "barcodes": ["BARCODE-003"]
     *     }
     *   ]
     * }
     */
    public function fulfill(Request $request, $id)
    {
        $order = Order::with(['items.batch', 'items.product'])->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        // Only social commerce and ecommerce orders need fulfillment
        if (!$order->needsFulfillment()) {
            return response()->json([
                'success' => false,
                'message' => 'This order type does not require fulfillment. Counter orders are fulfilled immediately.'
            ], 422);
        }

        if (!$order->canBeFulfilled()) {
            return response()->json([
                'success' => false,
                'message' => "Order cannot be fulfilled. Current status: {$order->status}, Fulfillment status: {$order->fulfillment_status}"
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'fulfillments' => 'required|array|min:1',
            'fulfillments.*.order_item_id' => 'required|exists:order_items,id',
            'fulfillments.*.barcodes' => 'required|array|min:1',
            'fulfillments.*.barcodes.*' => 'required|string|exists:product_barcodes,barcode',
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
            $fulfilledItems = [];
            $employee = Employee::find(Auth::id());

            foreach ($request->fulfillments as $fulfillment) {
                $orderItem = OrderItem::where('order_id', $order->id)
                    ->find($fulfillment['order_item_id']);

                if (!$orderItem) {
                    throw new \Exception("Order item {$fulfillment['order_item_id']} not found in this order");
                }

                $barcodes = $fulfillment['barcodes'];
                
                // Validate quantity matches
                if (count($barcodes) !== $orderItem->quantity) {
                    throw new \Exception("Item '{$orderItem->product_name}' requires {$orderItem->quantity} barcode(s), but " . count($barcodes) . " provided");
                }

                // Validate all barcodes
                $barcodeModels = [];
                foreach ($barcodes as $barcodeValue) {
                    // Start query for barcode
                    $barcodeQuery = \App\Models\ProductBarcode::where('barcode', $barcodeValue)
                        ->where('product_id', $orderItem->product_id);

                    // Try to find the barcode
                    $barcode = (clone $barcodeQuery)->where('batch_id', $orderItem->product_batch_id)->first();
                    
                    // If not found in specified batch, try finding it in ANY batch for this product
                    if (!$barcode) {
                        $barcode = $barcodeQuery->first();
                    }

                    if (!$barcode) {
                        throw new \Exception("Barcode {$barcodeValue} not found for product {$orderItem->product_name}");
                    }

                    // Check if barcode is already sold
                    if (in_array($barcode->current_status, ['sold', 'with_customer'])) {
                        throw new \Exception("Barcode {$barcodeValue} has already been sold");
                    }

                    if ($barcode->is_defective) {
                        throw new \Exception("Barcode {$barcodeValue} is marked as defective");
                    }

                    // Verify barcode belongs to correct store
                    if ($barcode->batch && $barcode->batch->store_id != $order->store_id) {
                        throw new \Exception("Barcode {$barcodeValue} belongs to Store " . ($barcode->batch->store_id ?? 'Unknown') . ". This order must be fulfilled from Store " . $order->store_id);
                    }

                    $barcodeModels[] = $barcode;
                }

                // For single quantity items, assign the barcode and its batch directly
                if ($orderItem->quantity == 1) {
                    $orderItem->update([
                        'product_barcode_id' => $barcodeModels[0]->id,
                        'product_batch_id' => $barcodeModels[0]->batch_id // Sync batch ID with physical unit
                    ]);
                    
                    $fulfilledItems[] = [
                        'item_id' => $orderItem->id,
                        'product_name' => $orderItem->product_name,
                        'barcodes' => [$barcodeModels[0]->barcode]
                    ];
                } else {
                    // For multiple quantity items, we need to split into individual items
                    // This maintains proper barcode tracking
                    $originalQuantity = $orderItem->quantity;
                    $unitPrice = $orderItem->unit_price;
                    $discountPerUnit = $orderItem->discount_amount / $originalQuantity;
                    $taxPerUnit = $orderItem->tax_amount / $originalQuantity;
                    $cogsPerUnit = ($orderItem->cogs ?? 0) / $originalQuantity;

                    // Update first item with first barcode and its batch
                    $orderItem->update([
                        'quantity' => 1,
                        'product_barcode_id' => $barcodeModels[0]->id,
                        'product_batch_id' => $barcodeModels[0]->batch_id, // Sync batch ID
                        'discount_amount' => round($discountPerUnit, 2),
                        'tax_amount' => round($taxPerUnit, 2),
                        'cogs' => round($cogsPerUnit, 2),
                        'total_amount' => round($unitPrice - $discountPerUnit + $taxPerUnit, 2),
                    ]);

                    $fulfilledBarcodes = [$barcodeModels[0]->barcode];

                    // Create new items for remaining barcodes
                    for ($i = 1; $i < count($barcodeModels); $i++) {
                        OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $orderItem->product_id,
                            'product_batch_id' => $barcodeModels[$i]->batch_id, // Use actual batch ID of the barcode
                            'product_barcode_id' => $barcodeModels[$i]->id,
                            'product_name' => $orderItem->product_name,
                            'product_sku' => $orderItem->product_sku,
                            'quantity' => 1,
                            'unit_price' => $unitPrice,
                            'discount_amount' => round($discountPerUnit, 2),
                            'tax_amount' => round($taxPerUnit, 2),
                            'cogs' => round($cogsPerUnit, 2),
                            'total_amount' => round($unitPrice - $discountPerUnit + $taxPerUnit, 2),
                        ]);

                        $fulfilledBarcodes[] = $barcodeModels[$i]->barcode;
                    }

                    $fulfilledItems[] = [
                        'item_id' => $orderItem->id,
                        'product_name' => $orderItem->product_name,
                        'original_quantity' => $originalQuantity,
                        'barcodes' => $fulfilledBarcodes
                    ];
                }
            }

            // Mark order as fulfilled
            $order->fulfill($employee);

            // Recalculate totals (in case of splitting)
            $order->calculateTotals();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order fulfilled successfully. Ready for shipment.',
                'data' => [
                    'order_number' => $order->order_number,
                    'fulfillment_status' => $order->fulfillment_status,
                    'fulfilled_at' => $order->fulfilled_at->format('Y-m-d H:i:s'),
                    'fulfilled_by' => $order->fulfilledBy->name,
                    'fulfilled_items' => $fulfilledItems,
                    'next_step' => 'Create shipment for delivery',
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Fulfillment failed: ' . $e->getMessage()
            ], 422);
        }
    }

    /**
     * Calculate tax based on TAX_MODE configuration
     * 
     * @param float $unitPrice The price per unit
     * @param int $quantity Number of units
     * @param float $taxPercentage Tax percentage
     * @return array ['base_price' => float, 'tax_per_unit' => float, 'total_tax' => float]
     */
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
            // Inclusive: unitPrice includes tax, extract base and tax
            $basePrice = round($unitPrice / (1 + ($taxPercentage / 100)), 2);
            $taxPerUnit = round($unitPrice - $basePrice, 2);
        } else {
            // Exclusive: unitPrice is the base, tax is added on top
            $basePrice = $unitPrice;
            $taxPerUnit = round($unitPrice * ($taxPercentage / 100), 2);
        }

        return [
            'base_price' => $basePrice,
            'tax_per_unit' => $taxPerUnit,
            'total_tax' => $taxPerUnit * $quantity,
        ];
    }

    /**
     * Set intended courier for an order
     * 
     * PATCH /api/orders/{id}/set-courier
     * Body: { "intended_courier": "pathao" }
     */
    public function setIntendedCourier(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'intended_courier' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($id);

        $order->update([
            'intended_courier' => $request->intended_courier
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Intended courier set successfully',
            'data' => [
                'order_number' => $order->order_number,
                'intended_courier' => $order->intended_courier
            ]
        ]);
    }

    /**
     * Get orders by intended courier with search and sort
     * 
     * GET /api/orders/by-courier?courier=pathao&status=pending&sort_by=created_at&sort_order=desc
     */
    public function getOrdersByCourier(Request $request)
    {
        $query = Order::with([
            'customer',
            'store',
            'items.product'
        ]);

        // Filter by intended courier (required)
        if ($request->filled('courier')) {
            $query->where('intended_courier', $request->courier);
        } else {
            // If no courier specified, group by courier
            $couriers = Order::whereNotNull('intended_courier')
                ->select('intended_courier', DB::raw('COUNT(*) as order_count'))
                ->groupBy('intended_courier')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $couriers
            ]);
        }

        // Additional filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('date_from')) {
            $query->where('order_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('order_date', '<=', $request->date_to);
        }

        // Search
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $this->whereLike($q, 'order_number', $request->search);
                $q->orWhereHas('customer', function ($customerQuery) use ($request) {
                    $this->whereLike($customerQuery, 'name', $request->search);
                    $this->orWhereLike($customerQuery, 'phone', $request->search);
                });
            });
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $orders = $query->paginate($request->input('per_page', 20));

        $formattedOrders = [];
        foreach ($orders as $order) {
            $formattedOrders[] = $this->formatOrderResponse($order);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'current_page' => $orders->currentPage(),
                'data' => $formattedOrders,
                'first_page_url' => $orders->url(1),
                'from' => $orders->firstItem(),
                'last_page' => $orders->lastPage(),
                'last_page_url' => $orders->url($orders->lastPage()),
                'next_page_url' => $orders->nextPageUrl(),
                'path' => $orders->path(),
                'per_page' => $orders->perPage(),
                'prev_page_url' => $orders->previousPageUrl(),
                'to' => $orders->lastItem(),
                'total' => $orders->total(),
                'courier' => $request->courier
            ]
        ]);
    }

    /**
     * Lookup single order by intended courier
     * 
     * GET /api/orders/lookup-courier/{orderId}
     */
    public function lookupOrderCourier($orderId)
    {
        $order = Order::with(['customer', 'store'])
            ->findOrFail($orderId);

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'intended_courier' => $order->intended_courier,
                'status' => $order->status,
                'customer_name' => $order->customer->name,
                'store_name' => $order->store?->name,
                'total_amount' => $order->total_amount,
                'created_at' => $order->created_at->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    /**
     * Bulk lookup orders by IDs
     * 
     * POST /api/orders/bulk-lookup-courier
     * Body: { "order_ids": [1, 2, 3] }
     */
    public function bulkLookupCourier(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $orders = Order::with(['customer', 'store'])
            ->whereIn('id', $request->order_ids)
            ->get();

        $results = $orders->map(function ($order) {
            return [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'intended_courier' => $order->intended_courier,
                'status' => $order->status,
                'customer_name' => $order->customer->name,
                'store_name' => $order->store?->name,
                'total_amount' => $order->total_amount,
                'created_at' => $order->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'total_orders' => $results->count(),
                'orders' => $results
            ]
        ]);
    }

    /**
     * Get available couriers (distinct values)
     * 
     * GET /api/orders/available-couriers
     */
    public function getAvailableCouriers()
    {
        $couriers = Order::whereNotNull('intended_courier')
            ->select('intended_courier', DB::raw('COUNT(*) as order_count'))
            ->groupBy('intended_courier')
            ->orderBy('order_count', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $couriers
        ]);
    }

    /**
     * Bulk export selected orders to CSV
     * 
     * POST /api/orders/bulk-export
     */
    public function bulkExport(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $orders = Order::with(['customer', 'store', 'items'])
            ->whereIn('id', $request->order_ids)
            ->get();

        $filename = 'orders_export_' . date('Y-m-d_His') . '.csv';
        
        $callback = function() use ($orders) {
            $file = fopen('php://output', 'w');
            
            // CSV Header from order_sample.csv
            fputcsv($file, [
                'ItemType', 
                'StoreName', 
                'MerchantOrderId', 
                'RecipientName(*)', 
                'RecipientPhone(*)', 
                'RecipientAddress(*)', 
                'RecipientCity(*)', 
                'RecipientZone(*)', 
                'RecipientArea', 
                'AmountToCollect(*)', 
                'ItemQuantity', 
                'ItemWeight', 
                'ItemDesc', 
                'SpecialInstruction'
            ]);

            foreach ($orders as $order) {
                $shipping = is_array($order->shipping_address) ? $order->shipping_address : json_decode($order->shipping_address, true);
                if (!$shipping) $shipping = [];

                $customer = $order->customer;
                
                // Robust address extraction
                $recipientAddress = '';
                if (!empty($shipping['address_line_1'])) {
                    $recipientAddress = $shipping['address_line_1'];
                } elseif (!empty($shipping['street'])) {
                    $recipientAddress = $shipping['street'];
                } elseif (!empty($shipping['address'])) {
                    $recipientAddress = $shipping['address'];
                } elseif ($customer) {
                    $recipientAddress = $customer->address ?? '';
                }

                // Item descriptions: join product names
                $itemDesc = $order->items->pluck('product_name')->implode(', ');
                $totalItems = $order->items->sum('quantity');

                fputcsv($file, [
                    'parcel', // ItemType
                    $order->store->name ?? '', // StoreName
                    $order->order_number, // MerchantOrderId
                    $order->customer_name ?? ($customer ? $customer->name : ''), // RecipientName
                    $order->customer_phone ?? ($customer ? $customer->phone : ''), // RecipientPhone
                    $recipientAddress, // RecipientAddress
                    $shipping['city'] ?? '', // RecipientCity
                    $shipping['zone'] ?? '', // RecipientZone
                    $shipping['area'] ?? '', // RecipientArea
                    round($order->outstanding_amount, 2), // AmountToCollect
                    $totalItems, // ItemQuantity
                    '0.5 Kg', // ItemWeight
                    $itemDesc, // ItemDesc
                    $order->notes ?? '' // SpecialInstruction
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ]);
    }
}


