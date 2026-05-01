<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ExpenseCategory::with(['parent', 'children']);

        if ($request->has('type')) {
            $query->byType($request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        $categories = $query->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50|unique:expense_categories,code',
            'description' => 'nullable|string',
            'type' => 'required|in:operational,capital,personnel,marketing,administrative,logistics,utilities,maintenance,taxes,insurance,other',
            'parent_id' => 'nullable|exists:expense_categories,id',
            'monthly_budget' => 'nullable|numeric|min:0',
            'yearly_budget' => 'nullable|numeric|min:0',
            'requires_approval' => 'nullable|boolean',
            'approval_threshold' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $category = ExpenseCategory::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Expense category created successfully',
            'data' => $category
        ], 201);
    }

    public function show($id)
    {
        $category = ExpenseCategory::with(['parent', 'children', 'expenses'])->findOrFail($id);
        
        $category->monthly_budget_used = $category->getMonthlyBudgetUsed();
        $category->yearly_budget_used = $category->getYearlyBudgetUsed();
        $category->budget_status = $category->budget_status;

        return response()->json(['success' => true, 'data' => $category]);
    }

    public function update(Request $request, $id)
    {
        $category = ExpenseCategory::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:50|unique:expense_categories,code,' . $id,
            'description' => 'nullable|string',
            'type' => 'sometimes|in:operational,capital,personnel,marketing,administrative,logistics,utilities,maintenance,taxes,insurance,other',
            'parent_id' => 'nullable|exists:expense_categories,id',
            'monthly_budget' => 'nullable|numeric|min:0',
            'yearly_budget' => 'nullable|numeric|min:0',
            'requires_approval' => 'nullable|boolean',
            'approval_threshold' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $category->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Expense category updated successfully',
            'data' => $category
        ]);
    }

    public function destroy($id)
    {
        $category = ExpenseCategory::findOrFail($id);

        if ($category->expenses()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with existing expenses'
            ], 400);
        }

        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Expense category deleted successfully'
        ]);
    }

    public function getTree()
    {
        $tree = ExpenseCategory::getCategoryTree();

        return response()->json([
            'success' => true,
            'data' => $tree
        ]);
    }

    public function getStatistics()
    {
        $stats = [
            'total_categories' => ExpenseCategory::count(),
            'active_categories' => ExpenseCategory::active()->count(),
            'by_type' => ExpenseCategory::selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type'),
            'categories_over_budget' => ExpenseCategory::get()->filter(function($cat) {
                return $cat->isOverMonthlyBudget() || $cat->isOverYearlyBudget();
            })->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}

