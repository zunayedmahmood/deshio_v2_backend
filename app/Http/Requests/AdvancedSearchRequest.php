<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdvancedSearchRequest extends FormRequest
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
            'query' => 'nullable|string|min:1|max:200',
            
            // MUST filters (AND logic)
            'filters.must' => 'nullable|array',
            'filters.must.category_ids' => 'nullable|array',
            'filters.must.category_ids.*' => 'integer|exists:categories,id',
            'filters.must.vendor_ids' => 'nullable|array',
            'filters.must.vendor_ids.*' => 'integer|exists:vendors,id',
            
            // SHOULD filters (OR logic)
            'filters.should' => 'nullable|array',
            'filters.should.colors' => 'nullable|array',
            'filters.should.colors.*' => 'string|max:100',
            'filters.should.sizes' => 'nullable|array',
            'filters.should.sizes.*' => 'string|max:100',
            'filters.should.brands' => 'nullable|array',
            'filters.should.brands.*' => 'string|max:100',
            
            // MUST_NOT filters (NOT logic)
            'filters.must_not' => 'nullable|array',
            'filters.must_not.is_archived' => 'nullable|boolean',
            
            // Sorting
            'sort' => 'nullable|array',
            'sort.*.field' => 'required|string|in:name,created_at,updated_at,relevance',
            'sort.*.order' => 'required|string|in:asc,desc',
            
            // Pagination
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
            'query.min' => 'Search query must be at least 1 character.',
            'query.max' => 'Search query cannot exceed 200 characters.',
            'filters.must.category_ids.*.exists' => 'One or more selected categories do not exist.',
            'filters.must.vendor_ids.*.exists' => 'One or more selected vendors do not exist.',
            'per_page.max' => 'Maximum items per page is 100.',
            'sort.*.field.in' => 'Invalid sort field. Valid fields are: name, created_at, updated_at, relevance.',
            'sort.*.order.in' => 'Invalid sort order. Valid orders are: asc, desc.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Set defaults
        $this->merge([
            'per_page' => $this->input('per_page', 30),
            'page' => $this->input('page', 1),
        ]);
    }
}
