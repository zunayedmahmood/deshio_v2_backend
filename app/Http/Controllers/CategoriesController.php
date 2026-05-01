<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CategoriesController extends Controller
{
    use DatabaseAgnosticSearch;
    public function createCategory(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048', // max 2MB
            'color' => 'nullable|string|max:7', // hex color
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        // Validate parent category doesn't create circular reference
        if (isset($validated['parent_id'])) {
            $parent = Category::find($validated['parent_id']);
            if (!$parent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parent category not found'
                ], 404);
            }
        }

        // Handle image upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '_' . Str::slug($validated['title']) . '.' . $image->getClientOriginalExtension();
            $imagePath = $image->storeAs('categories', $imageName, 'public');
            $validated['image'] = $imagePath;
        }

        // Generate slug from title if not provided
        if (!isset($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['title']);
        }

        // Ensure slug is unique
        $originalSlug = $validated['slug'];
        $counter = 1;
        while (Category::where('slug', $validated['slug'])->exists()) {
            $validated['slug'] = $originalSlug . '-' . $counter;
            $counter++;
        }

        $category = Category::create($validated);

        // Load relationships
        $category->load('parent', 'children');

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully',
            'data' => $category
        ], 201);
    }

    public function updateCategory(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048', // max 2MB
            'remove_image' => 'nullable|boolean', // flag to remove existing image
            'color' => 'nullable|string|max:7',
            'icon' => 'nullable|string|max:100',
            'order' => 'nullable|integer|min:0',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        // Prevent setting self as parent
        if (isset($validated['parent_id']) && $validated['parent_id'] == $category->id) {
            return response()->json([
                'success' => false,
                'message' => 'Category cannot be its own parent'
            ], 400);
        }

        // Prevent circular reference (setting a descendant as parent)
        if (isset($validated['parent_id'])) {
            $descendants = $category->descendants();
            if ($descendants->contains('id', $validated['parent_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot set a descendant category as parent (circular reference)'
                ], 400);
            }
        }

        // Handle image removal
        if ($request->has('remove_image') && $request->remove_image == true) {
            if ($category->image) {
                \Storage::disk('public')->delete($category->image);
                $validated['image'] = null;
            }
        }

        // Handle new image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($category->image) {
                \Storage::disk('public')->delete($category->image);
            }
            
            $image = $request->file('image');
            $imageName = time() . '_' . Str::slug($validated['title'] ?? $category->title) . '.' . $image->getClientOriginalExtension();
            $imagePath = $image->storeAs('categories', $imageName, 'public');
            $validated['image'] = $imagePath;
        }

        // Update slug if title changed
        if (isset($validated['title']) && $validated['title'] !== $category->title) {
            $newSlug = Str::slug($validated['title']);
            $originalSlug = $newSlug;
            $counter = 1;
            while (Category::where('slug', $newSlug)->where('id', '!=', $category->id)->exists()) {
                $newSlug = $originalSlug . '-' . $counter;
                $counter++;
            }
            $validated['slug'] = $newSlug;
        }

        $category->update($validated);

        // Load relationships
        $category->load('parent', 'children');

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully',
            'data' => $category
        ]);
    }

    public function deleteCategory($id)
    {
        $category = Category::findOrFail($id);

        // Check if category has children
        if ($category->hasChildren()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with subcategories. Delete or move subcategories first.'
            ], 400);
        }

        // Check if category has products
        if ($category->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with associated products'
            ], 400);
        }

        // Soft delete by setting is_active to false
        $category->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Category deactivated successfully'
        ]);
    }

    public function activateCategory($id)
    {
        $category = Category::findOrFail($id);

        $category->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Category activated successfully',
            'data' => $category
        ]);
    }

    public function deactivateCategory($id)
    {
        $category = Category::findOrFail($id);

        // Check if category has active products
        if ($category->activeProducts()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate category with active products'
            ], 400);
        }

        $category->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Category deactivated successfully'
        ]);
    }

    public function getCategories(Request $request)
    {
        $query = Category::query();

        // Filters
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by parent_id (null for root categories)
        if ($request->has('parent_id')) {
            if ($request->parent_id === 'null' || $request->parent_id === null) {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $request->parent_id);
            }
        }

        // Filter by level
        if ($request->has('level')) {
            $query->where('level', $request->level);
        }

        // Get hierarchical tree structure
        if ($request->boolean('tree')) {
            $categories = $query->with('allChildren')->whereNull('parent_id')->get();
            
            return response()->json([
                'success' => true,
                'data' => $categories
            ]);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['title', 'description', 'slug'], $search);
        }

        // Load relationships
        $query->with(['parent', 'children']);

        // Sorting
        $sortBy = $request->get('sort_by', 'order');
        $sortDirection = $request->get('sort_direction', 'asc');

        $allowedSortFields = ['title', 'slug', 'order', 'created_at', 'level'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        $categories = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function getCategory($id)
    {
        $category = Category::with([
            'parent',
            'children.children',
            'activeProducts' => function($query) {
                $query->select('id', 'name', 'category_id', 'is_active')->limit(10);
            }
        ])->findOrFail($id);

        // Add full path and additional info
        $categoryArray = $category->toArray();
        $categoryArray['full_path'] = $category->getFullPath();
        $categoryArray['ancestors'] = $category->ancestors()->toArray();
        $categoryArray['has_children'] = $category->hasChildren();
        $categoryArray['is_root'] = $category->isRoot();

        return response()->json([
            'success' => true,
            'data' => $categoryArray
        ]);
    }

    public function getCategoryStats()
    {
        $stats = [
            'total_categories' => Category::count(),
            'active_categories' => Category::where('is_active', true)->count(),
            'inactive_categories' => Category::where('is_active', false)->count(),
            'categories_with_products' => Category::whereHas('products')->count(),
            'total_products_by_category' => Category::withCount('products')
                ->where('is_active', true)
                ->orderBy('products_count', 'desc')
                ->take(10)
                ->get(['title', 'products_count']),
            'recent_categories' => Category::orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['title', 'slug', 'is_active', 'created_at'])
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'category_ids' => 'required|array',
            'category_ids.*' => 'exists:categories,id',
            'is_active' => 'required|boolean',
        ]);

        // Check if any categories have active products when deactivating
        if (!$validated['is_active']) {
            $categoriesWithProducts = Category::whereIn('id', $validated['category_ids'])
                ->whereHas('activeProducts')
                ->pluck('title')
                ->toArray();

            if (!empty($categoriesWithProducts)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot deactivate categories with active products: ' . implode(', ', $categoriesWithProducts)
                ], 400);
            }
        }

        $count = Category::whereIn('id', $validated['category_ids'])
            ->update(['is_active' => $validated['is_active']]);

        return response()->json([
            'success' => true,
            'message' => "Updated {$count} categories successfully"
        ]);
    }

    public function reorderCategories(Request $request)
    {
        $validated = $request->validate([
            'categories' => 'required|array',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.order' => 'required|integer|min:0',
        ]);

        foreach ($validated['categories'] as $categoryData) {
            Category::where('id', $categoryData['id'])
                ->update(['order' => $categoryData['order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Categories reordered successfully'
        ]);
    }

    // Nested category specific endpoints

    public function getCategoryTree(Request $request)
    {
        $query = Category::query();

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Get all root categories with nested children
        $categories = $query->with('allChildren')->whereNull('parent_id')->orderBy('order')->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function getRootCategories(Request $request)
    {
        $query = Category::rootCategories();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->with('children')->orderBy('order');

        $categories = $query->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function getSubcategories($parentId)
    {
        $parent = Category::findOrFail($parentId);

        $subcategories = $parent->children()
            ->with('children')
            ->orderBy('order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'parent' => $parent,
                'subcategories' => $subcategories
            ]
        ]);
    }

    public function moveCategory(Request $request, $id)
    {
        $validated = $request->validate([
            'new_parent_id' => 'nullable|exists:categories,id',
        ]);

        $category = Category::findOrFail($id);

        // Prevent setting self as parent
        if (isset($validated['new_parent_id']) && $validated['new_parent_id'] == $category->id) {
            return response()->json([
                'success' => false,
                'message' => 'Category cannot be its own parent'
            ], 400);
        }

        // Prevent circular reference
        if (isset($validated['new_parent_id'])) {
            $descendants = $category->descendants();
            if ($descendants->contains('id', $validated['new_parent_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot move category under its own descendant'
                ], 400);
            }
        }

        $category->update(['parent_id' => $validated['new_parent_id']]);
        $category->load('parent', 'children');

        return response()->json([
            'success' => true,
            'message' => 'Category moved successfully',
            'data' => $category
        ]);
    }

    public function getCategoryBreadcrumb($id)
    {
        $category = Category::findOrFail($id);

        $breadcrumb = $category->ancestors()->reverse()->values();
        $breadcrumb->push($category);

        return response()->json([
            'success' => true,
            'data' => $breadcrumb
        ]);
    }

    public function getCategoryDescendants($id)
    {
        $category = Category::findOrFail($id);

        $descendants = $category->descendants();

        return response()->json([
            'success' => true,
            'data' => [
                'category' => $category,
                'descendants' => $descendants,
                'total_descendants' => $descendants->count()
            ]
        ]);
    }

    /**
     * Permanently delete a category (hard delete)
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function hardDeleteCategory($id)
    {
        // Find category including soft deleted ones
        $category = Category::withTrashed()->findOrFail($id);

        // Check if category has children (including soft deleted)
        $childrenCount = Category::withTrashed()->where('parent_id', $id)->count();
        if ($childrenCount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot permanently delete category with subcategories. Delete subcategories first.'
            ], 400);
        }

        // Check if category has products
        if ($category->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot permanently delete category with associated products. Remove products first.'
            ], 400);
        }

        // Delete image if exists
        if ($category->image && Storage::disk('public')->exists($category->image)) {
            Storage::disk('public')->delete($category->image);
        }

        // Permanently delete the category
        $categoryTitle = $category->title;
        $category->forceDelete();

        return response()->json([
            'success' => true,
            'message' => "Category '{$categoryTitle}' has been permanently deleted"
        ]);
    }
}
