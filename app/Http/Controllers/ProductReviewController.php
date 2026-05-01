<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ProductReviewController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:customer');
    }

    /**
     * Get product reviews
     */
    public function getProductReviews($productId, Request $request): JsonResponse
    {
        try {
            $product = Product::findOrFail($productId);
            
            $rating = $request->query('rating');
            $sort = $request->query('sort', 'newest'); // newest, oldest, highest_rating, lowest_rating, helpful
            $perPage = $request->query('per_page', 10);

            $reviews = $this->simulateProductReviews($productId, $rating, $sort);
            $paginated = array_slice($reviews, 0, $perPage);

            $reviewStats = $this->calculateReviewStats($reviews);

            return response()->json([
                'success' => true,
                'data' => [
                    'product' => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'average_rating' => $reviewStats['average_rating'],
                        'total_reviews' => $reviewStats['total_reviews'],
                    ],
                    'reviews' => $paginated,
                    'pagination' => [
                        'current_page' => 1,
                        'total_pages' => ceil(count($reviews) / $perPage),
                        'per_page' => $perPage,
                        'total' => count($reviews),
                    ],
                    'statistics' => $reviewStats,
                    'filters' => [
                        'rating' => $rating,
                        'sort' => $sort,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch product reviews',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer's reviews
     */
    public function getCustomerReviews(Request $request): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $status = $request->query('status'); // pending, published, rejected
            $perPage = $request->query('per_page', 15);

            $reviews = $this->simulateCustomerReviews($customerId, $status);
            $paginated = array_slice($reviews, 0, $perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'reviews' => $paginated,
                    'pagination' => [
                        'current_page' => 1,
                        'total_pages' => ceil(count($reviews) / $perPage),
                        'per_page' => $perPage,
                        'total' => count($reviews),
                    ],
                    'summary' => [
                        'total_reviews' => count($reviews),
                        'pending_reviews' => count(array_filter($reviews, fn($r) => $r['status'] === 'pending')),
                        'published_reviews' => count(array_filter($reviews, fn($r) => $r['status'] === 'published')),
                        'average_rating_given' => $this->calculateAverageRating($reviews),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch customer reviews',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get products available for review
     */
    public function getReviewableProducts(): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();

            // Get completed orders with products not yet reviewed
            $reviewableProducts = $this->getProductsForReview($customerId);

            return response()->json([
                'success' => true,
                'data' => [
                    'reviewable_products' => $reviewableProducts,
                    'total_pending_reviews' => count($reviewableProducts),
                    'incentive_message' => 'Get 50 loyalty points for each review you write!',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reviewable products',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create product review
     */
    public function createReview(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'order_number' => 'required|string',
                'rating' => 'required|integer|min:1|max:5',
                'title' => 'required|string|max:100',
                'comment' => 'required|string|max:1000',
                'pros' => 'nullable|string|max:500',
                'cons' => 'nullable|string|max:500',
                'recommend' => 'boolean',
                'images' => 'nullable|array|max:5',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max per image
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();

            // Verify customer purchased this product
            $canReview = $this->verifyPurchase($customerId, $request->product_id, $request->order_number);
            if (!$canReview) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only review products you have purchased',
                ], 403);
            }

            // Check if already reviewed
            $alreadyReviewed = $this->hasAlreadyReviewed($customerId, $request->product_id);
            if ($alreadyReviewed) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already reviewed this product',
                ], 400);
            }

            // Handle image uploads
            $imagePaths = [];
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('review-images', 'public');
                    $imagePaths[] = [
                        'path' => $path,
                        'url' => Storage::url($path),
                        'filename' => $image->getClientOriginalName(),
                    ];
                }
            }

            // Create review
            $reviewId = 'REV-' . date('ymd') . '-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $review = [
                'review_id' => $reviewId,
                'customer_id' => $customerId,
                'product_id' => $request->product_id,
                'order_number' => $request->order_number,
                'rating' => $request->rating,
                'title' => $request->title,
                'comment' => $request->comment,
                'pros' => $request->pros,
                'cons' => $request->cons,
                'recommend' => $request->recommend ?? true,
                'images' => $imagePaths,
                'status' => 'pending', // Will be moderated
                'helpful_count' => 0,
                'created_at' => now(),
            ];

            // Award loyalty points
            $pointsEarned = 50;

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully',
                'data' => [
                    'review' => $review,
                    'points_earned' => $pointsEarned,
                    'moderation_message' => 'Your review will be published after moderation (usually within 24 hours)',
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create review',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update existing review
     */
    public function updateReview(Request $request, $reviewId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'rating' => 'sometimes|integer|min:1|max:5',
                'title' => 'sometimes|string|max:100',
                'comment' => 'sometimes|string|max:1000',
                'pros' => 'nullable|string|max:500',
                'cons' => 'nullable|string|max:500',
                'recommend' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();

            // Verify review ownership
            if (!$this->verifyReviewOwnership($reviewId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found',
                ], 404);
            }

            // In real app, update database
            $updatedReview = [
                'review_id' => $reviewId,
                'rating' => $request->rating ?? 5,
                'title' => $request->title ?? 'Updated Review',
                'comment' => $request->comment ?? 'Updated review comment',
                'pros' => $request->pros,
                'cons' => $request->cons,
                'recommend' => $request->recommend ?? true,
                'status' => 'pending', // Re-moderation required
                'updated_at' => now(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Review updated successfully',
                'data' => [
                    'review' => $updatedReview,
                    'moderation_message' => 'Updated review will be re-moderated before publishing',
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update review',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete review
     */
    public function deleteReview($reviewId): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();

            if (!$this->verifyReviewOwnership($reviewId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Review not found',
                ], 404);
            }

            // In real app, soft delete from database
            
            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete review',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark review as helpful
     */
    public function markHelpful(Request $request, $reviewId): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();

            // Check if already marked helpful
            $alreadyMarked = $this->hasMarkedHelpful($customerId, $reviewId);
            if ($alreadyMarked) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already marked this review as helpful',
                ], 400);
            }

            // In real app, update database
            $helpfulCount = random_int(5, 20); // Simulate current helpful count

            return response()->json([
                'success' => true,
                'message' => 'Review marked as helpful',
                'data' => [
                    'review_id' => $reviewId,
                    'helpful_count' => $helpfulCount + 1,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark review as helpful',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Report inappropriate review
     */
    public function reportReview(Request $request, $reviewId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|in:inappropriate_content,spam,fake_review,offensive_language,other',
                'comment' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();

            $report = [
                'review_id' => $reviewId,
                'reporter_id' => $customerId,
                'reason' => $request->reason,
                'comment' => $request->comment,
                'status' => 'pending',
                'reported_at' => now(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Review reported successfully. Our team will investigate.',
                'data' => ['report' => $report],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to report review',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Private helper methods

    private function simulateProductReviews($productId, ?int $rating, string $sort): array
    {
        $reviews = [
            [
                'review_id' => 'REV-241118-0001',
                'customer_name' => 'Ahmed K.',
                'customer_verified' => true,
                'rating' => 5,
                'title' => 'Excellent product!',
                'comment' => 'Really happy with this purchase. Quality is great and delivery was fast.',
                'pros' => 'Good quality, fast delivery',
                'cons' => 'Nothing to complain about',
                'recommend' => true,
                'helpful_count' => 12,
                'has_images' => true,
                'purchase_verified' => true,
                'created_at' => now()->subDays(2),
                'images' => [
                    ['url' => 'https://example.com/review1.jpg', 'thumb' => 'https://example.com/review1_thumb.jpg']
                ],
            ],
            [
                'review_id' => 'REV-241117-0002',
                'customer_name' => 'Sarah M.',
                'customer_verified' => true,
                'rating' => 4,
                'title' => 'Good value for money',
                'comment' => 'Product is as described. Packaging could be better.',
                'pros' => 'Good price, works well',
                'cons' => 'Packaging not great',
                'recommend' => true,
                'helpful_count' => 8,
                'has_images' => false,
                'purchase_verified' => true,
                'created_at' => now()->subDays(5),
                'images' => [],
            ],
            [
                'review_id' => 'REV-241116-0003',
                'customer_name' => 'Mohammad R.',
                'customer_verified' => false,
                'rating' => 3,
                'title' => 'Average product',
                'comment' => 'Its okay, but expected better quality for this price.',
                'pros' => 'Delivered on time',
                'cons' => 'Quality could be better',
                'recommend' => false,
                'helpful_count' => 3,
                'has_images' => false,
                'purchase_verified' => true,
                'created_at' => now()->subWeek(),
                'images' => [],
            ],
        ];

        // Apply rating filter
        if ($rating) {
            $reviews = array_filter($reviews, fn($r) => $r['rating'] == $rating);
        }

        // Apply sorting
        switch ($sort) {
            case 'oldest':
                usort($reviews, fn($a, $b) => $a['created_at'] <=> $b['created_at']);
                break;
            case 'highest_rating':
                usort($reviews, fn($a, $b) => $b['rating'] <=> $a['rating']);
                break;
            case 'lowest_rating':
                usort($reviews, fn($a, $b) => $a['rating'] <=> $b['rating']);
                break;
            case 'helpful':
                usort($reviews, fn($a, $b) => $b['helpful_count'] <=> $a['helpful_count']);
                break;
            case 'newest':
            default:
                usort($reviews, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
                break;
        }

        return array_values($reviews);
    }

    private function calculateReviewStats(array $reviews): array
    {
        if (empty($reviews)) {
            return [
                'average_rating' => 0,
                'total_reviews' => 0,
                'rating_distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                'verified_reviews_count' => 0,
                'recommendation_percentage' => 0,
            ];
        }

        $totalRating = array_sum(array_column($reviews, 'rating'));
        $averageRating = round($totalRating / count($reviews), 1);

        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($reviews as $review) {
            $distribution[$review['rating']]++;
        }

        $verifiedCount = count(array_filter($reviews, fn($r) => $r['purchase_verified']));
        $recommendCount = count(array_filter($reviews, fn($r) => $r['recommend']));
        $recommendPercentage = count($reviews) > 0 ? round(($recommendCount / count($reviews)) * 100) : 0;

        return [
            'average_rating' => $averageRating,
            'total_reviews' => count($reviews),
            'rating_distribution' => $distribution,
            'verified_reviews_count' => $verifiedCount,
            'recommendation_percentage' => $recommendPercentage,
        ];
    }

    private function simulateCustomerReviews(int $customerId, ?string $status): array
    {
        $reviews = [
            [
                'review_id' => 'REV-241118-0001',
                'product_id' => 1,
                'product_name' => 'Smartphone XYZ',
                'order_number' => 'ORD-241115-1234',
                'rating' => 5,
                'title' => 'Great phone!',
                'comment' => 'Very satisfied with this purchase.',
                'status' => 'published',
                'helpful_count' => 12,
                'created_at' => now()->subDays(3),
            ],
            [
                'review_id' => 'REV-241117-0002',
                'product_id' => 2,
                'product_name' => 'Laptop ABC',
                'order_number' => 'ORD-241110-5678',
                'rating' => 4,
                'title' => 'Good laptop',
                'comment' => 'Works well for my needs.',
                'status' => 'pending',
                'helpful_count' => 0,
                'created_at' => now()->subDays(1),
            ],
        ];

        if ($status) {
            return array_filter($reviews, fn($r) => $r['status'] === $status);
        }

        return $reviews;
    }

    private function calculateAverageRating(array $reviews): float
    {
        if (empty($reviews)) {
            return 0;
        }

        $totalRating = array_sum(array_column($reviews, 'rating'));
        return round($totalRating / count($reviews), 1);
    }

    private function getProductsForReview(int $customerId): array
    {
        // Simulate products available for review
        return [
            [
                'product_id' => 1,
                'product_name' => 'Wireless Headphones',
                'order_number' => 'ORD-241118-9999',
                'purchased_date' => now()->subDays(3),
                'delivery_date' => now()->subDays(1),
                'price' => 2500.00,
                'image_url' => 'https://example.com/headphones.jpg',
                'can_review_until' => now()->addDays(27), // 30 days from delivery
            ],
            [
                'product_id' => 2,
                'product_name' => 'Bluetooth Speaker',
                'order_number' => 'ORD-241116-8888',
                'purchased_date' => now()->subDays(5),
                'delivery_date' => now()->subDays(3),
                'price' => 1800.00,
                'image_url' => 'https://example.com/speaker.jpg',
                'can_review_until' => now()->addDays(25),
            ],
        ];
    }

    private function verifyPurchase(int $customerId, int $productId, string $orderNumber): bool
    {
        // In real app, check if customer purchased this product in the specified order
        return true; // Simulate successful verification
    }

    private function hasAlreadyReviewed(int $customerId, int $productId): bool
    {
        // In real app, check database for existing review
        return false; // Simulate no existing review
    }

    private function verifyReviewOwnership(string $reviewId, int $customerId): bool
    {
        // In real app, verify the review belongs to the customer
        return str_contains($reviewId, 'REV-'); // Simple check for demo
    }

    private function hasMarkedHelpful(int $customerId, string $reviewId): bool
    {
        // In real app, check if customer already marked this review as helpful
        return false; // Simulate not marked yet
    }
}