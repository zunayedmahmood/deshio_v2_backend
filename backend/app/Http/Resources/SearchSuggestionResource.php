<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SearchSuggestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'text' => $this->resource['text'] ?? '',
            'type' => $this->resource['type'] ?? 'unknown',
            'count' => $this->resource['count'] ?? 0,
            'meta' => $this->when(isset($this->resource['meta']), $this->resource['meta'] ?? []),
        ];
    }
}
