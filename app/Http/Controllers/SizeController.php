<?php

namespace App\Http\Controllers;

use App\Models\VariantOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SizeController extends Controller
{
    /**
     * Get all sizes
     * GET /api/sizes
     */
    public function index(Request $request)
    {
        $query = VariantOption::where('name', 'Size')->active();

        if ($request->has('category')) {
            // If we want to filter by category in the future, we could use display_value or another field
            // For now, just return all sizes
        }

        $sizes = $query->orderBy('sort_order')->orderBy('value')->get();

        // Map to the format the frontend expects: { id, name }
        $formatted = $sizes->map(function ($size) {
            return [
                'id' => $size->id,
                'name' => $size->value,
                'category' => $size->display_value, // Using display_value as a placeholder for category if needed
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted
        ]);
    }

    /**
     * Create a new size
     * POST /api/sizes
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'category' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Check if it already exists
        $existing = VariantOption::where('name', 'Size')
            ->where('value', $request->name)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $existing->id,
                    'name' => $existing->value,
                    'category' => $existing->display_value,
                ]
            ]);
        }

        $size = VariantOption::create([
            'name' => 'Size',
            'value' => $request->name,
            'type' => 'text',
            'display_value' => $request->category,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $size->id,
                'name' => $size->value,
                'category' => $size->display_value,
            ],
            'message' => 'Size created successfully'
        ], 201);
    }
}
