<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Traits\ProductImageFallback;

class CartController extends Controller
{
    use ProductImageFallback;
    /**
     * Get customer's cart
     */
    public function index(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();
            
            $cartItems = Cart::with(['product.images', 'product.category', 'product.batches' => function($q) {
                    $q->active()->available();
                }])
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->get();

            $totalAmount = $cartItems->sum(function ($item) {
                return $item->quantity * $item->unit_price;
            });

            $totalItems = $cartItems->sum('quantity');

            return response()->json([
                'success' => true,
                'data' => [
                    'cart_items' => $cartItems->map(function ($item) {
                        // ✅ Use available_inventory from reserved_products (total stock - reservations)
                        $reservedRow = \App\Models\ReservedProduct::where('product_id', $item->product->id)->first();
                        $totalStock = $item->product->batches->sum('quantity');
                        $availableInventory = $reservedRow ? (int) $reservedRow->available_inventory : $totalStock;

                        $currentBatch = $item->product->batches->first();
                        $currentPrice = $currentBatch ? $currentBatch->sell_price : $item->unit_price;
                        
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'product' => [
                                'id' => $item->product->id,
                                'name' => $item->product->name,
                                'selling_price' => $currentPrice,
                                // ✅ Always return a usable primary image (SKU-core fallback)
                                'images' => array_slice($this->mergedActiveImages($item->product, ['id','url','is_primary']), 0, 1),
                                'category' => $item->product->category->name ?? null,
                                'stock_quantity' => $totalStock,
                                'available_inventory' => $availableInventory,
                                'in_stock' => $availableInventory > 0,
                            ],
                            'variant_options' => $item->variant_options,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->quantity * $item->unit_price,
                            'notes' => $item->notes,
                            'added_at' => $item->created_at,
                            'updated_at' => $item->updated_at,
                        ];
                    }),
                    'summary' => [
                        'total_items' => $totalItems,
                        'total_amount' => $totalAmount,
                        'currency' => 'BDT',
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get cart: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add product to cart
     */
    public function addToCart(Request $request)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'product_id' => 'required|integer|exists:products,id',
                'quantity' => 'required|integer|min:1|max:100',
                'notes' => 'nullable|string|max:500',
                'variant_options' => 'nullable|array',
                'variant_options.color' => 'nullable|string|max:50',
                'variant_options.size' => 'nullable|string|max:50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $product = Product::with(['batches' => function($q) {
                $q->active()->available();
            }])->findOrFail($request->product_id);

            // Check if product is archived (ERP uses is_archived instead of status)
            if ($product->is_archived) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product is not available for purchase',
                ], 400);
            }

            // ✅ Check available inventory (total stock - reservations)
            $reservedRow = \App\Models\ReservedProduct::where('product_id', $product->id)->first();
            $totalStock = $product->batches->sum('quantity');
            $availableInventory = $reservedRow ? (int) $reservedRow->available_inventory : $totalStock;
            
            if ($availableInventory < $request->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock. Available: ' . $availableInventory,
                ], 400);
            }
            
            // Get price from the first available batch (you can modify this logic)
            $availableBatch = $product->batches->first();
            if (!$availableBatch) {
                return response()->json([
                    'success' => false,
                    'message' => 'No available batches for this product',
                ], 400);
            }
            
            $productPrice = $availableBatch->sell_price;

            // Check if item already exists in cart (matching product_id and variant_options)
            // Compute hash for database-agnostic comparison
            $variantHash = null;
            if ($request->has('variant_options') && $request->variant_options) {
                $variantHash = md5(json_encode($request->variant_options));
            }
            
            $query = Cart::where('customer_id', $customer->id)
                ->where('product_id', $product->id)
                ->where('status', 'active');
            
            // Match variant_hash (database-agnostic)
            if ($variantHash) {
                $query->where('variant_hash', $variantHash);
            } else {
                $query->whereNull('variant_options');
            }
            
            $existingCartItem = $query->first();

            if ($existingCartItem) {
                // Update existing cart item
                $newQuantity = $existingCartItem->quantity + $request->quantity;
                
                // Re-check available inventory
                $reservedRow = \App\Models\ReservedProduct::where('product_id', $product->id)->first();
                $totalStock = $product->batches->sum('quantity');
                $availableInventory = $reservedRow ? (int) $reservedRow->available_inventory : $totalStock;
                
                if ($newQuantity > $availableInventory) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Total quantity exceeds available stock. Current in cart: ' . $existingCartItem->quantity . ', Available: ' . $availableInventory,
                    ], 400);
                }

                $existingCartItem->update([
                    'quantity' => $newQuantity,
                    'notes' => $request->notes ?? $existingCartItem->notes,
                ]);

                $cartItem = $existingCartItem;
            } else {
                // Create new cart item
                $cartItem = Cart::create([
                    'customer_id' => $customer->id,
                    'product_id' => $product->id,
                    'variant_options' => $request->variant_options,
                    'quantity' => $request->quantity,
                    'unit_price' => $productPrice,
                    'notes' => $request->notes,
                    'status' => 'active',
                ]);
            }

            // Load relationships
            $cartItem->load(['product.images', 'product.category', 'product.batches' => function($q) {
                $q->active()->available();
            }]);
            
            // Get current price from batch
            $currentBatch = $cartItem->product->batches->first();
            $currentPrice = $currentBatch ? $currentBatch->sell_price : $cartItem->unit_price;

            return response()->json([
                'success' => true,
                'message' => 'Product added to cart successfully',
                'data' => [
                    'cart_item' => [
                        'id' => $cartItem->id,
                        'product_id' => $cartItem->product_id,
                        'product' => [
                            'id' => $cartItem->product->id,
                            'name' => $cartItem->product->name,
                            'selling_price' => $currentPrice,
                            'images' => $cartItem->product->images->take(1),
                        ],
                        'variant_options' => $cartItem->variant_options,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $cartItem->unit_price,
                        'total_price' => $cartItem->quantity * $cartItem->unit_price,
                        'notes' => $cartItem->notes,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add to cart: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update cart item quantity
     */
    public function updateQuantity(Request $request, $cartItemId)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'quantity' => 'required|integer|min:1|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $cartItem = Cart::where('id', $cartItemId)
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->firstOrFail();

            $product = Product::with(['batches' => function($q) {
                $q->active()->available();
            }])->findOrFail($cartItem->product_id);

            // Check available inventory (total stock - reservations)
            $reservedRow = \App\Models\ReservedProduct::where('product_id', $product->id)->first();
            $totalStock = $product->batches->sum('quantity');
            $availableInventory = $reservedRow ? (int) $reservedRow->available_inventory : $totalStock;
            
            if ($availableInventory < $request->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock. Available: ' . $availableInventory,
                ], 400);
            }

            $cartItem->update([
                'quantity' => $request->quantity,
                'unit_price' => $product->selling_price, // Update price in case it changed
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Cart item updated successfully',
                'data' => [
                    'cart_item' => [
                        'id' => $cartItem->id,
                        'quantity' => $cartItem->quantity,
                        'unit_price' => $cartItem->unit_price,
                        'total_price' => $cartItem->quantity * $cartItem->unit_price,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update cart: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove item from cart
     */
    public function removeFromCart($cartItemId)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $cartItem = Cart::where('id', $cartItemId)
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->firstOrFail();

            $cartItem->delete();

            return response()->json([
                'success' => true,
                'message' => 'Item removed from cart successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove from cart: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear entire cart
     */
    public function clearCart()
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            Cart::where('customer_id', $customer->id)
                ->where('status', 'active')
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Cart cleared successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cart: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Save item for later (move to saved items)
     */
    public function saveForLater($cartItemId)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $cartItem = Cart::where('id', $cartItemId)
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->firstOrFail();

            $cartItem->update(['status' => 'saved']);

            return response()->json([
                'success' => true,
                'message' => 'Item saved for later',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save item: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Move saved item back to cart
     */
    public function moveToCart($cartItemId)
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $cartItem = Cart::where('id', $cartItemId)
                ->where('customer_id', $customer->id)
                ->where('status', 'saved')
                ->firstOrFail();

            $product = Product::with(['batches' => function($q) {
                $q->active()->available();
            }])->findOrFail($cartItem->product_id);

            // Check available inventory (total stock - reservations)
            $reservedRow = \App\Models\ReservedProduct::where('product_id', $product->id)->first();
            $totalStock = $product->batches->sum('quantity');
            $availableInventory = $reservedRow ? (int) $reservedRow->available_inventory : $totalStock;

            if ($availableInventory < $cartItem->quantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient stock. Available: ' . $availableInventory,
                ], 400);
            }
            
            // Get current price from batch
            $currentBatch = $product->batches->first();
            $currentPrice = $currentBatch ? $currentBatch->sell_price : $cartItem->unit_price;

            $cartItem->update([
                'status' => 'active',
                'unit_price' => $currentPrice, // Update price from batch
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Item moved to cart',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to move item: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get saved items
     */
    public function getSavedItems()
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $savedItems = Cart::with(['product.images', 'product.category', 'product.batches' => function($q) {
                    $q->active()->available();
                }])
                ->where('customer_id', $customer->id)
                ->where('status', 'saved')
                ->orderBy('updated_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'saved_items' => $savedItems->map(function ($item) {
                        // Get current stock and price from batches
                        $totalStock = $item->product->batches->sum('quantity');
                        $currentBatch = $item->product->batches->first();
                        $currentPrice = $currentBatch ? $currentBatch->sell_price : $item->unit_price;
                        
                        return [
                            'id' => $item->id,
                            'product_id' => $item->product_id,
                            'product' => [
                                'id' => $item->product->id,
                                'name' => $item->product->name,
                                'selling_price' => $currentPrice,
                                // ✅ Always return a usable primary image (SKU-core fallback)
                                'images' => array_slice($this->mergedActiveImages($item->product, ['id','url','is_primary']), 0, 1),
                                'category' => $item->product->category->name ?? null,
                                'stock_quantity' => $totalStock,
                                'available_inventory' => $availableInventory,
                                'in_stock' => $availableInventory > 0,
                                'price_changed' => $item->unit_price != $currentPrice,
                            ],
                            'quantity' => $item->quantity,
                            'original_price' => $item->unit_price,
                            'current_price' => $currentPrice,
                            'notes' => $item->notes,
                            'saved_at' => $item->updated_at,
                        ];
                    }),
                    'total_saved_items' => $savedItems->count(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get saved items: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get cart summary (item count, total)
     */
    public function getCartSummary()
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $cartItems = Cart::where('customer_id', $customer->id)
                ->where('status', 'active')
                ->get();

            $totalItems = $cartItems->sum('quantity');
            $totalAmount = $cartItems->sum(function ($item) {
                return $item->quantity * $item->unit_price;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'total_items' => $totalItems,
                    'total_amount' => $totalAmount,
                    'currency' => 'BDT',
                    'has_items' => $totalItems > 0,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get cart summary: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate cart before checkout
     */
    public function validateCart()
    {
        try {
            $customer = JWTAuth::parseToken()->authenticate();

            $cartItems = Cart::with(['product.batches' => function($q) {
                    $q->active()->available();
                }])
                ->where('customer_id', $customer->id)
                ->where('status', 'active')
                ->get();

            if ($cartItems->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cart is empty',
                ], 400);
            }

            $issues = [];
            $validItems = [];

            foreach ($cartItems as $item) {
                $product = $item->product;
                
                // Check product availability (ERP uses is_archived)
                if ($product->is_archived) {
                    $issues[] = [
                        'item_id' => $item->id,
                        'product_name' => $product->name,
                        'issue' => 'Product is no longer available',
                    ];
                    continue;
                }

                // Check available inventory (total stock - reservations)
                $reservedRow = \App\Models\ReservedProduct::where('product_id', $product->id)->first();
                $totalStock = $product->batches->sum('quantity');
                $availableInventory = $reservedRow ? (int) $reservedRow->available_inventory : $totalStock;

                if ($availableInventory < $item->quantity) {
                    $issues[] = [
                        'item_id' => $item->id,
                        'product_name' => $product->name,
                        'issue' => 'Insufficient stock. Available: ' . $availableInventory,
                        'available_quantity' => $availableInventory,
                    ];
                    continue;
                }

                // Check price changes from batch
                $currentBatch = $product->batches->first();
                $currentPrice = $currentBatch ? $currentBatch->sell_price : $item->unit_price;
                if ($item->unit_price != $currentPrice) {
                    $issues[] = [
                        'item_id' => $item->id,
                        'product_name' => $product->name,
                        'issue' => 'Price has changed',
                        'old_price' => $item->unit_price,
                        'new_price' => $currentPrice,
                    ];
                }

                $validItems[] = $item;
            }

            return response()->json([
                'success' => count($issues) === 0,
                'data' => [
                    'is_valid' => count($issues) === 0,
                    'valid_items_count' => count($validItems),
                    'total_items_count' => $cartItems->count(),
                    'issues' => $issues,
                    'total_amount' => $validItems ? collect($validItems)->sum(function ($item) {
                        return $item->quantity * $item->product->selling_price;
                    }) : 0,
                ],
                'message' => count($issues) === 0 ? 'Cart is valid for checkout' : 'Cart has issues that need to be resolved',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to validate cart: ' . $e->getMessage(),
            ], 500);
        }
    }
}