<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSearchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description ? $this->truncateDescription($this->description) : null,
            
            'category' => $this->when($this->relationLoaded('category') && $this->category, [
                'id' => $this->category?->id,
                'title' => $this->category?->title,
                'slug' => $this->category?->slug,
            ]),
            
            'vendor' => $this->when($this->relationLoaded('vendor') && $this->vendor, [
                'id' => $this->vendor?->id,
                'name' => $this->vendor?->name,
            ]),
            
            'fields' => $this->when($this->relationLoaded('productFields'), function() {
                $fieldsArray = [];
                foreach ($this->productFields as $productField) {
                    if ($productField->field) {
                        $fieldKey = strtolower($productField->field->title);
                        $fieldsArray[$fieldKey] = $productField->value;
                    }
                }
                return $fieldsArray;
            }),
            
            'primary_image' => $this->when($this->relationLoaded('images'), function() {
                $primaryImage = $this->images->first();
                return $primaryImage ? [
                    'id' => $primaryImage->id,
                    'url' => $primaryImage->image_path,
                    'alt_text' => $primaryImage->alt_text,
                ] : null;
            }),
            
            'relevance_score' => $this->when(isset($this->relevance_score), round($this->relevance_score, 2)),
            'match_type' => $this->when(isset($this->match_type), $this->match_type),
            
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Truncate description to first 200 characters
     */
    protected function truncateDescription(?string $description): ?string
    {
        if (!$description) {
            return null;
        }

        if (mb_strlen($description) <= 200) {
            return $description;
        }

        return mb_substr($description, 0, 200) . '...';
    }
}
