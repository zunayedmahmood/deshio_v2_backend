<?php

namespace App\Http\Controllers;

use App\Models\Wishlist;
use App\Models\Product;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Traits\ProductImageFallback;

class WishlistController extends Controller
{
    use ProductImageFallback;
    /**
     * Get customer's wishlist
     */
    public function index(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();
            
            $wishlistName = $request->get('wishlist_name', 'default');
            
            $wishlistItems = Wishlist::with(['product.images', 'product.category'])
                ->forCustomer($customer->id)
                ->when($wishlistName !== 'all', function ($query) use ($wishlistName) {
                    return $query->byWishlistName($wishlistName);
                })
                ->orderBy('created_at', 'desc')
                ->get();

            $wishlistNames = Wishlist::forCustomer($customer->id)
                ->select('wishlist_name')
                ->distinct()
                ->pluck('wishlist_name');

            return response()->json([
                'success' => true,
                'data' => [
                    'wishlist_items' => $wishlistItems->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'product' => [
                                'id' => $item->product->id,
                                'name' => $item->product->name,
                                'selling_price' => $item->product->selling_price,
                                // ✅ Always return a usable primary image (SKU-core fallback)
                                'images' => array_slice($this->mergedActiveImages($item->product, ['id','url','is_primary']), 0, 1),
                                'category' => $item->product->category->name ?? null,
                                'stock_quantity' => $item->product->stock_quantity,
                                'in_stock' => $item->product->stock_quantity > 0,
                                'is_active' => $item->product->is_active && $item->product->status === 'active',
                            ],
                            'notes' => $item->notes,
                            'wishlist_name' => $item->wishlist_name,
                            'added_at' => $item->created_at,
                        ];
                    }),
                    'wishlist_names' => $wishlistNames,
                    'current_wishlist' => $wishlistName,
                    'total_items' => $wishlistItems->count(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get wishlist: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add product to wishlist
     */
    public function addToWishlist(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'product_id' => 'required|integer|exists:products,id',
                'notes' => 'nullable|string|max:500',
                'wishlist_name' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $product = Product::findOrFail($request->product_id);
            $wishlistName = $request->wishlist_name ?? 'default';

            // Check if item already exists in this wishlist
            $existingWishlistItem = Wishlist::forCustomer($customer->id)
                ->where('product_id', $product->id)
                ->byWishlistName($wishlistName)
                ->first();

            if ($existingWishlistItem) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product already exists in this wishlist',
                ], 400);
            }

            $wishlistItem = Wishlist::create([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'notes' => $request->notes,
                'wishlist_name' => $wishlistName,
            ]);

            $wishlistItem->load(['product.images']);

            return response()->json([
                'success' => true,
                'message' => 'Product added to wishlist successfully',
                'data' => [
                    'wishlist_item' => [
                        'id' => $wishlistItem->id,
                        'product_id' => $wishlistItem->product_id,
                        'product' => [
                            'id' => $wishlistItem->product->id,
                            'name' => $wishlistItem->product->name,
                            'selling_price' => $wishlistItem->product->selling_price,
                            'images' => $wishlistItem->product->images->take(1),
                        ],
                        'notes' => $wishlistItem->notes,
                        'wishlist_name' => $wishlistItem->wishlist_name,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add to wishlist: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove product from wishlist
     */
    public function removeFromWishlist($wishlistItemId)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $wishlistItem = Wishlist::where('id', $wishlistItemId)
                ->forCustomer($customer->id)
                ->firstOrFail();

            $wishlistItem->delete();

            return response()->json([
                'success' => true,
                'message' => 'Item removed from wishlist successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove from wishlist: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Move wishlist item to cart
     */
    public function moveToCart(Request $request, $wishlistItemId)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'quantity' => 'nullable|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $wishlistItem = Wishlist::where('id', $wishlistItemId)
                ->forCustomer($customer->id)
                ->with('product')
                ->firstOrFail();

            $product = $wishlistItem->product;
            $quantity = $request->quantity ?? 1;

            // Check if product is available
            if (!$product->is_active || $product->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is not available for purchase',
                ], 400);
            }

            // Check stock
            if ($product->stock_quantity < $quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock. Available: ' . $product->stock_quantity,
                ], 400);
            }

            // Move to cart
            $wishlistItem->moveToCart($quantity);

            return response()->json([
                'success' => true,
                'message' => 'Item moved to cart successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to move to cart: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear entire wishlist or specific wishlist
     */
    public function clearWishlist(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $wishlistName = $request->get('wishlist_name');

            $query = Wishlist::forCustomer($customer->id);
            
            if ($wishlistName) {
                $query->byWishlistName($wishlistName);
            }

            $deletedCount = $query->count();
            $query->delete();

            return response()->json([
                'success' => true,
                'message' => $wishlistName 
                    ? "Wishlist '{$wishlistName}' cleared successfully" 
                    : 'All wishlists cleared successfully',
                'data' => [
                    'deleted_items' => $deletedCount,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear wishlist: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get wishlist statistics
     */
    public function getWishlistStats()
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $stats = Wishlist::getWishlistStats($customer->id);

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get wishlist stats: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or rename wishlist
     */
    public function manageWishlistName(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'action' => 'required|in:create,rename',
                'old_name' => 'required_if:action,rename|string|max:100',
                'new_name' => 'required|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $newName = $request->new_name;

            // Check if new name already exists
            $existingWishlist = Wishlist::forCustomer($customer->id)
                ->byWishlistName($newName)
                ->exists();

            if ($existingWishlist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Wishlist name already exists',
                ], 400);
            }

            if ($request->action === 'create') {
                // Just return success - wishlist will be created when first item is added
                return response()->json([
                    'success' => true,
                    'message' => 'Wishlist name reserved successfully',
                    'data' => [
                        'wishlist_name' => $newName,
                    ],
                ]);
            }

            if ($request->action === 'rename') {
                $oldName = $request->old_name;
                
                $updatedCount = Wishlist::forCustomer($customer->id)
                    ->byWishlistName($oldName)
                    ->update(['wishlist_name' => $newName]);

                if ($updatedCount === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Wishlist not found',
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Wishlist renamed successfully',
                    'data' => [
                        'old_name' => $oldName,
                        'new_name' => $newName,
                        'updated_items' => $updatedCount,
                    ],
                ]);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to manage wishlist: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Move all wishlist items to cart
     */
    public function moveAllToCart(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $wishlistName = $request->get('wishlist_name');

            $query = Wishlist::with('product')
                ->forCustomer($customer->id);
                
            if ($wishlistName) {
                $query->byWishlistName($wishlistName);
            }

            $wishlistItems = $query->get();

            if ($wishlistItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No items in wishlist',
                ], 400);
            }

            $movedCount = 0;
            $skippedItems = [];

            foreach ($wishlistItems as $item) {
                $product = $item->product;

                // Check availability and stock
                if (!$product->is_active || $product->status !== 'active' || $product->stock_quantity < 1) {
                    $skippedItems[] = [
                        'product_name' => $product->name,
                        'reason' => $product->stock_quantity < 1 ? 'Out of stock' : 'Not available',
                    ];
                    continue;
                }

                try {
                    $item->moveToCart(1);
                    $movedCount++;
                } catch (\Exception $e) {
                    $skippedItems[] = [
                        'product_name' => $product->name,
                        'reason' => 'Failed to add to cart',
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => $movedCount > 0 ? 'Items moved to cart' : 'No items could be moved',
                'data' => [
                    'moved_items' => $movedCount,
                    'skipped_items' => $skippedItems,
                    'total_items' => $wishlistItems->count(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to move items to cart: ' . $e->getMessage(),
            ], 500);
        }
    }
}