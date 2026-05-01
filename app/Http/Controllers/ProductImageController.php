<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageController extends Controller
{
    /**
     * Get all images for a product
     * 
     * GET /api/products/{productId}/images
     * 
     * If the product has no images and has a base_name, this endpoint will
     * fallback to images from other products with the same base_name.
     * The 'fallback_used' flag indicates if fallback images were returned.
     */
    public function index($productId)
    {
        $product = Product::findOrFail($productId);

        // Try to get images for this specific product
        $images = ProductImage::byProduct($productId)
            ->ordered()
            ->get();

        $fallbackUsed = false;

        // If no images found, fallback to sibling products with same base_name
        if ($images->isEmpty() && !empty($product->base_name)) {
            // Find a sibling product with the same base_name that has images
            $siblingProductId = Product::where('base_name', $product->base_name)
                ->where('id', '!=', $productId)
                ->whereHas('images')  // Only products that have images
                ->orderBy('id')  // Deterministic selection (first by ID)
                ->value('id');

            if ($siblingProductId) {
                $images = ProductImage::byProduct($siblingProductId)
                    ->ordered()
                    ->get();
                $fallbackUsed = true;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $images,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'base_name' => $product->base_name,
            ],
            'fallback_used' => $fallbackUsed,
        ]);
    }

    /**
     * Get a specific image
     * 
     * GET /api/product-images/{id}
     */
    public function show($id)
    {
        $image = ProductImage::with('product')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $image
        ]);
    }

    /**
     * Upload new product image
     * 
     * POST /api/products/{productId}/images
     * 
     * Body (multipart/form-data):
     * - image: file (required, jpeg,png,jpg,gif,webp, max 5MB)
     * - alt_text: string (optional)
     * - is_primary: boolean (optional, default false)
     * - sort_order: integer (optional, default 0)
     */
    public function upload(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);

        $validated = $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // max 5MB
            'alt_text' => 'nullable|string|max:255',
            'is_primary' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Handle file upload
            $image = $request->file('image');
            $imageName = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
            $imagePath = $image->storeAs('products/' . $productId, $imageName, 'public');

            // Create image record
            $productImage = ProductImage::create([
                'product_id' => $productId,
                'image_path' => $imagePath,
                'alt_text' => $validated['alt_text'] ?? $product->name,
                'is_primary' => $validated['is_primary'] ?? false,
                'sort_order' => $validated['sort_order'] ?? 0,
                'is_active' => true,
            ]);

            // If set as primary, update other images
            if ($productImage->is_primary) {
                ProductImage::where('product_id', $productId)
                    ->where('id', '!=', $productImage->id)
                    ->update(['is_primary' => false]);
            }

            // If this is the first image, make it primary
            $imageCount = ProductImage::byProduct($productId)->count();
            if ($imageCount === 1) {
                $productImage->update(['is_primary' => true]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully',
                'data' => $productImage->fresh()
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Delete uploaded file if database operation failed
            if (isset($imagePath) && Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload multiple images at once
     * 
     * POST /api/products/{productId}/images/bulk-upload
     * 
     * Body (multipart/form-data):
     * - images[]: file[] (required, multiple files)
     * - alt_texts[]: string[] (optional, corresponding alt texts)
     */
    public function bulkUpload(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);

        $request->validate([
            'images' => 'required|array|max:10', // max 10 images at once
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'alt_texts' => 'nullable|array',
            'alt_texts.*' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            $uploadedImages = [];
            $images = $request->file('images');
            $altTexts = $request->input('alt_texts', []);

            // Get current max sort order
            $maxSortOrder = ProductImage::byProduct($productId)->max('sort_order') ?? 0;

            foreach ($images as $index => $image) {
                $imageName = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();
                $imagePath = $image->storeAs('products/' . $productId, $imageName, 'public');

                $productImage = ProductImage::create([
                    'product_id' => $productId,
                    'image_path' => $imagePath,
                    'alt_text' => $altTexts[$index] ?? $product->name . ' - Image ' . ($index + 1),
                    'is_primary' => false, // Never set as primary in bulk upload
                    'sort_order' => $maxSortOrder + $index + 1,
                    'is_active' => true,
                ]);

                $uploadedImages[] = $productImage;
            }

            // If no primary image exists, make the first uploaded image primary
            $hasPrimary = ProductImage::byProduct($productId)->primary()->exists();
            if (!$hasPrimary && count($uploadedImages) > 0) {
                $uploadedImages[0]->update(['is_primary' => true]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($uploadedImages) . ' images uploaded successfully',
                'data' => $uploadedImages
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            // Clean up uploaded files
            if (isset($uploadedImages)) {
                foreach ($uploadedImages as $img) {
                    if (Storage::disk('public')->exists($img->image_path)) {
                        Storage::disk('public')->delete($img->image_path);
                    }
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload images: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update image details (alt text, sort order, active status)
     * 
     * PUT /api/product-images/{id}
     */
    public function update(Request $request, $id)
    {
        $image = ProductImage::findOrFail($id);

        $validated = $request->validate([
            'alt_text' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $image->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Image updated successfully',
            'data' => $image->fresh()
        ]);
    }

    /**
     * Set an image as primary
     * 
     * PATCH /api/product-images/{id}/make-primary
     */
    public function makePrimary($id)
    {
        $image = ProductImage::findOrFail($id);

        DB::beginTransaction();
        try {
            $image->makePrimary();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Image set as primary successfully',
                'data' => $image->fresh()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to set primary image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reorder images
     * 
     * PATCH /api/products/{productId}/images/reorder
     * 
     * Body: {
     *   "image_orders": [
     *     {"image_id": 1, "sort_order": 0},
     *     {"image_id": 2, "sort_order": 1},
     *     {"image_id": 3, "sort_order": 2}
     *   ]
     * }
     */
    public function reorder(Request $request, $productId)
    {
        $product = Product::findOrFail($productId);

        $validated = $request->validate([
            'image_orders' => 'required|array',
            'image_orders.*.image_id' => 'required|exists:product_images,id',
            'image_orders.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['image_orders'] as $order) {
                $image = ProductImage::where('id', $order['image_id'])
                    ->where('product_id', $productId)
                    ->first();

                if ($image) {
                    $image->update(['sort_order' => $order['sort_order']]);
                }
            }

            DB::commit();

            $images = ProductImage::byProduct($productId)->ordered()->get();

            return response()->json([
                'success' => true,
                'message' => 'Images reordered successfully',
                'data' => $images
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder images: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an image
     * 
     * DELETE /api/product-images/{id}
     */
    public function destroy($id)
    {
        $image = ProductImage::findOrFail($id);
        $productId = $image->product_id;
        $wasPrimary = $image->is_primary;

        DB::beginTransaction();
        try {
            // Delete file from storage
            if (Storage::disk('public')->exists($image->image_path)) {
                Storage::disk('public')->delete($image->image_path);
            }

            // Delete database record
            $image->delete();

            // If deleted image was primary, set another image as primary
            if ($wasPrimary) {
                $newPrimary = ProductImage::byProduct($productId)
                    ->active()
                    ->ordered()
                    ->first();

                if ($newPrimary) {
                    $newPrimary->update(['is_primary' => true]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Image deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete all images for a product
     * 
     * DELETE /api/products/{productId}/images/delete-all
     */
    public function destroyAll($productId)
    {
        $product = Product::findOrFail($productId);

        DB::beginTransaction();
        try {
            $images = ProductImage::byProduct($productId)->get();

            foreach ($images as $image) {
                if (Storage::disk('public')->exists($image->image_path)) {
                    Storage::disk('public')->delete($image->image_path);
                }
                $image->delete();
            }

            // Try to delete the product folder if empty
            $folderPath = 'products/' . $productId;
            if (Storage::disk('public')->exists($folderPath)) {
                $files = Storage::disk('public')->files($folderPath);
                if (empty($files)) {
                    Storage::disk('public')->deleteDirectory($folderPath);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'All images deleted successfully',
                'deleted_count' => $images->count()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete images: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get image statistics for a product
     * 
     * GET /api/products/{productId}/images/statistics
     */
    public function getStatistics($productId)
    {
        $product = Product::findOrFail($productId);

        $totalImages = ProductImage::byProduct($productId)->count();
        $activeImages = ProductImage::byProduct($productId)->active()->count();
        $inactiveImages = $totalImages - $activeImages;
        $primaryImage = ProductImage::byProduct($productId)->primary()->first();

        // Calculate total storage size
        $images = ProductImage::byProduct($productId)->get();
        $totalSize = 0;
        foreach ($images as $image) {
            if (Storage::disk('public')->exists($image->image_path)) {
                $totalSize += Storage::disk('public')->size($image->image_path);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $productId,
                'product_name' => $product->name,
                'total_images' => $totalImages,
                'active_images' => $activeImages,
                'inactive_images' => $inactiveImages,
                'has_primary' => $primaryImage !== null,
                'primary_image_id' => $primaryImage?->id,
                'total_storage_bytes' => $totalSize,
                'total_storage_mb' => round($totalSize / 1024 / 1024, 2),
            ]
        ]);
    }

    /**
     * Activate/Deactivate an image
     * 
     * PATCH /api/product-images/{id}/toggle-active
     */
    public function toggleActive($id)
    {
        $image = ProductImage::findOrFail($id);

        $image->update(['is_active' => !$image->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Image ' . ($image->is_active ? 'activated' : 'deactivated') . ' successfully',
            'data' => $image->fresh()
        ]);
    }

    /**
     * Get primary image for a product
     * 
     * GET /api/products/{productId}/images/primary
     */
    public function getPrimary($productId)
    {
        $product = Product::findOrFail($productId);
        
        $primaryImage = ProductImage::getPrimaryImageForProduct($productId);

        if (!$primaryImage) {
            return response()->json([
                'success' => false,
                'message' => 'No primary image found for this product'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $primaryImage
        ]);
    }
}