<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Public API - no authentication required
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'q' => 'nullable|string|min:1|max:200',
            'category_id' => 'nullable|integer|exists:categories,id',
            'category_slug' => 'nullable|string|exists:categories,slug',
            'include_subcategories' => 'nullable|boolean',
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'color' => 'nullable|string|max:100',
            'size' => 'nullable|string|max:100',
            'brand' => 'nullable|string|max:100',
            'sort_by' => 'nullable|in:relevance,name,name_desc,newest,oldest,updated',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'q.min' => 'Search query must be at least 1 character.',
            'q.max' => 'Search query cannot exceed 200 characters.',
            'category_id.exists' => 'The selected category does not exist.',
            'category_slug.exists' => 'The selected category does not exist.',
            'vendor_id.exists' => 'The selected vendor does not exist.',
            'per_page.max' => 'Maximum items per page is 100.',
            'sort_by.in' => 'Invalid sort option. Valid options are: relevance, name, name_desc, newest, oldest, updated.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set defaults
        $this->merge([
            'include_subcategories' => $this->input('include_subcategories', true),
            'sort_by' => $this->input('sort_by', 'relevance'),
            'per_page' => $this->input('per_page', 20),
            'page' => $this->input('page', 1),
        ]);
    }
}
