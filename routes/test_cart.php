<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Test Routes for Cart API Debugging
|--------------------------------------------------------------------------
*/

Route::post('/test-cart-add', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'message' => 'Test endpoint reached',
        'request_data' => $request->all(),
        'headers' => [
            'Authorization' => $request->header('Authorization'),
            'Content-Type' => $request->header('Content-Type'),
        ],
    ]);
});

Route::middleware('auth:customer')->post('/test-cart-add-auth', function (\Illuminate\Http\Request $request) {
    try {
        $customer = \Tymon\JWTAuth\Facades\JWTAuth::parseToken()->authenticate();
        
        return response()->json([
            'message' => 'Authenticated test endpoint reached',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
            ],
            'request_data' => $request->all(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Authentication failed',
            'error' => $e->getMessage(),
        ], 401);
    }
});

Route::middleware('auth:customer')->post('/test-cart-add-full', function (\Illuminate\Http\Request $request) {
    try {
        $customer = \Tymon\JWTAuth\Facades\JWTAuth::parseToken()->authenticate();
        
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
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

        $product = \App\Models\Product::find($request->product_id);
        
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Validation passed',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
            ],
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'stock' => $product->stock_quantity,
            ],
            'request_data' => $request->all(),
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], 500);
    }
});
