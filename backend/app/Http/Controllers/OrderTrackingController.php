<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class OrderTrackingController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:customer');
    }

    /**
     * Get real-time order tracking information
     */
    public function trackOrder($orderNumber): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            
            $order = Order::where('customer_id', $customerId)
                ->where('order_number', $orderNumber)
                ->with(['orderItems.product'])
                ->firstOrFail();

            $trackingInfo = $this->generateTrackingDetails($order);

            return response()->json([
                'success' => true,
                'data' => [
                    'order' => $order,
                    'tracking' => $trackingInfo,
                    'real_time_updates' => $this->getRealTimeUpdates($order),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Get all orders with tracking status
     */
    public function getAllOrdersTracking(Request $request): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $status = $request->query('status');
            $perPage = $request->query('per_page', 10);

            $query = Order::where('customer_id', $customerId)
                ->with(['orderItems.product'])
                ->orderBy('created_at', 'desc');

            if ($status) {
                $query->where('status', $status);
            }

            $orders = $query->paginate($perPage);

            // Add tracking info to each order
            $orders->transform(function($order) {
                $order->tracking_summary = $this->getTrackingSummary($order);
                return $order;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $orders->items(),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'total_pages' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order tracking information',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update notification preferences
     */
    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email_notifications' => 'boolean',
                'sms_notifications' => 'boolean',
                'order_updates' => 'boolean',
                'delivery_updates' => 'boolean',
                'promotional_notifications' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();
            $customer = Customer::findOrFail($customerId);

            // Update notification preferences
            $preferences = $customer->notification_preferences ?? [];
            $preferences = array_merge($preferences, $validator->validated());

            $customer->update(['notification_preferences' => $preferences]);

            return response()->json([
                'success' => true,
                'message' => 'Notification preferences updated successfully',
                'data' => ['preferences' => $preferences],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update preferences',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get notification history
     */
    public function getNotificationHistory(Request $request): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $type = $request->query('type'); // email, sms, push
            $perPage = $request->query('per_page', 20);

            // In a real app, you'd have a notifications table
            // For demo purposes, we'll simulate notification history
            $notifications = $this->simulateNotificationHistory($customerId, $type);

            $paginated = array_slice($notifications, 0, $perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'notifications' => $paginated,
                    'unread_count' => count(array_filter($notifications, fn($n) => !$n['read'])),
                    'total' => count($notifications),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notification history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark notifications as read
     */
    public function markNotificationsAsRead(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'notification_ids' => 'array',
                'notification_ids.*' => 'string',
                'mark_all' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();

            // In a real app, you'd update the notifications table
            // For demo purposes, we'll just return success

            return response()->json([
                'success' => true,
                'message' => 'Notifications marked as read',
                'data' => [
                    'marked_count' => $request->mark_all ? 'all' : count($request->notification_ids ?? []),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notifications as read',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Subscribe to order updates via webhook/SSE
     */
    public function subscribeToUpdates(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'order_number' => 'required|string',
                'webhook_url' => 'nullable|url',
                'notification_types' => 'array',
                'notification_types.*' => 'in:status_change,location_update,delivery_attempt',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();
            
            $order = Order::where('customer_id', $customerId)
                ->where('order_number', $request->order_number)
                ->firstOrFail();

            // In real app, you'd set up webhook/SSE subscription
            $subscriptionId = 'sub_' . uniqid();

            return response()->json([
                'success' => true,
                'message' => 'Subscribed to order updates',
                'data' => [
                    'subscription_id' => $subscriptionId,
                    'order_number' => $order->order_number,
                    'webhook_url' => $request->webhook_url,
                    'notification_types' => $request->notification_types ?? ['status_change'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to subscribe to updates',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get delivery location updates
     */
    public function getDeliveryLocation($orderNumber): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            
            $order = Order::where('customer_id', $customerId)
                ->where('order_number', $orderNumber)
                ->firstOrFail();

            if (!in_array($order->status, ['shipped', 'out_for_delivery'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location tracking not available for this order status',
                ], 400);
            }

            $locationData = $this->getDeliveryLocationData($order);

            return response()->json([
                'success' => true,
                'data' => [
                    'order' => $order,
                    'location' => $locationData,
                    'estimated_arrival' => $this->getEstimatedArrival($order),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get delivery location',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Private helper methods

    private function generateTrackingDetails(Order $order): array
    {
        $timeline = [
            [
                'status' => 'pending',
                'title' => 'Order Placed',
                'description' => 'Your order has been placed successfully',
                'completed' => true,
                'timestamp' => $order->created_at,
                'icon' => 'check-circle',
            ],
            [
                'status' => 'processing',
                'title' => 'Order Processing',
                'description' => 'We are preparing your order',
                'completed' => in_array($order->status, ['processing', 'shipped', 'delivered', 'completed']),
                'timestamp' => $order->status === 'processing' ? $order->updated_at : null,
                'icon' => 'cog',
            ],
            [
                'status' => 'shipped',
                'title' => 'Order Shipped',
                'description' => 'Your order is on its way',
                'completed' => in_array($order->status, ['shipped', 'delivered', 'completed']),
                'timestamp' => $order->status === 'shipped' ? $order->updated_at : null,
                'icon' => 'truck',
                'tracking_number' => $order->tracking_number,
            ],
            [
                'status' => 'delivered',
                'title' => 'Order Delivered',
                'description' => 'Your order has been delivered successfully',
                'completed' => in_array($order->status, ['delivered', 'completed']),
                'timestamp' => $order->status === 'delivered' ? $order->updated_at : null,
                'icon' => 'home',
            ],
        ];

        if ($order->status === 'cancelled') {
            $timeline[] = [
                'status' => 'cancelled',
                'title' => 'Order Cancelled',
                'description' => 'Your order has been cancelled',
                'completed' => true,
                'timestamp' => $order->cancelled_at ?? $order->updated_at,
                'icon' => 'x-circle',
            ];
        }

        return [
            'current_status' => $order->status,
            'timeline' => $timeline,
            'estimated_delivery' => $this->getEstimatedDelivery($order),
            'delivery_instructions' => $this->getDeliveryInstructions($order),
        ];
    }

    private function getTrackingSummary(Order $order): array
    {
        $statusLabels = [
            'pending' => 'Order Placed',
            'processing' => 'Being Prepared',
            'shipped' => 'On the Way',
            'delivered' => 'Delivered',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ];

        return [
            'status' => $order->status,
            'status_label' => $statusLabels[$order->status] ?? 'Unknown',
            'progress_percentage' => $this->getProgressPercentage($order->status),
            'estimated_delivery' => $this->getEstimatedDelivery($order),
            'can_track_location' => in_array($order->status, ['shipped', 'out_for_delivery']),
        ];
    }

    private function getProgressPercentage(string $status): int
    {
        $statusProgress = [
            'pending' => 25,
            'processing' => 50,
            'shipped' => 75,
            'delivered' => 100,
            'completed' => 100,
            'cancelled' => 0,
        ];

        return $statusProgress[$status] ?? 0;
    }

    private function getRealTimeUpdates(Order $order): array
    {
        // Simulate real-time updates
        return [
            [
                'timestamp' => now()->subMinutes(30),
                'message' => 'Order is being packed',
                'location' => 'Warehouse - Dhaka',
            ],
            [
                'timestamp' => now()->subMinutes(15),
                'message' => 'Order has left the warehouse',
                'location' => 'In transit',
            ],
            [
                'timestamp' => now()->subMinutes(5),
                'message' => 'Out for delivery',
                'location' => 'Local delivery hub - ' . ($this->getDeliveryArea($order) ?? 'Your area'),
            ],
        ];
    }

    private function getEstimatedDelivery(Order $order): ?string
    {
        if ($order->scheduled_delivery_date) {
            return $order->scheduled_delivery_date;
        }

        $shippingAddress = json_decode($order->shipping_address, true);
        $city = strtolower($shippingAddress['city'] ?? '');
        
        $baseDays = str_contains($city, 'dhaka') ? 2 : 4;
        
        if ($order->delivery_preference === 'express') {
            $baseDays = max(1, $baseDays - 1);
        }

        return now()->addDays($baseDays)->format('M d, Y');
    }

    private function getDeliveryInstructions(Order $order): ?array
    {
        $shippingAddress = json_decode($order->shipping_address, true);
        
        return [
            'address' => $shippingAddress,
            'phone' => $shippingAddress['phone'] ?? null,
            'delivery_notes' => $shippingAddress['delivery_instructions'] ?? null,
            'contact_person' => $shippingAddress['name'] ?? null,
        ];
    }

    private function getDeliveryLocationData(Order $order): array
    {
        // Simulate GPS tracking data
        return [
            'current_location' => [
                'lat' => 23.8103 + (rand(-1000, 1000) / 10000),
                'lng' => 90.4125 + (rand(-1000, 1000) / 10000),
                'address' => 'Delivery vehicle location',
            ],
            'delivery_address' => [
                'lat' => 23.8103,
                'lng' => 90.4125,
                'address' => json_decode($order->shipping_address, true)['city'] ?? 'Delivery Address',
            ],
            'last_updated' => now()->format('Y-m-d H:i:s'),
            'delivery_person' => [
                'name' => 'Karim Ahmed',
                'phone' => '+8801712345678',
                'vehicle_type' => 'Motorcycle',
            ],
        ];
    }

    private function getEstimatedArrival(Order $order): string
    {
        return now()->addMinutes(rand(15, 60))->format('h:i A');
    }

    private function getDeliveryArea(Order $order): ?string
    {
        $shippingAddress = json_decode($order->shipping_address, true);
        return $shippingAddress['city'] ?? null;
    }

    private function simulateNotificationHistory(int $customerId, ?string $type): array
    {
        return [
            [
                'id' => 'notif_1',
                'type' => 'email',
                'title' => 'Order Confirmed',
                'message' => 'Your order #ORD-241118-1234 has been confirmed',
                'read' => true,
                'created_at' => now()->subHours(2),
            ],
            [
                'id' => 'notif_2',
                'type' => 'sms',
                'title' => 'Order Shipped',
                'message' => 'Your order is on its way! Track: TRK123456',
                'read' => false,
                'created_at' => now()->subHour(),
            ],
            [
                'id' => 'notif_3',
                'type' => 'push',
                'title' => 'Out for Delivery',
                'message' => 'Your order will be delivered within 2 hours',
                'read' => false,
                'created_at' => now()->subMinutes(30),
            ],
        ];
    }
}