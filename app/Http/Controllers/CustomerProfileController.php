<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class CustomerProfileController extends Controller
{
    /**
     * Get customer profile
     */
    public function getProfile()
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            return response()->json([
                'success' => true,
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                        'customer_code' => $customer->customer_code,
                        'customer_type' => $customer->customer_type,
                        'status' => $customer->status,
                        'email_verified' => $customer->hasVerifiedEmail(),
                        'date_of_birth' => $customer->date_of_birth,
                        'gender' => $customer->gender,
                        'address' => $customer->address,
                        'city' => $customer->city,
                        'state' => $customer->state,
                        'postal_code' => $customer->postal_code,
                        'country' => $customer->country,
                        'total_orders' => $customer->total_orders,
                        'total_purchases' => $customer->total_purchases,
                        'first_purchase_at' => $customer->first_purchase_at,
                        'last_purchase_at' => $customer->last_purchase_at,
                        'preferences' => $customer->preferences,
                        'social_profiles' => $customer->social_profiles,
                        'created_at' => $customer->created_at,
                        'updated_at' => $customer->updated_at,
                        // Computed fields
                        'age' => $customer->age,
                        'formatted_phone' => $customer->formatted_phone,
                        'lifetime_value' => $customer->lifetime_value,
                        'average_order_value' => $customer->average_order_value,
                        'days_since_last_purchase' => $customer->days_since_last_purchase,
                        'is_loyal_customer' => $customer->isLoyalCustomer(),
                        'is_recent_customer' => $customer->isRecentCustomer(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update customer profile
     */
    public function updateProfile(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|min:2|max:255',
                'phone' => 'sometimes|string|max:20|unique:customers,phone,' . $customer->id,
                'date_of_birth' => 'sometimes|nullable|date|before:today',
                'gender' => 'sometimes|nullable|in:male,female,other',
                'address' => 'sometimes|nullable|string|max:500',
                'city' => 'sometimes|nullable|string|max:100',
                'state' => 'sometimes|nullable|string|max:100',
                'postal_code' => 'sometimes|nullable|string|max:20',
                'country' => 'sometimes|nullable|string|max:100',
                'preferences' => 'sometimes|nullable|array',
                'social_profiles' => 'sometimes|nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customer->update($request->only([
                'name', 'phone', 'date_of_birth', 'gender', 'address', 
                'city', 'state', 'postal_code', 'country', 'preferences', 'social_profiles'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'phone' => $customer->phone,
                        'date_of_birth' => $customer->date_of_birth,
                        'gender' => $customer->gender,
                        'address' => $customer->address,
                        'city' => $customer->city,
                        'state' => $customer->state,
                        'postal_code' => $customer->postal_code,
                        'country' => $customer->country,
                        'updated_at' => $customer->updated_at,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer's order history
     */
    public function getOrderHistory(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $perPage = $request->get('per_page', 10);
            $status = $request->get('status');
            $startDate = $request->get('start_date');
            $endDate = $request->get('end_date');

            $orders = Order::with(['orderItems.product', 'payments', 'shipment'])
                ->where('customer_id', $customer->id)
                ->when($status, function ($query) use ($status) {
                    return $query->where('status', $status);
                })
                ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                    return $query->whereBetween('created_at', [$startDate, $endDate]);
                })
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'orders' => $orders->map(function ($order) {
                        return [
                            'id' => $order->id,
                            'order_number' => $order->order_number,
                            'order_date' => $order->created_at,
                            'status' => $order->status,
                            'total_amount' => $order->total_amount,
                            'items_count' => $order->orderItems->count(),
                            'items' => $order->orderItems->map(function ($item) {
                                return [
                                    'product_name' => $item->product->name ?? 'Product not found',
                                    'quantity' => $item->quantity,
                                    'unit_price' => $item->unit_price,
                                    'total_price' => $item->total_price,
                                ];
                            }),
                            'payment_status' => $order->payments->first()->status ?? 'pending',
                            'shipping_address' => $order->shipping_address,
                            'tracking_number' => $order->shipment->tracking_number ?? null,
                            'can_cancel' => $order->status === 'pending',
                            'can_return' => in_array($order->status, ['delivered', 'completed']),
                        ];
                    }),
                    'pagination' => [
                        'current_page' => $orders->currentPage(),
                        'last_page' => $orders->lastPage(),
                        'per_page' => $orders->perPage(),
                        'total' => $orders->total(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get order history: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update communication preferences
     */
    public function updateCommunicationPreferences(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'email_notifications' => 'boolean',
                'sms_notifications' => 'boolean',
                'promotional_emails' => 'boolean',
                'order_updates' => 'boolean',
                'newsletter' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $preferences = $customer->preferences ?? [];
            $preferences['communication'] = array_merge(
                $preferences['communication'] ?? [],
                $request->only([
                    'email_notifications', 'sms_notifications', 'promotional_emails',
                    'order_updates', 'newsletter'
                ])
            );

            $customer->preferences = $preferences;
            $customer->save();

            return response()->json([
                'success' => true,
                'message' => 'Communication preferences updated successfully',
                'data' => [
                    'communication_preferences' => $preferences['communication'],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update preferences: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update shopping preferences
     */
    public function updateShoppingPreferences(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'preferred_categories' => 'array',
                'preferred_brands' => 'array',
                'size_preferences' => 'array',
                'budget_range' => 'array',
                'delivery_preferences' => 'array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $preferences = $customer->preferences ?? [];
            $preferences['shopping'] = array_merge(
                $preferences['shopping'] ?? [],
                $request->only([
                    'preferred_categories', 'preferred_brands', 'size_preferences',
                    'budget_range', 'delivery_preferences'
                ])
            );

            $customer->preferences = $preferences;
            $customer->save();

            return response()->json([
                'success' => true,
                'message' => 'Shopping preferences updated successfully',
                'data' => [
                    'shopping_preferences' => $preferences['shopping'],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update shopping preferences: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer statistics and insights
     */
    public function getCustomerStats()
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $orders = Order::where('customer_id', $customer->id)->get();
            
            $stats = [
                'total_orders' => $orders->count(),
                'completed_orders' => $orders->where('status', 'completed')->count(),
                'cancelled_orders' => $orders->where('status', 'cancelled')->count(),
                'pending_orders' => $orders->where('status', 'pending')->count(),
                'total_spent' => $customer->total_purchases,
                'average_order_value' => $customer->average_order_value,
                'lifetime_value' => $customer->lifetime_value,
                'days_since_registration' => $customer->created_at->diffInDays(now()),
                'days_since_last_purchase' => $customer->days_since_last_purchase,
                'customer_tier' => $this->getCustomerTier($customer),
                'loyalty_status' => [
                    'is_loyal' => $customer->isLoyalCustomer(),
                    'is_recent' => $customer->isRecentCustomer(),
                    'is_at_risk' => $customer->isAtRiskCustomer(),
                ],
                'recent_activity' => [
                    'last_login' => $customer->updated_at, // Approximate
                    'last_order' => $orders->sortByDesc('created_at')->first()->created_at ?? null,
                    'favorite_categories' => $this->getFavoriteCategories($customer->id),
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get customer stats: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deactivate customer account
     */
    public function deactivateAccount(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:500',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            if (!$customer->verifyPassword($request->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid password',
                ], 400);
            }

            $customer->deactivate();

            // Log deactivation reason
            $preferences = $customer->preferences ?? [];
            $preferences['deactivation'] = [
                'reason' => $request->reason,
                'deactivated_at' => now(),
                'can_reactivate' => true,
            ];
            $customer->preferences = $preferences;
            $customer->save();

            // Invalidate JWT token
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Account deactivated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate account: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer tier based on spending
     */
    private function getCustomerTier(Customer $customer)
    {
        $totalSpent = $customer->total_purchases;

        if ($totalSpent >= 10000) {
            return 'platinum';
        } elseif ($totalSpent >= 5000) {
            return 'gold';
        } elseif ($totalSpent >= 1000) {
            return 'silver';
        } else {
            return 'bronze';
        }
    }

    /**
     * Get customer's favorite categories
     */
    private function getFavoriteCategories($customerId)
    {
        // This would require a more complex query joining orders, order_items, products, and categories
        // For now, return an empty array
        return [];
    }
}