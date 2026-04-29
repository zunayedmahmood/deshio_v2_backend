<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class LoyaltyProgramController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:customer');
    }

    /**
     * Get customer loyalty program details
     */
    public function getLoyaltyDetails(): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $customer = Customer::findOrFail($customerId);

            $loyaltyData = $this->calculateLoyaltyPoints($customer);
            $tier = $this->calculateCustomerTier($customer);
            $benefits = $this->getTierBenefits($tier);

            return response()->json([
                'success' => true,
                'data' => [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'email' => $customer->email,
                        'member_since' => $customer->created_at->format('M Y'),
                    ],
                    'loyalty' => [
                        'current_points' => $loyaltyData['current_points'],
                        'total_earned' => $loyaltyData['total_earned'],
                        'total_redeemed' => $loyaltyData['total_redeemed'],
                        'pending_points' => $loyaltyData['pending_points'],
                        'points_expiring_soon' => $loyaltyData['expiring_soon'],
                    ],
                    'tier' => [
                        'current' => $tier['current'],
                        'next' => $tier['next'],
                        'progress_percentage' => $tier['progress'],
                        'points_to_next_tier' => $tier['points_needed'],
                    ],
                    'benefits' => $benefits,
                    'store_credits' => $this->getStoreCreditBalance($customer),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch loyalty details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get points history
     */
    public function getPointsHistory(Request $request): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $type = $request->query('type'); // earned, redeemed, expired
            $perPage = $request->query('per_page', 15);

            $history = $this->simulatePointsHistory($customerId, $type);
            $paginated = array_slice($history, 0, $perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'history' => $paginated,
                    'pagination' => [
                        'current_page' => 1,
                        'total_pages' => ceil(count($history) / $perPage),
                        'per_page' => $perPage,
                        'total' => count($history),
                    ],
                    'summary' => [
                        'total_transactions' => count($history),
                        'points_earned' => array_sum(array_column(
                            array_filter($history, fn($h) => $h['type'] === 'earned'), 
                            'points'
                        )),
                        'points_redeemed' => array_sum(array_column(
                            array_filter($history, fn($h) => $h['type'] === 'redeemed'), 
                            'points'
                        )),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch points history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available rewards
     */
    public function getAvailableRewards(Request $request): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $customer = Customer::findOrFail($customerId);
            
            $category = $request->query('category');
            $minPoints = $request->query('min_points');
            $maxPoints = $request->query('max_points');

            $rewards = $this->getRewardsData($category, $minPoints, $maxPoints);
            $currentPoints = $this->calculateLoyaltyPoints($customer)['current_points'];

            // Mark rewards as affordable
            foreach ($rewards as &$reward) {
                $reward['can_redeem'] = $currentPoints >= $reward['points_required'];
                $reward['points_needed'] = max(0, $reward['points_required'] - $currentPoints);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'rewards' => $rewards,
                    'current_points' => $currentPoints,
                    'categories' => $this->getRewardCategories(),
                    'affordable_count' => count(array_filter($rewards, fn($r) => $r['can_redeem'])),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available rewards',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Redeem points for reward
     */
    public function redeemReward(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reward_id' => 'required|string',
                'quantity' => 'nullable|integer|min:1|max:5',
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
            
            $reward = $this->getRewardById($request->reward_id);
            if (!$reward) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reward not found',
                ], 404);
            }

            $quantity = $request->quantity ?? 1;
            $totalPointsRequired = $reward['points_required'] * $quantity;
            $currentPoints = $this->calculateLoyaltyPoints($customer)['current_points'];

            if ($currentPoints < $totalPointsRequired) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient points',
                    'data' => [
                        'required_points' => $totalPointsRequired,
                        'current_points' => $currentPoints,
                        'shortage' => $totalPointsRequired - $currentPoints,
                    ],
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Deduct points (in real app, update database)
                $redemptionId = 'RED-' . date('ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
                
                $redemption = [
                    'redemption_id' => $redemptionId,
                    'customer_id' => $customerId,
                    'reward_id' => $request->reward_id,
                    'reward_name' => $reward['name'],
                    'points_used' => $totalPointsRequired,
                    'quantity' => $quantity,
                    'status' => 'confirmed',
                    'expires_at' => $reward['type'] === 'voucher' ? now()->addDays(30) : null,
                    'redemption_code' => $this->generateRedemptionCode(),
                    'redeemed_at' => now(),
                ];

                // If it's store credit, add to customer balance
                if ($reward['type'] === 'store_credit') {
                    $this->addStoreCredit($customer, $reward['value'] * $quantity, 'Points redemption');
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Reward redeemed successfully',
                    'data' => [
                        'redemption' => $redemption,
                        'remaining_points' => $currentPoints - $totalPointsRequired,
                        'instructions' => $this->getRedemptionInstructions($reward),
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to redeem reward',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get store credit balance and history
     */
    public function getStoreCredits(): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $customer = Customer::findOrFail($customerId);

            $balance = $this->getStoreCreditBalance($customer);
            $history = $this->getStoreCreditHistory($customerId);

            return response()->json([
                'success' => true,
                'data' => [
                    'balance' => [
                        'current_balance' => $balance['current'],
                        'total_earned' => $balance['total_earned'],
                        'total_used' => $balance['total_used'],
                        'pending_credits' => $balance['pending'],
                        'expiring_soon' => $balance['expiring_soon'],
                    ],
                    'history' => $history,
                    'usage_tips' => [
                        'Store credits can be used for any purchase',
                        'Credits are applied automatically at checkout',
                        'Credits expire after 1 year if unused',
                        'Minimum ৳50 required to use store credits',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch store credits',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Apply store credit to order
     */
    public function applyStoreCredit(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:50',
                'order_total' => 'required|numeric|min:1',
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
            
            $balance = $this->getStoreCreditBalance($customer)['current'];
            $requestedAmount = $request->amount;
            $orderTotal = $request->order_total;

            if ($balance < $requestedAmount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient store credit balance',
                    'data' => [
                        'available_balance' => $balance,
                        'requested_amount' => $requestedAmount,
                    ],
                ], 400);
            }

            $applicableAmount = min($requestedAmount, $orderTotal);
            $newOrderTotal = $orderTotal - $applicableAmount;
            $remainingBalance = $balance - $applicableAmount;

            return response()->json([
                'success' => true,
                'message' => 'Store credit applied successfully',
                'data' => [
                    'applied_amount' => $applicableAmount,
                    'new_order_total' => $newOrderTotal,
                    'remaining_balance' => $remainingBalance,
                    'savings' => $applicableAmount,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to apply store credit',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get referral program details
     */
    public function getReferralProgram(): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $customer = Customer::findOrFail($customerId);

            $referralData = [
                'referral_code' => 'REF' . str_pad($customerId, 6, '0', STR_PAD_LEFT),
                'total_referrals' => 3,
                'successful_referrals' => 2,
                'pending_referrals' => 1,
                'total_earned' => 500, // points
                'referral_link' => url('/register?ref=REF' . str_pad($customerId, 6, '0', STR_PAD_LEFT)),
                'program_details' => [
                    'referrer_reward' => '200 points + ৳100 store credit',
                    'referee_reward' => '100 points + 10% discount on first order',
                    'minimum_purchase' => '৳500 (for referee)',
                    'reward_validity' => '30 days',
                ],
                'recent_referrals' => $this->getRecentReferrals($customerId),
            ];

            return response()->json([
                'success' => true,
                'data' => ['referral_program' => $referralData],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch referral program details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Private helper methods

    private function calculateLoyaltyPoints(Customer $customer): array
    {
        // In real app, calculate from database
        return [
            'current_points' => 1250,
            'total_earned' => 2500,
            'total_redeemed' => 1250,
            'pending_points' => 150,
            'expiring_soon' => 50,
        ];
    }

    private function calculateCustomerTier(Customer $customer): array
    {
        $currentPoints = $this->calculateLoyaltyPoints($customer)['current_points'];
        
        $tiers = [
            ['name' => 'Bronze', 'min_points' => 0, 'max_points' => 999],
            ['name' => 'Silver', 'min_points' => 1000, 'max_points' => 2999],
            ['name' => 'Gold', 'min_points' => 3000, 'max_points' => 7999],
            ['name' => 'Platinum', 'min_points' => 8000, 'max_points' => 19999],
            ['name' => 'Diamond', 'min_points' => 20000, 'max_points' => null],
        ];

        foreach ($tiers as $index => $tier) {
            if ($currentPoints >= $tier['min_points'] && 
                ($tier['max_points'] === null || $currentPoints <= $tier['max_points'])) {
                
                $nextTier = $tiers[$index + 1] ?? null;
                $progress = 0;
                $pointsNeeded = 0;

                if ($nextTier) {
                    $tierRange = $tier['max_points'] - $tier['min_points'] + 1;
                    $currentInTier = $currentPoints - $tier['min_points'];
                    $progress = ($currentInTier / $tierRange) * 100;
                    $pointsNeeded = $nextTier['min_points'] - $currentPoints;
                }

                return [
                    'current' => $tier['name'],
                    'next' => $nextTier ? $nextTier['name'] : null,
                    'progress' => round($progress, 1),
                    'points_needed' => max(0, $pointsNeeded),
                ];
            }
        }

        return [
            'current' => 'Bronze',
            'next' => 'Silver',
            'progress' => 0,
            'points_needed' => 1000 - $currentPoints,
        ];
    }

    private function getTierBenefits(array $tier): array
    {
        $benefits = [
            'Bronze' => [
                'Point earning rate: 1 point per ৳10 spent',
                'Birthday discount: 5%',
                'Free shipping threshold: ৳1500',
            ],
            'Silver' => [
                'Point earning rate: 1.5 points per ৳10 spent',
                'Birthday discount: 10%',
                'Free shipping threshold: ৳1000',
                'Early access to sales',
            ],
            'Gold' => [
                'Point earning rate: 2 points per ৳10 spent',
                'Birthday discount: 15%',
                'Free shipping threshold: ৳500',
                'Early access to sales',
                'Priority customer support',
            ],
            'Platinum' => [
                'Point earning rate: 2.5 points per ৳10 spent',
                'Birthday discount: 20%',
                'Free shipping on all orders',
                'Early access to sales',
                'Priority customer support',
                'Exclusive member prices',
            ],
            'Diamond' => [
                'Point earning rate: 3 points per ৳10 spent',
                'Birthday discount: 25%',
                'Free shipping on all orders',
                'Early access to sales',
                'Dedicated customer support',
                'Exclusive member prices',
                'VIP customer events',
            ],
        ];

        return $benefits[$tier['current']] ?? $benefits['Bronze'];
    }

    private function getStoreCreditBalance(Customer $customer): array
    {
        // In real app, calculate from database
        return [
            'current' => 250.00,
            'total_earned' => 500.00,
            'total_used' => 250.00,
            'pending' => 0.00,
            'expiring_soon' => 50.00,
        ];
    }

    private function simulatePointsHistory(int $customerId, ?string $type): array
    {
        $history = [
            [
                'id' => 1,
                'type' => 'earned',
                'points' => 150,
                'description' => 'Order purchase',
                'reference' => 'ORD-241118-1234',
                'created_at' => now()->subDays(1),
            ],
            [
                'id' => 2,
                'type' => 'redeemed',
                'points' => -200,
                'description' => 'Store credit voucher',
                'reference' => 'RED-241117-0001',
                'created_at' => now()->subDays(2),
            ],
            [
                'id' => 3,
                'type' => 'earned',
                'points' => 100,
                'description' => 'Referral bonus',
                'reference' => 'REF-241116-0001',
                'created_at' => now()->subDays(3),
            ],
            [
                'id' => 4,
                'type' => 'earned',
                'points' => 50,
                'description' => 'Product review',
                'reference' => 'REV-241115-0001',
                'created_at' => now()->subDays(4),
            ],
            [
                'id' => 5,
                'type' => 'expired',
                'points' => -25,
                'description' => 'Points expired',
                'reference' => 'EXP-241115-0001',
                'created_at' => now()->subDays(5),
            ],
        ];

        if ($type) {
            return array_filter($history, fn($h) => $h['type'] === $type);
        }

        return $history;
    }

    private function getRewardsData(?string $category, ?int $minPoints, ?int $maxPoints): array
    {
        $rewards = [
            [
                'id' => 'reward_1',
                'name' => '৳50 Store Credit',
                'description' => 'Store credit voucher worth ৳50',
                'category' => 'store_credit',
                'type' => 'store_credit',
                'value' => 50,
                'points_required' => 250,
                'image' => 'store-credit-icon',
                'terms' => 'Valid for 30 days',
            ],
            [
                'id' => 'reward_2',
                'name' => '10% Off Next Order',
                'description' => '10% discount on your next purchase',
                'category' => 'discount',
                'type' => 'voucher',
                'value' => 10,
                'points_required' => 200,
                'image' => 'discount-icon',
                'terms' => 'Valid for 15 days, minimum order ৳500',
            ],
            [
                'id' => 'reward_3',
                'name' => 'Free Delivery',
                'description' => 'Free delivery on your next order',
                'category' => 'delivery',
                'type' => 'voucher',
                'value' => 60,
                'points_required' => 150,
                'image' => 'delivery-icon',
                'terms' => 'Valid for 30 days',
            ],
            [
                'id' => 'reward_4',
                'name' => '৳100 Store Credit',
                'description' => 'Store credit voucher worth ৳100',
                'category' => 'store_credit',
                'type' => 'store_credit',
                'value' => 100,
                'points_required' => 500,
                'image' => 'store-credit-icon',
                'terms' => 'Valid for 60 days',
            ],
        ];

        $filtered = $rewards;

        if ($category) {
            $filtered = array_filter($filtered, fn($r) => $r['category'] === $category);
        }

        if ($minPoints !== null) {
            $filtered = array_filter($filtered, fn($r) => $r['points_required'] >= $minPoints);
        }

        if ($maxPoints !== null) {
            $filtered = array_filter($filtered, fn($r) => $r['points_required'] <= $maxPoints);
        }

        return array_values($filtered);
    }

    private function getRewardCategories(): array
    {
        return [
            ['id' => 'store_credit', 'name' => 'Store Credit'],
            ['id' => 'discount', 'name' => 'Discounts'],
            ['id' => 'delivery', 'name' => 'Delivery'],
            ['id' => 'gift', 'name' => 'Gifts'],
        ];
    }

    private function getRewardById(string $rewardId): ?array
    {
        $rewards = $this->getRewardsData(null, null, null);
        
        foreach ($rewards as $reward) {
            if ($reward['id'] === $rewardId) {
                return $reward;
            }
        }

        return null;
    }

    private function generateRedemptionCode(): string
    {
        return strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8));
    }

    private function addStoreCredit(Customer $customer, float $amount, string $reason): void
    {
        // In real app, add to customer store credit balance
    }

    private function getRedemptionInstructions(array $reward): array
    {
        switch ($reward['type']) {
            case 'store_credit':
                return [
                    'Your store credit has been added to your account',
                    'Use it automatically at checkout on your next order',
                    'Check your store credit balance in the loyalty section',
                ];
            case 'voucher':
                return [
                    'Use the redemption code at checkout',
                    'Code is valid for the specified period only',
                    'Cannot be combined with other offers',
                ];
            default:
                return ['Reward details have been sent to your email'];
        }
    }

    private function getStoreCreditHistory(int $customerId): array
    {
        return [
            [
                'id' => 1,
                'type' => 'earned',
                'amount' => 100.00,
                'description' => 'Points redemption',
                'reference' => 'RED-241117-0001',
                'balance_after' => 250.00,
                'created_at' => now()->subDays(2),
            ],
            [
                'id' => 2,
                'type' => 'used',
                'amount' => -50.00,
                'description' => 'Applied to order',
                'reference' => 'ORD-241116-5678',
                'balance_after' => 150.00,
                'created_at' => now()->subDays(3),
            ],
        ];
    }

    private function getRecentReferrals(int $customerId): array
    {
        return [
            [
                'referee_name' => 'John D.',
                'signup_date' => now()->subDays(5),
                'first_purchase_date' => now()->subDays(3),
                'status' => 'completed',
                'earned_points' => 200,
            ],
            [
                'referee_name' => 'Sarah K.',
                'signup_date' => now()->subDays(2),
                'first_purchase_date' => null,
                'status' => 'pending',
                'earned_points' => 0,
            ],
        ];
    }
}