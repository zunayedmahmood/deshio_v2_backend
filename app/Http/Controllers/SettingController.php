<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Support\MediaUrl;

class SettingController extends Controller
{
    private array $sectionKeys = [
        'hero',
        'featured_collections',
        'new_arrivals',
        'bannered_collections',
        'instagram_reels',
        'showcase',
    ];

    public function getHomepageSettings(Request $request)
    {
        $group = $request->query('group');
        $settings = $this->homepageSettings();

        $response = [
            'section_order' => $this->sectionOrder($settings),
        ];

        if (!$group || $group === 'hero') {
            $response['ticker'] = array_merge($this->defaultTicker(), $this->settingArray($settings, 'homepage_ticker'));
            $response['hero'] = $this->normalizeHero(array_merge($this->defaultHero(), $this->settingArray($settings, 'homepage_hero')));
        }

        if (!$group || $group === 'collections') {
            $response['collections'] = $this->resolveCollectionTiles($this->settingArray($settings, 'homepage_collections'));
        }

        if (!$group || $group === 'new_arrivals') {
            $response['new_arrivals'] = $this->resolveNewArrivals($this->settingArray($settings, 'homepage_new_arrivals'));
        }

        if (!$group || $group === 'bannered_collections') {
            $response['bannered_collections'] = $this->resolveBanneredCollections($this->settingArray($settings, 'homepage_bannered_collections'));
        }

        if (!$group || $group === 'instagram_reels') {
            $response['instagram_reels'] = array_merge($this->defaultInstagramReels(), $this->settingArray($settings, 'homepage_instagram_reels'));
        }

        if (!$group || $group === 'showcase') {
            $response['showcase'] = $this->resolveShowcase($this->settingArray($settings, 'homepage_showcase'));
        }

        return response()->json($response);
    }

    public function getAdminHomepageSettings()
    {
        $settings = $this->homepageSettings();

        return response()->json([
            'ticker' => array_merge($this->defaultTicker(), $this->settingArray($settings, 'homepage_ticker')),
            'hero' => $this->normalizeHero(array_merge($this->defaultHero(), $this->settingArray($settings, 'homepage_hero'))),
            'collections' => $this->settingArray($settings, 'homepage_collections'),
            'showcase' => $this->resolveShowcase($this->settingArray($settings, 'homepage_showcase')),
            'new_arrivals' => $this->resolveNewArrivals($this->settingArray($settings, 'homepage_new_arrivals')),
            'bannered_collections' => $this->normalizeBanneredSettings($this->settingArray($settings, 'homepage_bannered_collections')),
            'instagram_reels' => array_merge($this->defaultInstagramReels(), $this->settingArray($settings, 'homepage_instagram_reels')),
            'section_order' => $this->sectionOrder($settings),
        ]);
    }

    public function updateHomepageSettings(Request $request)
    {
        foreach (['collections', 'showcase'] as $field) {
            if ($request->input($field) === '') {
                $request->merge([$field => []]);
            }
        }

        if ($request->input('new_arrivals.product_ids') === '') {
            $newArrivals = $request->input('new_arrivals', []);
            $newArrivals['product_ids'] = [];
            $request->merge(['new_arrivals' => $newArrivals]);
        }

        $validated = $request->validate([
            'ticker' => 'nullable|array',
            'ticker.enabled' => 'nullable|string',
            'ticker.mode' => 'nullable|string|in:static,moving',
            'ticker.background_color' => 'nullable|string|max:20',
            'ticker.text_color' => 'nullable|string|max:20',
            'ticker.speed' => 'nullable|integer|min:5|max:200',
            'ticker.phrases' => 'nullable|array',
            'ticker.phrases.*' => 'nullable|string|max:255',

            'collections' => 'nullable|array',
            'collections.*.id' => 'required|integer',
            'collections.*.type' => 'required|string|in:category,collection',
            'collections.*.title' => 'nullable|string|max:255',
            'collections.*.subtitle' => 'nullable|string|max:255',
            'collections.*.show_text' => 'nullable|string',

            'showcase' => 'nullable|array',
            'showcase.*.category_id' => 'required|integer',
            'showcase.*.subcategories' => 'nullable|array',
            'showcase.*.subcategories.*' => 'integer',
            'showcase.*.product_ids_by_category' => 'nullable|array',
            'showcase.*.product_ids_by_category.*' => 'nullable|array|max:8',
            'showcase.*.product_ids_by_category.*.*' => 'integer|exists:products,id',

            'hero_images' => 'nullable|array',
            'hero_images.*' => 'nullable|image|max:5120',
            'hero_images_meta' => 'nullable|string',
            'hero_title' => 'nullable|string|max:500',
            'hero_show_title' => 'nullable|string',
            'hero_slideshow_enabled' => 'nullable|string',
            'hero_autoplay_speed' => 'nullable|integer|min:1000|max:30000',
            'hero_text_position' => 'nullable|string|in:top-left,top-right,bottom-left,bottom-right,center',
            'hero_text_color' => 'nullable|string|max:20',
            'hero_font_size' => 'nullable|integer|min:20',
            'hero_transition_type' => 'nullable|string|in:fade,slide',

            'new_arrivals' => 'nullable|array',
            'new_arrivals.enabled' => 'nullable|string',
            'new_arrivals.product_ids' => 'nullable|array|max:12',
            'new_arrivals.product_ids.*' => 'integer|exists:products,id',

            'bannered_collections' => 'nullable|array',
            'bannered_collections.*.id' => 'required|integer',
            'bannered_collections.*.type' => 'required|string|in:category,collection',
            'bannered_collections.*.title' => 'nullable|string|max:255',
            'bannered_collections.*.subtitle' => 'nullable|string|max:255',
            'bannered_collections.*.show_text' => 'nullable|string',
            'bannered_collections_images' => 'nullable|array',
            'bannered_collections_images.*' => 'nullable|image|max:5120',
            'bannered_collections_meta' => 'nullable|string',

            'instagram_reels' => 'nullable|array',
            'instagram_reels.enabled' => 'nullable|string',
            'instagram_reels.links' => 'nullable|array|max:12',
            'instagram_reels.links.*' => 'nullable|string|max:2048',

            'section_order' => 'nullable|array',
            'section_order.*' => 'string|in:hero,featured_collections,new_arrivals,bannered_collections,instagram_reels,showcase',
        ]);

        if ($request->has('ticker')) {
            $tickerData = $validated['ticker'];
            if (isset($tickerData['enabled'])) {
                $tickerData['enabled'] = filter_var($tickerData['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            $tickerData['phrases'] = array_values(array_filter($tickerData['phrases'] ?? [], fn ($phrase) => trim((string) $phrase) !== ''));
            $this->saveHomepageSetting('homepage_ticker', $tickerData);
        }

        if ($request->has('collections')) {
            $collectionsData = $validated['collections'];
            foreach ($collectionsData as &$col) {
                $col['show_text'] = filter_var($col['show_text'] ?? true, FILTER_VALIDATE_BOOLEAN);
            }
            $this->saveHomepageSetting('homepage_collections', array_values($collectionsData));
        }

        if ($request->has('showcase')) {
            $showcaseData = array_map(function ($item) {
                $productMap = $item['product_ids_by_category'] ?? [];
                $normalizedProductMap = [];
                foreach ($productMap as $categoryId => $ids) {
                    $cleanIds = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->take(8)->values()->all();
                    if (!empty($cleanIds)) {
                        $normalizedProductMap[(string) $categoryId] = $cleanIds;
                    }
                }

                return [
                    'category_id' => (int) $item['category_id'],
                    'subcategories' => collect($item['subcategories'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all(),
                    'product_ids_by_category' => $normalizedProductMap,
                ];
            }, $validated['showcase']);

            $this->saveHomepageSetting('homepage_showcase', array_values($showcaseData));
        }

        if ($request->has('new_arrivals')) {
            $newArrivalsData = $validated['new_arrivals'];
            if (isset($newArrivalsData['enabled'])) {
                $newArrivalsData['enabled'] = filter_var($newArrivalsData['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            $newArrivalsData['product_ids'] = collect($newArrivalsData['product_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->take(12)->values()->all();
            $this->saveHomepageSetting('homepage_new_arrivals', $newArrivalsData);
        }

        if ($request->has('instagram_reels')) {
            $reelsData = $validated['instagram_reels'];
            if (isset($reelsData['enabled'])) {
                $reelsData['enabled'] = filter_var($reelsData['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            $reelsData['links'] = array_values(array_filter($reelsData['links'] ?? [], fn ($link) => trim((string) $link) !== ''));
            $this->saveHomepageSetting('homepage_instagram_reels', $reelsData);
        }

        if ($request->has('section_order')) {
            $this->saveHomepageSetting('homepage_section_order', array_values($validated['section_order']));
        }

        if ($request->has('bannered_collections_meta')) {
            $this->saveHomepageSetting('homepage_bannered_collections', $this->buildBanneredCollections($request));
        }

        if ($this->hasHeroPayload($request)) {
            $this->saveHomepageSetting('homepage_hero', $this->buildHero($request));
        }

        return response()->json(['message' => 'Homepage settings updated successfully']);
    }

    private function homepageSettings()
    {
        return Setting::where('group', 'homepage')->get()->mapWithKeys(fn ($setting) => [$setting->key => $setting->value]);
    }

    private function settingArray($settings, string $key, array $fallback = []): array
    {
        $value = $settings->get($key, $fallback);
        return is_array($value) ? $value : $fallback;
    }

    private function sectionOrder($settings): array
    {
        $order = array_values(array_filter(
            $this->settingArray($settings, 'homepage_section_order', $this->sectionKeys),
            fn ($key) => in_array($key, $this->sectionKeys, true)
        ));

        foreach ($this->sectionKeys as $key) {
            if (!in_array($key, $order, true)) {
                $order[] = $key;
            }
        }

        return $order;
    }

    private function saveHomepageSetting(string $key, array $value): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => 'homepage']
        );
    }

    private function defaultTicker(): array
    {
        return [
            'enabled' => false,
            'mode' => 'moving',
            'background_color' => '#111111',
            'text_color' => '#ffffff',
            'speed' => 40,
            'phrases' => [],
        ];
    }

    private function defaultHero(): array
    {
        return [
            'images' => [],
            'title' => '',
            'show_title' => true,
            'slideshow_enabled' => true,
            'autoplay_speed' => 5000,
            'text_position' => 'center',
            'text_color' => '#ffffff',
            'font_size' => 84,
            'transition_type' => 'fade',
        ];
    }

    private function defaultInstagramReels(): array
    {
        return [
            'enabled' => false,
            'links' => [],
        ];
    }

    private function normalizeImagePayload($image): ?array
    {
        if (is_string($image)) {
            $pathOrUrl = $image;
            $path = null;
        } elseif (is_array($image)) {
            $pathOrUrl = $image['path'] ?? $image['url'] ?? null;
            $path = $image['path'] ?? null;
        } else {
            return null;
        }

        if (!$pathOrUrl) {
            return null;
        }

        $storedPayload = MediaUrl::imagePayload($pathOrUrl);
        if ($storedPayload) {
            return $storedPayload;
        }

        $url = MediaUrl::toPublicUrl($pathOrUrl);
        if (!$url) {
            return null;
        }

        return [
            'url' => $url,
            'path' => MediaUrl::storedPath($path) ?: null,
        ];
    }

    private function normalizeHero(array $hero): array
    {
        $hero['images'] = collect($hero['images'] ?? [])
            ->map(fn ($image) => $this->normalizeImagePayload($image))
            ->filter()
            ->values()
            ->all();

        return $hero;
    }

    private function normalizeBanneredSettings(array $items): array
    {
        return collect($items)->map(function ($item) {
            if (!empty($item['override_image'])) {
                $item['override_image'] = $this->normalizeImagePayload($item['override_image']);
            }

            return $item;
        })->values()->all();
    }

    private function resolveCollectionTiles(array $collectionsSetting): array
    {
        if (empty($collectionsSetting)) return [];

        $idsByType = collect($collectionsSetting)->groupBy('type');
        $categories = $idsByType->has('category')
            ? Category::whereIn('id', $idsByType->get('category')->pluck('id')->all())->get()->keyBy('id')
            : collect();
        $collections = $idsByType->has('collection')
            ? Collection::whereIn('id', $idsByType->get('collection')->pluck('id')->all())->get()->keyBy('id')
            : collect();

        $response = [];
        foreach ($collectionsSetting as $item) {
            $type = $item['type'] ?? 'category';
            $id = (int) ($item['id'] ?? 0);

            if ($type === 'category' && isset($categories[$id])) {
                $cat = $categories[$id];
                $response[] = [
                    'id' => $cat->id,
                    'type' => 'category',
                    'title' => !empty($item['title']) ? $item['title'] : $cat->title,
                    'subtitle' => $item['subtitle'] ?? 'Explore Category',
                    'image' => $cat->thumbnail_url ?: $cat->image_url ?: $cat->banner_url,
                    'href' => '/e-commerce/' . ($cat->slug ?? $cat->id),
                    'show_text' => filter_var($item['show_text'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ];
            } elseif ($type === 'collection' && isset($collections[$id])) {
                $col = $collections[$id];
                $response[] = [
                    'id' => $col->id,
                    'type' => 'collection',
                    'title' => !empty($item['title']) ? $item['title'] : $col->name,
                    'subtitle' => $item['subtitle'] ?? 'View Collection',
                    'image' => $col->thumbnail_url ?: $col->banner_url,
                    'href' => '/e-commerce/collections/' . ($col->slug ?? $col->id),
                    'show_text' => filter_var($item['show_text'] ?? true, FILTER_VALIDATE_BOOLEAN),
                ];
            }
        }

        return $response;
    }

    private function resolveBanneredCollections(array $banneredSetting): array
    {
        if (empty($banneredSetting)) return [];

        $idsByType = collect($banneredSetting)->groupBy('type');
        $categories = $idsByType->has('category')
            ? Category::whereIn('id', $idsByType->get('category')->pluck('id')->all())->get()->keyBy('id')
            : collect();
        $collections = $idsByType->has('collection')
            ? Collection::whereIn('id', $idsByType->get('collection')->pluck('id')->all())->get()->keyBy('id')
            : collect();

        $response = [];
        foreach ($banneredSetting as $item) {
            $type = $item['type'] ?? 'category';
            $id = (int) ($item['id'] ?? 0);
            $base = [
                'id' => $id,
                'type' => $type,
                'show_text' => filter_var($item['show_text'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'override_image' => $this->normalizeImagePayload($item['override_image'] ?? null),
            ];

            if ($type === 'category' && isset($categories[$id])) {
                $cat = $categories[$id];
                $base['title'] = !empty($item['title']) ? $item['title'] : $cat->title;
                $base['subtitle'] = $item['subtitle'] ?? '';
                $base['image'] = !empty($base['override_image']['url']) ? $base['override_image']['url'] : ($cat->banner_url ?: $cat->thumbnail_url ?: $cat->image_url);
                $base['href'] = '/e-commerce/' . ($cat->slug ?? $cat->id);
                $response[] = $base;
            } elseif ($type === 'collection' && isset($collections[$id])) {
                $col = $collections[$id];
                $base['title'] = !empty($item['title']) ? $item['title'] : $col->name;
                $base['subtitle'] = $item['subtitle'] ?? '';
                $base['image'] = !empty($base['override_image']['url']) ? $base['override_image']['url'] : ($col->banner_url ?: $col->thumbnail_url);
                $base['href'] = '/e-commerce/collections/' . ($col->slug ?? $col->id);
                $response[] = $base;
            }
        }

        return $response;
    }

    private function resolveNewArrivals(array $setting): array
    {
        $newArrivals = array_merge([
            'enabled' => false,
            'product_ids' => [],
        ], $setting);

        $ids = collect($newArrivals['product_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->take(12)->values()->all();

        if (!empty($ids)) {
            $newArrivals['product_ids'] = $ids;
            $newArrivals['products'] = $this->productsByIds($ids)->values();
        } else {
            $newArrivals['products'] = collect();
        }

        return $newArrivals;
    }

    private function resolveShowcase(array $showcase): array
    {
        if (empty($showcase)) return [];

        $allProductIds = collect($showcase)
            ->flatMap(fn ($item) => collect($item['product_ids_by_category'] ?? [])->flatMap(fn ($ids) => $ids))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $products = $this->productsByIds($allProductIds)->keyBy('id');

        return array_values(array_map(function ($item) use ($products) {
            $map = $item['product_ids_by_category'] ?? [];
            $productData = [];

            foreach ($map as $categoryId => $ids) {
                $productData[(string) $categoryId] = collect($ids)
                    ->map(fn ($id) => $products->get((int) $id))
                    ->filter()
                    ->values()
                    ->all();
            }

            return [
                'category_id' => (int) ($item['category_id'] ?? 0),
                'subcategories' => collect($item['subcategories'] ?? [])->map(fn ($id) => (int) $id)->filter()->values()->all(),
                'product_ids_by_category' => $map,
                'product_data_by_category' => $productData,
            ];
        }, $showcase));
    }

    private function productsByIds(array $ids)
    {
        if (empty($ids)) return collect();

        $products = Product::with(['images', 'category', 'batches' => function ($q) {
                $q->where('is_active', true)->where('availability', true)->orderBy('sell_price', 'asc');
            }])
            ->whereIn('id', $ids)
            ->where('is_archived', false)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn ($id) => $products->get((int) $id))
            ->filter()
            ->map(fn ($product) => $this->formatProductForHome($product));
    }

    private function formatProductForHome(Product $product): array
    {
        $activeBatches = $product->batches;
        $cheapestBatch = $activeBatches->where('quantity', '>', 0)->sortBy('sell_price')->first()
            ?? $activeBatches->sortBy('sell_price')->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'base_name' => $product->base_name,
            'variation_suffix' => $product->variation_suffix,
            'sku' => $product->sku,
            'description' => $product->description,
            'selling_price' => $cheapestBatch ? (float) $cheapestBatch->sell_price : 0,
            'price' => $cheapestBatch ? (float) $cheapestBatch->sell_price : 0,
            'stock_quantity' => (int) $activeBatches->sum('quantity'),
            'images' => $product->images->where('is_active', true)->sortBy('sort_order')->take(2)->map(function ($image) {
                return [
                    'id' => $image->id,
                    'url' => $image->image_url,
                    'alt_text' => $image->alt_text,
                    'is_primary' => (bool) $image->is_primary,
                ];
            })->values(),
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->title,
                'title' => $product->category->title,
                'slug' => $product->category->slug,
            ] : null,
            'in_stock' => $activeBatches->sum('quantity') > 0,
            'has_variants' => $product->base_name ? Product::where('base_name', $product->base_name)->count() > 1 : false,
        ];
    }

    private function buildBanneredCollections(Request $request): array
    {
        $currentBannered = Setting::where('key', 'homepage_bannered_collections')->first()?->value ?? [];
        $meta = json_decode($request->input('bannered_collections_meta'), true) ?: [];
        $uploadedFiles = $request->file('bannered_collections_images') ?? [];
        $newBannered = [];

        foreach ($meta as $item) {
            $banneredItem = [
                'id' => (int) ($item['id'] ?? 0),
                'type' => $item['type'] ?? 'category',
                'title' => $item['title'] ?? '',
                'subtitle' => $item['subtitle'] ?? '',
                'show_text' => filter_var($item['show_text'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];

            if (($item['image_type'] ?? null) === 'existing') {
                $banneredItem['override_image'] = $this->normalizeImagePayload($item['override_image'] ?? null);
            } elseif (($item['image_type'] ?? null) === 'new' && isset($uploadedFiles[$item['fileIndex']])) {
                $file = $uploadedFiles[$item['fileIndex']];
                $path = $file->store('homepage/bannered', 'public');
                $banneredItem['override_image'] = MediaUrl::imagePayload($path);
            } else {
                $banneredItem['override_image'] = null;
            }

            if ($banneredItem['id'] > 0) {
                $newBannered[] = $banneredItem;
            }
        }

        $oldPaths = collect($currentBannered)->pluck('override_image.path')->map(fn ($path) => MediaUrl::storedPath($path))->filter()->all();
        $newPaths = collect($newBannered)->pluck('override_image.path')->map(fn ($path) => MediaUrl::storedPath($path))->filter()->all();
        foreach (array_diff($oldPaths, $newPaths) as $path) {
            Storage::disk('public')->delete($path);
        }

        return $newBannered;
    }

    private function hasHeroPayload(Request $request): bool
    {
        return $request->has('hero_images')
            || $request->has('hero_images_meta')
            || $request->has('hero_title')
            || $request->has('hero_show_title')
            || $request->has('hero_slideshow_enabled')
            || $request->has('hero_text_position')
            || $request->has('hero_transition_type');
    }

    private function buildHero(Request $request): array
    {
        $currentHero = Setting::where('key', 'homepage_hero')->first()?->value ?? $this->defaultHero();

        if ($request->has('hero_images_meta')) {
            $meta = json_decode($request->input('hero_images_meta'), true) ?: [];
            $uploadedFiles = $request->file('hero_images') ?? [];
            $newImages = [];

            foreach ($meta as $item) {
                if (($item['type'] ?? null) === 'existing') {
                    $normalized = $this->normalizeImagePayload($item);
                    if ($normalized) {
                        $newImages[] = $normalized;
                    }
                } elseif (($item['type'] ?? null) === 'new' && isset($uploadedFiles[$item['fileIndex']])) {
                    $file = $uploadedFiles[$item['fileIndex']];
                    $path = $file->store('homepage', 'public');
                    $newImages[] = MediaUrl::imagePayload($path);
                }
            }

            $oldPaths = collect($currentHero['images'] ?? [])->pluck('path')->map(fn ($path) => MediaUrl::storedPath($path))->filter()->all();
            $newPaths = collect($newImages)->pluck('path')->map(fn ($path) => MediaUrl::storedPath($path))->filter()->all();
            foreach (array_diff($oldPaths, $newPaths) as $path) {
                Storage::disk('public')->delete($path);
            }

            $currentHero['images'] = array_values(array_filter($newImages, fn ($img) => is_array($img) && !empty($img['url'])));
        }

        if ($request->has('hero_title')) $currentHero['title'] = $request->input('hero_title');
        if ($request->has('hero_show_title')) $currentHero['show_title'] = filter_var($request->input('hero_show_title'), FILTER_VALIDATE_BOOLEAN);
        if ($request->has('hero_slideshow_enabled')) $currentHero['slideshow_enabled'] = filter_var($request->input('hero_slideshow_enabled'), FILTER_VALIDATE_BOOLEAN);
        if ($request->has('hero_autoplay_speed')) $currentHero['autoplay_speed'] = (int) $request->input('hero_autoplay_speed');
        if ($request->has('hero_text_position')) $currentHero['text_position'] = $request->input('hero_text_position');
        if ($request->has('hero_text_color')) $currentHero['text_color'] = $request->input('hero_text_color');
        if ($request->has('hero_font_size')) $currentHero['font_size'] = (int) $request->input('hero_font_size');
        if ($request->has('hero_transition_type')) $currentHero['transition_type'] = $request->input('hero_transition_type');

        return array_merge($this->defaultHero(), $currentHero);
    }
}
