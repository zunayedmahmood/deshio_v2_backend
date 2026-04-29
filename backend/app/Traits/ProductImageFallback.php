<?php

namespace App\Traits;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Provides SKU-group image fallback + merging.
 */
trait ProductImageFallback
{
    /**
     * Build a sorted, primary-correct image list for a product.
     *
     * Rules (no merging — eliminates duplication):
     *  - If the product has its OWN active images  → return only those.
     *  - If the product has NO own images           → return the base/core images
     *                                                  (from findCoreImagesForSkuGroup).
     *  - Base products always return only their own images.
     *  - is_primary values are taken verbatim from the DB.
     *    The ONLY modification: if no image in the chosen set has is_primary=true,
     *    the first image in sort order is marked primary in the response (not persisted).
     *
     * @param  Product  $product
     * @param  array<int,string>  $mapFields
     * @return array<int, array<string, mixed>>
     */
    protected function mergedActiveImages(Product $product, array $mapFields = ['id','url','alt_text','is_primary','sort_order']): array
    {
        if (!$product->relationLoaded('images')) {
            $product->load(['images']);
        }

        // Own active images, sorted by: primary first → sort_order → created_at
        $ownImages = $product->images
            ->where('is_active', true)
            ->sortBy(function ($img) {
                return [
                    ($img->is_primary ? 0 : 1),
                    (int)($img->sort_order ?? 0),
                    (string)($img->created_at ?? ''),
                ];
            })
            ->values();

        if ($ownImages->count() > 0) {
            // Variant has own images: use only those — no base images mixed in.
            $imageSet = $ownImages;
        } else {
            // No own images: fall back to core/base images for this SKU group.
            $coreImages = $this->findCoreImagesForSkuGroup($product);
            $imageSet = $coreImages->sortBy(function ($img) {
                return [
                    ($img->is_primary ? 0 : 1),
                    (int)($img->sort_order ?? 0),
                    (string)($img->created_at ?? ''),
                ];
            })->values();
        }

        if ($imageSet->isEmpty()) {
            return [];
        }

        // Determine whether any image already carries the primary flag from DB.
        $hasPrimary = $imageSet->contains(fn($img) => (bool)$img->is_primary);

        return $imageSet->values()->map(function ($img, $idx) use ($mapFields, $hasPrimary) {
            // Trust DB is_primary; only synthesise it for the first item when none is set.
            $isPrimary = $hasPrimary ? (bool)$img->is_primary : ($idx === 0);

            $out = [];
            foreach ($mapFields as $field) {
                switch ($field) {
                    case 'url':
                        $out['url'] = $img->image_url;
                        break;
                    case 'image_path':
                        $out['image_path'] = $img->image_path;
                        break;
                    case 'is_primary':
                        $out['is_primary'] = $isPrimary;
                        break;
                    default:
                        $out[$field] = $img->{$field} ?? null;
                }
            }
            return $out;
        })->toArray();
    }

    /**
     * Find the best "core" image set for the SKU group.
     */
    protected function findCoreImagesForSkuGroup(Product $product): Collection
    {
        $sku = (string)($product->sku ?? '');
        if ($sku === '') return collect();

        $candidates = Product::with(['images' => function ($q) {
                $q->where('is_active', true)->orderBy('is_primary', 'desc')->orderBy('sort_order');
            }])
            ->where('sku', $sku)
            ->get();

        if ($candidates->isEmpty()) return collect();

        $best = $candidates->sortBy(function ($p) {
            $imgs = $p->images ?? collect();
            $count = $imgs->count();
            $hasPrimary = $imgs->contains(fn($i) => (bool)$i->is_primary);
            return [-$count, $hasPrimary ? 0 : 1, (string)($p->created_at ?? '')];
        })->first();

        return ($best && $best->images) ? $best->images : collect();
    }
}
