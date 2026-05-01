<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\ServiceOrderPayment;
use App\Models\Service;
use App\Models\Customer;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ServiceOrderController extends Controller
{
    use DatabaseAgnosticSearch;

    /**
     * List all service orders with filters
     * GET /api/service-orders
     */
    public function index(Request $request)
    {
        $query = ServiceOrder::with([
            'customer',
            'store',
            'createdBy',
            'assignedTo',
            'items.service',
            'payments'
        ]);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by payment status
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by store
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // Filter by customer
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by assigned employee
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        // Search by order number or customer name/phone
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $this->whereAnyLike($q, [
                    'service_order_number',
                    'customer_name',
                    'customer_phone',
                    'customer_email'
                ], $search);
            });
        }

        // Date range filters
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Scheduled date filter
        if ($request->filled('scheduled_date')) {
            $query->whereDate('scheduled_date', $request->scheduled_date);
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->input('per_page', 20);
        $orders = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }

    /**
     * Get single service order
     * GET /api/service-orders/{id}
     */
    public function show($id)
    {
        $order = ServiceOrder::with([
            'customer',
            'store',
            'createdBy',
            'assignedTo',
            'items.service',
            'payments'
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * Create new service order
     * POST /api/service-orders
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'nullable|exists:customers,id',
            'store_id' => 'required|exists:stores,id',
            'customer_name' => 'required|string|max:255',
            'customer_phone' => 'required|string|max:20',
            'customer_email' => 'nullable|email',
            'customer_address' => 'nullable|string',
            'scheduled_date' => 'nullable|date',
            'scheduled_time' => 'nullable|date_format:H:i',
            'special_instructions' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.service_id' => 'required|exists:services,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.selected_options' => 'nullable|array',
            'items.*.customizations' => 'nullable|array',
            'items.*.special_instructions' => 'nullable|string',
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
            // Calculate totals
            $subtotal = 0;
            $itemsData = [];

            foreach ($request->items as $itemData) {
                $service = Service::findOrFail($itemData['service_id']);
                
                // Calculate price
                $quantity = $itemData['quantity'];
                $unitPrice = $itemData['unit_price'] ?? $service->calculatePrice($quantity, $itemData['selected_options'] ?? []);
                $totalPrice = $unitPrice * $quantity;

                $itemsData[] = [
                    'service_id' => $service->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'selected_options' => $itemData['selected_options'] ?? null,
                    'customizations' => $itemData['customizations'] ?? null,
                    'special_instructions' => $itemData['special_instructions'] ?? null,
                ];

                $subtotal += $totalPrice;
            }

            // Create service order
            $order = ServiceOrder::create([
                'customer_id' => $request->customer_id,
                'store_id' => $request->store_id,
                'created_by' => Auth::id(),
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'customer_name' => $request->customer_name,
                'customer_phone' => $request->customer_phone,
                'customer_email' => $request->customer_email,
                'customer_address' => $request->customer_address,
                'scheduled_date' => $request->scheduled_date,
                'scheduled_time' => $request->scheduled_time,
                'special_instructions' => $request->special_instructions,
                'subtotal' => $subtotal,
                'tax_amount' => 0, // Can be calculated based on business rules
                'discount_amount' => 0,
                'total_amount' => $subtotal,
                'paid_amount' => 0,
                'outstanding_amount' => $subtotal,
            ]);

            // Create order items
            foreach ($itemsData as $itemData) {
                $order->items()->create($itemData);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Service order created successfully',
                'data' => $order->load(['items.service', 'customer', 'store'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create service order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update service order
     * PUT /api/service-orders/{id}
     */
    public function update(Request $request, $id)
    {
        $order = ServiceOrder::findOrFail($id);

        // Only allow updates for pending/confirmed orders
        if (in_array($order->status, ['completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update completed or cancelled orders'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'customer_name' => 'sometimes|string|max:255',
            'customer_phone' => 'sometimes|string|max:20',
            'customer_email' => 'nullable|email',
            'customer_address' => 'nullable|string',
            'scheduled_date' => 'nullable|date',
            'scheduled_time' => 'nullable|date_format:H:i',
            'assigned_to' => 'nullable|exists:employees,id',
            'special_instructions' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $order->update($request->only([
            'customer_name',
            'customer_phone',
            'customer_email',
            'customer_address',
            'scheduled_date',
            'scheduled_time',
            'assigned_to',
            'special_instructions',
            'notes'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Service order updated successfully',
            'data' => $order->load(['items.service', 'customer', 'store', 'assignedTo'])
        ]);
    }

    /**
     * Confirm service order
     * PATCH /api/service-orders/{id}/confirm
     */
    public function confirm($id)
    {
        $order = ServiceOrder::findOrFail($id);

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending orders can be confirmed'
            ], 422);
        }

        $order->update([
            'status' => 'confirmed',
            'confirmed_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service order confirmed successfully',
            'data' => $order
        ]);
    }

    /**
     * Start service order
     * PATCH /api/service-orders/{id}/start
     */
    public function start($id)
    {
        $order = ServiceOrder::findOrFail($id);

        if (!in_array($order->status, ['pending', 'confirmed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Order must be pending or confirmed to start'
            ], 422);
        }

        $order->update([
            'status' => 'in_progress',
            'started_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service order started successfully',
            'data' => $order
        ]);
    }

    /**
     * Complete service order
     * PATCH /api/service-orders/{id}/complete
     */
    public function complete($id)
    {
        $order = ServiceOrder::findOrFail($id);

        if ($order->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Only in-progress orders can be completed'
            ], 422);
        }

        $order->update([
            'status' => 'completed',
            'completed_at' => now(),
            'actual_completion' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service order completed successfully',
            'data' => $order
        ]);
    }

    /**
     * Cancel service order
     * PATCH /api/service-orders/{id}/cancel
     */
    public function cancel(Request $request, $id)
    {
        $order = ServiceOrder::findOrFail($id);

        if (in_array($order->status, ['completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot cancel completed or already cancelled orders'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $order->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'notes' => $request->cancellation_reason 
                ? ($order->notes ? $order->notes . "\n\nCancellation: " : 'Cancellation: ') . $request->cancellation_reason
                : $order->notes
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service order cancelled successfully',
            'data' => $order
        ]);
    }

    /**
     * Add payment to service order
     * POST /api/service-orders/{id}/payments
     */
    public function addPayment(Request $request, $id)
    {
        $order = ServiceOrder::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|exists:payment_methods,id',
            'payment_date' => 'nullable|date',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate payment amount
        if ($request->amount > $order->outstanding_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Payment amount exceeds outstanding amount'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Create payment
            $payment = $order->payments()->create([
                'amount' => $request->amount,
                'payment_method_id' => $request->payment_method_id,
                'payment_date' => $request->payment_date ?? now(),
                'reference_number' => $request->reference_number,
                'notes' => $request->notes,
                'received_by' => Auth::id(),
                'status' => 'completed',
            ]);

            // Update order payment status
            $order->paid_amount += $request->amount;
            $order->outstanding_amount = $order->total_amount - $order->paid_amount;

            if ($order->outstanding_amount <= 0) {
                $order->payment_status = 'paid';
            } elseif ($order->paid_amount > 0) {
                $order->payment_status = 'partially_paid';
            }

            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment added successfully',
                'data' => [
                    'payment' => $payment,
                    'order' => $order->fresh(['payments'])
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to add payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get service order statistics
     * GET /api/service-orders/statistics
     */
    public function getStatistics(Request $request)
    {
        $query = ServiceOrder::query();

        // Apply store filter if provided
        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        // Apply date range if provided
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $stats = [
            'total_orders' => (clone $query)->count(),
            'pending_orders' => (clone $query)->where('status', 'pending')->count(),
            'confirmed_orders' => (clone $query)->where('status', 'confirmed')->count(),
            'in_progress_orders' => (clone $query)->where('status', 'in_progress')->count(),
            'completed_orders' => (clone $query)->where('status', 'completed')->count(),
            'cancelled_orders' => (clone $query)->where('status', 'cancelled')->count(),
            
            'total_revenue' => (clone $query)->where('status', 'completed')->sum('total_amount'),
            'total_paid' => (clone $query)->sum('paid_amount'),
            'total_outstanding' => (clone $query)->whereIn('status', ['pending', 'confirmed', 'in_progress'])->sum('outstanding_amount'),
            
            'unpaid_orders' => (clone $query)->where('payment_status', 'unpaid')->count(),
            'partially_paid_orders' => (clone $query)->where('payment_status', 'partially_paid')->count(),
            'fully_paid_orders' => (clone $query)->where('payment_status', 'paid')->count(),
            
            'scheduled_today' => (clone $query)->whereDate('scheduled_date', today())->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get orders by customer
     * GET /api/customers/{customerId}/service-orders
     */
    public function getByCustomer($customerId)
    {
        $orders = ServiceOrder::with(['items.service', 'payments'])
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $orders
        ]);
    }
}
