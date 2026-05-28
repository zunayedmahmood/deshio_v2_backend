<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Product;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Support\MediaUrl;

class CollectionController extends Controller
{
    use DatabaseAgnosticSearch;

    private function deleteStoredImage(?string $path): void
    {
        $storedPath = MediaUrl::storedPath($path);
        if ($storedPath) {
            Storage::disk('public')->delete($storedPath);
        }
    }
    public function index(Request $request)
    {
        $query = Collection::with(['createdBy', 'products']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->byType($request->type);
        }

        if ($request->has('season')) {
            $query->bySeason($request->season);
        }

        if ($request->has('year')) {
            $query->byYear($request->year);
        }

        if ($request->has('current') && filter_var($request->current, FILTER_VALIDATE_BOOLEAN)) {
            $query->current();
        }

        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['name', 'description'], $search);
        }

        $query->orderBy('sort_order')->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        $collections = $query->paginate($perPage);

        return response()->json(['success' => true, 'data' => $collections]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|unique:collections,slug',
            'description' => 'nullable|string',
            'type' => 'required|in:season,occasion,category,campaign',
            'season' => 'nullable|in:Spring,Summer,Fall,Winter',
            'year' => 'nullable|integer|min:2000|max:2100',
            'launch_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:launch_date',
            'banner_image' => $request->hasFile('banner_image') ? 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120' : 'nullable|string|max:2048',
            'thumbnail_image' => $request->hasFile('thumbnail_image') ? 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048' : 'nullable|string|max:2048',
            'status' => 'nullable|in:draft,active,archived',
            'sort_order' => 'nullable|integer',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['created_by'] = auth()->id();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Handle Banner Upload
        if ($request->hasFile('banner_image')) {
            $banner = $request->file('banner_image');
            $bannerName = time() . '_banner_' . Str::slug($data['name']) . '.' . $banner->getClientOriginalExtension();
            $bannerPath = $banner->storeAs('collections/banners', $bannerName, 'public');
            $data['banner_image'] = $bannerPath;
        }

        // Handle Thumbnail Upload
        if ($request->hasFile('thumbnail_image')) {
            $thumbnail = $request->file('thumbnail_image');
            $thumbnailName = time() . '_thumb_' . Str::slug($data['name']) . '.' . $thumbnail->getClientOriginalExtension();
            $thumbnailPath = $thumbnail->storeAs('collections', $thumbnailName, 'public');
            $data['thumbnail_image'] = $thumbnailPath;
        }

        $collection = Collection::create($data);

        return response()->json(['success' => true, 'data' => $collection->load('createdBy'), 'message' => 'Collection created successfully'], 201);
    }

    public function show($id)
    {
        $collection = Collection::with(['createdBy', 'products'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $collection]);
    }

    public function update(Request $request, $id)
    {
        $collection = Collection::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'nullable|string|unique:collections,slug,' . $id,
            'description' => 'nullable|string',
            'type' => 'sometimes|required|in:season,occasion,category,campaign',
            'season' => 'nullable|in:Spring,Summer,Fall,Winter',
            'year' => 'nullable|integer|min:2000|max:2100',
            'launch_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:launch_date',
            'banner_image' => $request->hasFile('banner_image') ? 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120' : 'nullable|string|max:2048',
            'thumbnail_image' => $request->hasFile('thumbnail_image') ? 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048' : 'nullable|string|max:2048',
            'remove_banner' => 'nullable|boolean',
            'remove_thumbnail' => 'nullable|boolean',
            'status' => 'nullable|in:draft,active,archived',
            'sort_order' => 'nullable|integer',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Handle Banner removal/upload
        if ($request->remove_banner && $collection->banner_image) {
            $this->deleteStoredImage($collection->banner_image);
            $data['banner_image'] = null;
        }
        if ($request->hasFile('banner_image')) {
            if ($collection->banner_image) {
                $this->deleteStoredImage($collection->banner_image);
            }
            $banner = $request->file('banner_image');
            $bannerName = time() . '_banner_' . Str::slug($data['name'] ?? $collection->name) . '.' . $banner->getClientOriginalExtension();
            $bannerPath = $banner->storeAs('collections/banners', $bannerName, 'public');
            $data['banner_image'] = $bannerPath;
        }

        // Handle Thumbnail removal/upload
        if ($request->remove_thumbnail && $collection->thumbnail_image) {
            $this->deleteStoredImage($collection->thumbnail_image);
            $data['thumbnail_image'] = null;
        }
        if ($request->hasFile('thumbnail_image')) {
            if ($collection->thumbnail_image) {
                $this->deleteStoredImage($collection->thumbnail_image);
            }
            $thumbnail = $request->file('thumbnail_image');
            $thumbnailName = time() . '_thumb_' . Str::slug($data['name'] ?? $collection->name) . '.' . $thumbnail->getClientOriginalExtension();
            $thumbnailPath = $thumbnail->storeAs('collections', $thumbnailName, 'public');
            $data['thumbnail_image'] = $thumbnailPath;
        }

        $collection->update($data);

        return response()->json(['success' => true, 'data' => $collection->load('createdBy'), 'message' => 'Collection updated successfully']);
    }

    public function destroy($id)
    {
        $collection = Collection::findOrFail($id);
        $collection->delete();

        return response()->json(['success' => true, 'message' => 'Collection deleted successfully']);
    }

    public function addProducts(Request $request, $id)
    {
        $collection = Collection::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'required|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $syncData = [];
        foreach ($request->product_ids as $index => $productId) {
            $syncData[$productId] = ['sort_order' => $index];
        }

        $collection->products()->syncWithoutDetaching($syncData);

        return response()->json(['success' => true, 'message' => 'Products added to collection successfully']);
    }

    public function removeProduct($id, $productId)
    {
        $collection = Collection::findOrFail($id);
        $collection->products()->detach($productId);

        return response()->json(['success' => true, 'message' => 'Product removed from collection successfully']);
    }

    public function getProducts($id, Request $request)
    {
        $collection = Collection::findOrFail($id);

        $products = $collection->products()
            ->with(['images', 'category'])
            ->paginate($request->get('per_page', 20));

        return response()->json(['success' => true, 'data' => $products]);
    }

    public function reorderProducts(Request $request, $id)
    {
        $collection = Collection::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'product_orders' => 'required|array',
            'product_orders.*.product_id' => 'required|exists:products,id',
            'product_orders.*.sort_order' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $syncData = [];
        foreach ($request->product_orders as $order) {
            $syncData[$order['product_id']] = ['sort_order' => $order['sort_order']];
        }

        $collection->products()->sync($syncData);

        return response()->json(['success' => true, 'message' => 'Products reordered successfully']);
    }

    public function getStatistics(Request $request)
    {
        $stats = [
            'total' => Collection::count(),
            'active' => Collection::active()->count(),
            'draft' => Collection::where('status', 'draft')->count(),
            'archived' => Collection::where('status', 'archived')->count(),
            'by_type' => [
                'season' => Collection::byType('season')->count(),
                'occasion' => Collection::byType('occasion')->count(),
                'category' => Collection::byType('category')->count(),
                'campaign' => Collection::byType('campaign')->count(),
            ],
            'current' => Collection::current()->count(),
        ];

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function duplicate($id)
    {
        $collection = Collection::with('products')->findOrFail($id);

        $newCollection = $collection->replicate();
        $newCollection->name = $collection->name . ' (Copy)';
        $newCollection->slug = Str::slug($newCollection->name);
        $newCollection->status = 'draft';
        $newCollection->created_by = auth()->id();
        $newCollection->save();

        // Copy products
        $products = $collection->products->pluck('id')->toArray();
        $syncData = [];
        foreach ($products as $index => $productId) {
            $syncData[$productId] = ['sort_order' => $index];
        }
        $newCollection->products()->sync($syncData);

        return response()->json(['success' => true, 'data' => $newCollection->load('products'), 'message' => 'Collection duplicated successfully'], 201);
    }
}
