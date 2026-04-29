<?php

namespace App\Http\Controllers;

use App\Models\Field;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FieldController extends Controller
{
    /**
     * Get all fields with optional filters
     * 
     * GET /api/fields
     * Query params: type, is_active, is_required, per_page
     */
    public function index(Request $request)
    {
        try {
            $query = Field::query();

            // Filter by type
            if ($request->has('type')) {
                $query->byType($request->type);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Filter by required status
            if ($request->has('is_required')) {
                $query->where('is_required', $request->boolean('is_required'));
            }

            // Default ordering
            $query->ordered();

            // Pagination
            $perPage = $request->input('per_page', 50);
            $fields = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $fields,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch fields: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get active fields only (for form rendering)
     * 
     * GET /api/fields/active
     */
    public function getActiveFields(Request $request)
    {
        try {
            $fields = Field::active()
                ->ordered()
                ->get();

            return response()->json([
                'success' => true,
                'data' => $fields,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch active fields: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get fields by type
     * 
     * GET /api/fields/by-type/{type}
     */
    public function getByType(Request $request, $type)
    {
        try {
            $fields = Field::byType($type)
                ->active()
                ->ordered()
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'type' => $type,
                    'count' => $fields->count(),
                    'fields' => $fields,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch fields by type: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single field
     * 
     * GET /api/fields/{id}
     */
    public function show($id)
    {
        try {
            $field = Field::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $field,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Field not found',
            ], 404);
        }
    }

    /**
     * Create new field
     * 
     * POST /api/fields
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255|unique:fields,title',
            'type' => [
                'required',
                'string',
                Rule::in([
                    'text',
                    'textarea',
                    'number',
                    'email',
                    'url',
                    'tel',
                    'date',
                    'datetime',
                    'time',
                    'select',
                    'radio',
                    'checkbox',
                    'file',
                    'image',
                    'color',
                    'range',
                ])
            ],
            'description' => 'nullable|string',
            'is_required' => 'boolean',
            'default_value' => 'nullable|string',
            'options' => 'nullable|array',
            'options.*' => 'string',
            'validation_rules' => 'nullable|string',
            'placeholder' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();
        try {
            // If no order specified, set to max + 1
            $order = $request->order ?? (Field::max('order') + 1);

            $field = Field::create([
                'title' => $request->title,
                'type' => $request->type,
                'description' => $request->description,
                'is_required' => $request->boolean('is_required', false),
                'default_value' => $request->default_value,
                'options' => $request->options,
                'validation_rules' => $request->validation_rules,
                'placeholder' => $request->placeholder,
                'order' => $order,
                'is_active' => $request->boolean('is_active', true),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Field created successfully',
                'data' => $field,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create field: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update field
     * 
     * PUT /api/fields/{id}
     */
    public function update(Request $request, $id)
    {
        $field = Field::findOrFail($id);

        $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('fields', 'title')->ignore($field->id)
            ],
            'type' => [
                'required',
                'string',
                Rule::in([
                    'text',
                    'textarea',
                    'number',
                    'email',
                    'url',
                    'tel',
                    'date',
                    'datetime',
                    'time',
                    'select',
                    'radio',
                    'checkbox',
                    'file',
                    'image',
                    'color',
                    'range',
                ])
            ],
            'description' => 'nullable|string',
            'is_required' => 'boolean',
            'default_value' => 'nullable|string',
            'options' => 'nullable|array',
            'options.*' => 'string',
            'validation_rules' => 'nullable|string',
            'placeholder' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        DB::beginTransaction();
        try {
            $field->update($request->all());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Field updated successfully',
                'data' => $field->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update field: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete field
     * 
     * DELETE /api/fields/{id}
     */
    public function destroy($id)
    {
        DB::beginTransaction();
        try {
            $field = Field::findOrFail($id);

            // Check if field is used by products
            $productFieldCount = $field->productFields()->count();
            if ($productFieldCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete field. It is used by {$productFieldCount} product(s).",
                ], 400);
            }

            // Check if field is used by services
            $serviceFieldCount = $field->serviceFields()->count();
            if ($serviceFieldCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete field. It is used by {$serviceFieldCount} service(s).",
                ], 400);
            }

            $field->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Field deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete field: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate field
     * 
     * PATCH /api/fields/{id}/activate
     */
    public function activate($id)
    {
        try {
            $field = Field::findOrFail($id);
            $field->update(['is_active' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Field activated successfully',
                'data' => $field,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate field: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deactivate field
     * 
     * PATCH /api/fields/{id}/deactivate
     */
    public function deactivate($id)
    {
        try {
            $field = Field::findOrFail($id);
            $field->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Field deactivated successfully',
                'data' => $field,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate field: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reorder fields
     * 
     * PATCH /api/fields/reorder
     * Body: { orders: [ {id: 1, order: 0}, {id: 2, order: 1} ] }
     */
    public function reorder(Request $request)
    {
        $request->validate([
            'orders' => 'required|array',
            'orders.*.id' => 'required|exists:fields,id',
            'orders.*.order' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($request->orders as $orderData) {
                Field::where('id', $orderData['id'])
                    ->update(['order' => $orderData['order']]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Fields reordered successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder fields: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get field statistics
     * 
     * GET /api/fields/statistics
     */
    public function getStatistics()
    {
        try {
            $total = Field::count();
            $active = Field::active()->count();
            $inactive = $total - $active;
            $required = Field::required()->count();

            // Count by type
            $byType = Field::select('type', DB::raw('count(*) as count'))
                ->groupBy('type')
                ->pluck('count', 'type');

            // Most used fields (by product_fields count)
            $mostUsed = Field::select('fields.*', DB::raw('COUNT(product_fields.id) as usage_count'))
                ->leftJoin('product_fields', 'fields.id', '=', 'product_fields.field_id')
                ->groupBy('fields.id')
                ->orderByDesc('usage_count')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'active' => $active,
                    'inactive' => $inactive,
                    'required' => $required,
                    'by_type' => $byType,
                    'most_used' => $mostUsed,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available field types
     * 
     * GET /api/fields/types
     */
    public function getTypes()
    {
        $types = [
            [
                'value' => 'text',
                'label' => 'Text',
                'description' => 'Single line text input',
                'supports_options' => false,
            ],
            [
                'value' => 'textarea',
                'label' => 'Textarea',
                'description' => 'Multi-line text input',
                'supports_options' => false,
            ],
            [
                'value' => 'number',
                'label' => 'Number',
                'description' => 'Numeric input',
                'supports_options' => false,
            ],
            [
                'value' => 'email',
                'label' => 'Email',
                'description' => 'Email address input',
                'supports_options' => false,
            ],
            [
                'value' => 'url',
                'label' => 'URL',
                'description' => 'Website URL input',
                'supports_options' => false,
            ],
            [
                'value' => 'tel',
                'label' => 'Telephone',
                'description' => 'Phone number input',
                'supports_options' => false,
            ],
            [
                'value' => 'date',
                'label' => 'Date',
                'description' => 'Date picker',
                'supports_options' => false,
            ],
            [
                'value' => 'datetime',
                'label' => 'Date & Time',
                'description' => 'Date and time picker',
                'supports_options' => false,
            ],
            [
                'value' => 'time',
                'label' => 'Time',
                'description' => 'Time picker',
                'supports_options' => false,
            ],
            [
                'value' => 'select',
                'label' => 'Select Dropdown',
                'description' => 'Dropdown selection',
                'supports_options' => true,
            ],
            [
                'value' => 'radio',
                'label' => 'Radio Button',
                'description' => 'Single choice radio buttons',
                'supports_options' => true,
            ],
            [
                'value' => 'checkbox',
                'label' => 'Checkbox',
                'description' => 'Multiple choice checkboxes',
                'supports_options' => true,
            ],
            [
                'value' => 'file',
                'label' => 'File Upload',
                'description' => 'File upload field',
                'supports_options' => false,
            ],
            [
                'value' => 'image',
                'label' => 'Image Upload',
                'description' => 'Image upload field',
                'supports_options' => false,
            ],
            [
                'value' => 'color',
                'label' => 'Color Picker',
                'description' => 'Color selection',
                'supports_options' => false,
            ],
            [
                'value' => 'range',
                'label' => 'Range Slider',
                'description' => 'Range slider input',
                'supports_options' => false,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $types,
        ]);
    }

    /**
     * Bulk update field status
     * 
     * PATCH /api/fields/bulk/status
     * Body: { field_ids: [1, 2, 3], is_active: true }
     */
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'field_ids' => 'required|array|min:1',
            'field_ids.*' => 'exists:fields,id',
            'is_active' => 'required|boolean',
        ]);

        DB::beginTransaction();
        try {
            Field::whereIn('id', $request->field_ids)
                ->update(['is_active' => $request->is_active]);

            DB::commit();

            $status = $request->is_active ? 'activated' : 'deactivated';
            $count = count($request->field_ids);

            return response()->json([
                'success' => true,
                'message' => "{$count} field(s) {$status} successfully",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update fields: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Duplicate a field
     * 
     * POST /api/fields/{id}/duplicate
     */
    public function duplicate($id)
    {
        DB::beginTransaction();
        try {
            $field = Field::findOrFail($id);

            $newField = $field->replicate();
            $newField->title = $field->title . ' (Copy)';
            $newField->order = Field::max('order') + 1;
            $newField->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Field duplicated successfully',
                'data' => $newField,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to duplicate field: ' . $e->getMessage(),
            ], 500);
        }
    }
}
