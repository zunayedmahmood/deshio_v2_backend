<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\SocialMediaLiveProduct;
use App\Models\SocialMediaLiveSetting;
use App\Traits\ProductImageFallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocialMediaLiveController extends Controller
{
    use ProductImageFallback;

    public function publicFeed()
    {
        $setting = SocialMediaLiveSetting::current();

        if (!$setting->is_live) {
            return response()->json([
                'success' => true,
                'data' => [
                    'is_live' => false,
                    'displaying_now_enabled' => (bool) $setting->displaying_now_enabled,
                    'displaying_now' => null,
                    'products' => [],
                    'updated_at' => optional($setting->updated_at)->toIso8601String(),
                ],
            ]);
        }

        $items = $this->baseLiveProductQuery()->get();
        $products = $items->map(fn ($item) => $this->formatLiveItem($item))->values();
        $displayingNow = $setting->displaying_now_enabled
            ? $products->firstWhere('is_displaying_now', true)
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'is_live' => true,
                'displaying_now_enabled' => (bool) $setting->displaying_now_enabled,
                'displaying_now' => $displayingNow,
                'products' => $products,
                'updated_at' => $this->latestFeedTimestamp($setting, $items),
            ],
        ]);
    }

    public function adminIndex()
    {
        $setting = SocialMediaLiveSetting::current();
        $items = $this->baseLiveProductQuery()->get();

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => [
                    'is_live' => (bool) $setting->is_live,
                    'displaying_now_enabled' => (bool) $setting->displaying_now_enabled,
                    'updated_at' => optional($setting->updated_at)->toIso8601String(),
                ],
                'products' => $items->map(fn ($item) => $this->formatLiveItem($item))->values(),
                'updated_at' => $this->latestFeedTimestamp($setting, $items),
            ],
        ]);
    }

    public function addProduct(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $product = Product::where('id', $validated['product_id'])
            ->where('is_archived', false)
            ->firstOrFail();

        $nextSort = (int) SocialMediaLiveProduct::max('sort_order') + 1;

        SocialMediaLiveProduct::firstOrCreate(
            ['product_id' => $product->id],
            ['sort_order' => $nextSort]
        );

        return $this->adminIndex();
    }

    public function removeProduct(int $productId)
    {
        SocialMediaLiveProduct::where('product_id', $productId)->delete();

        return $this->adminIndex();
    }

    public function updateStatus(Request $request)
    {
        $validated = $request->validate([
            'is_live' => 'sometimes|boolean',
            'displaying_now_enabled' => 'sometimes|boolean',
            'confirm_stop' => 'sometimes|boolean',
        ]);

        $setting = SocialMediaLiveSetting::current();

        if (array_key_exists('is_live', $validated)) {
            $nextLive = (bool) $validated['is_live'];
            if ($setting->is_live && !$nextLive && empty($validated['confirm_stop'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stopping the live feed requires confirmation.',
                ], 422);
            }
            $setting->is_live = $nextLive;
        }

        if (array_key_exists('displaying_now_enabled', $validated)) {
            $setting->displaying_now_enabled = (bool) $validated['displaying_now_enabled'];
            if (!$setting->displaying_now_enabled) {
                SocialMediaLiveProduct::query()->update(['is_displaying_now' => false]);
            }
        }

        $setting->save();

        return $this->adminIndex();
    }

    public function setDisplayingNow(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
        ]);

        $setting = SocialMediaLiveSetting::current();
        if (!$setting->displaying_now_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Turn on Displaying Now before selecting a product.',
            ], 422);
        }

        DB::transaction(function () use ($validated) {
            SocialMediaLiveProduct::query()->update(['is_displaying_now' => false]);

            if (!empty($validated['product_id'])) {
                SocialMediaLiveProduct::where('product_id', $validated['product_id'])
                    ->update(['is_displaying_now' => true]);
            }
        });

        return $this->adminIndex();
    }

    private function baseLiveProductQuery()
    {
        return SocialMediaLiveProduct::with([
                'product.category',
                'product.images',
                'product.batches' => function ($q) {
                    $q->where('is_active', true)->where('availability', true);
                },
            ])
            ->whereHas('product', function ($q) {
                $q->where('is_archived', false)->whereNull('deleted_at');
            })
            ->orderByDesc('is_displaying_now')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    private function formatLiveItem(SocialMediaLiveProduct $item): array
    {
        $product = $item->product;
        $batches = $product->batches ?? collect();
        $totalStock = (int) $batches->sum('quantity');
        $reserved = $this->reservedQuantityForProduct((int) $product->id);
        $available = max(0, $totalStock - $reserved);
        $price = (float) ($batches->where('quantity', '>', 0)->sortBy('sell_price')->first()->sell_price
            ?? $batches->sortBy('sell_price')->first()->sell_price
            ?? 0);

        return [
            'live_item_id' => $item->id,
            'product_id' => $product->id,
            'id' => $product->id,
            'name' => $product->name,
            'base_name' => $product->base_name,
            'variation_suffix' => $product->variation_suffix,
            'sku' => $product->sku,
            'description' => $product->description,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->title ?? $product->category->name,
                'slug' => $product->category->slug ?? null,
            ] : null,
            'images' => $this->mergedActiveImages($product, ['id', 'url', 'alt_text', 'is_primary', 'sort_order']),
            'selling_price' => $price,
            'price' => $price,
            'stock_quantity' => $totalStock,
            'total_stock' => $totalStock,
            'reserved_inventory' => $reserved,
            'available_inventory' => $available,
            'in_stock' => $available > 0,
            'is_displaying_now' => (bool) $item->is_displaying_now,
            'sort_order' => (int) $item->sort_order,
            'created_at' => optional($product->created_at)->toIso8601String(),
            'updated_at' => optional($product->updated_at)->toIso8601String(),
        ];
    }

    private function reservedQuantityForProduct(int $productId): int
    {
        $statuses = array_values(array_unique(array_merge(Order::RESERVATION_STATUSES, ['confirmed'])));

        return (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.product_id', $productId)
            ->whereNull('orders.deleted_at')
            ->whereIn('orders.status', $statuses)
            ->where(function ($q) {
                $q->whereNull('orders.order_type')
                  ->orWhere('orders.order_type', '!=', 'preorder');
            })
            ->where(function ($q) {
                $q->whereNull('order_items.is_inventory_deducted')
                  ->orWhere('order_items.is_inventory_deducted', false);
            })
            ->sum('order_items.quantity');
    }

    private function latestFeedTimestamp(SocialMediaLiveSetting $setting, $items): ?string
    {
        $latest = $setting->updated_at;

        foreach ($items as $item) {
            if ($item->updated_at && (!$latest || $item->updated_at->gt($latest))) {
                $latest = $item->updated_at;
            }
            if ($item->product && $item->product->updated_at && (!$latest || $item->product->updated_at->gt($latest))) {
                $latest = $item->product->updated_at;
            }
        }

        return $latest ? $latest->toIso8601String() : null;
    }
}
