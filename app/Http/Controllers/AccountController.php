<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Traits\DatabaseAgnosticSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AccountController extends Controller
{
    use DatabaseAgnosticSearch;
    public function index(Request $request)
    {
        $query = Account::with(['parent', 'children']);

        // Filter by type
        if ($request->has('type')) {
            $query->byType($request->type);
        }

        // Filter by sub_type
        if ($request->has('sub_type')) {
            $query->bySubType($request->sub_type);
        }

        // Filter by active status
        if ($request->has('active')) {
            $isActive = filter_var($request->active, FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        // Filter by level
        if ($request->has('level')) {
            $query->byLevel($request->level);
        }

        // Filter to leaf accounts only (accounts with no children — the only ones that can receive transactions)
        if (filter_var($request->get('leaf_only', false), FILTER_VALIDATE_BOOLEAN)) {
            $query->whereDoesntHave('children');
        }

        // Search by name or code
        if ($request->has('search')) {
            $search = $request->search;
            $this->whereAnyLike($query, ['name', 'account_code'], $search);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'account_code');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        if ($request->has('per_page')) {
            $accounts = $query->paginate($request->per_page);
        } else {
            $accounts = $query->get();
        }

        return response()->json(['success' => true, 'data' => $accounts]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_code' => 'required|string|unique:accounts,account_code|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:asset,liability,equity,income,expense',
            'sub_type' => 'required|string|max:100',
            'parent_id' => 'nullable|exists:accounts,id',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $account = Account::create($validator->validated());

        return response()->json(['success' => true, 'data' => $account, 'message' => 'Account created successfully'], 201);
    }

    public function show($id)
    {
        $account = Account::with(['parent', 'children', 'transactions'])->findOrFail($id);

        // Get balance
        $balance = $account->getBalance();
        $account->current_balance = $balance;

        return response()->json(['success' => true, 'data' => $account]);
    }

    public function update(Request $request, $id)
    {
        $account = Account::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'account_code' => 'sometimes|required|string|max:50|unique:accounts,account_code,' . $id,
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|required|in:asset,liability,equity,income,expense',
            'sub_type' => 'sometimes|required|string|max:100',
            'parent_id' => 'nullable|exists:accounts,id',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Check if account has transactions before allowing type change
        if ($request->has('type') && $request->type !== $account->type) {
            if ($account->transactions()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change account type because it has transactions'
                ], 422);
            }
        }

        $account->update($validator->validated());

        return response()->json(['success' => true, 'data' => $account, 'message' => 'Account updated successfully']);
    }

    public function destroy($id)
    {
        $account = Account::findOrFail($id);

        // Check if account has children
        if ($account->hasChildren()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete account with sub-accounts'
            ], 422);
        }

        // Check if account has transactions
        if ($account->transactions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete account with transactions'
            ], 422);
        }

        $account->delete();

        return response()->json(['success' => true, 'message' => 'Account deleted successfully']);
    }

    public function getTree(Request $request)
    {
        $type = $request->get('type');

        $query = Account::with(['children' => function($q) {
            $q->with('children');
        }])->whereNull('parent_id');

        if ($type) {
            $query->byType($type);
        }

        $tree = $query->orderBy('account_code')->get();

        return response()->json(['success' => true, 'data' => $tree]);
    }

    public function getBalance($id, Request $request)
    {
        $account = Account::findOrFail($id);

        $storeId = $request->get('store_id');
        $endDate = $request->get('end_date');

        $balance = $account->getBalance($storeId, $endDate);
        $childrenBalance = $account->getChildrenBalance($storeId, $endDate);

        return response()->json([
            'success' => true,
            'data' => [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'account_code' => $account->account_code,
                'balance' => $balance,
                'children_balance' => $childrenBalance,
                'total_balance' => $balance + $childrenBalance,
                'store_id' => $storeId,
                'end_date' => $endDate,
            ]
        ]);
    }

    public function activate($id)
    {
        $account = Account::findOrFail($id);

        if ($account->is_active) {
            return response()->json(['success' => false, 'message' => 'Account is already active'], 422);
        }

        $account->is_active = true;
        $account->save();

        return response()->json(['success' => true, 'data' => $account, 'message' => 'Account activated successfully']);
    }

    public function deactivate($id)
    {
        $account = Account::findOrFail($id);

        if (!$account->is_active) {
            return response()->json(['success' => false, 'message' => 'Account is already inactive'], 422);
        }

        if ($account->hasChildren()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate account with sub-accounts'
            ], 422);
        }

        if ($account->transactions()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate account with transactions'
            ], 422);
        }

        $account->is_active = false;
        $account->save();

        return response()->json(['success' => true, 'data' => $account, 'message' => 'Account deactivated successfully']);
    }

    public function getStatistics(Request $request)
    {
        $type = $request->get('type');

        $query = Account::query();
        if ($type) {
            $query->byType($type);
        }

        $stats = [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'inactive' => (clone $query)->where('is_active', false)->count(),
            'by_type' => [
                'assets' => Account::assets()->count(),
                'liabilities' => Account::liabilities()->count(),
                'equity' => Account::equity()->count(),
                'income' => Account::income()->count(),
                'expenses' => Account::expenses()->count(),
            ],
            'by_sub_type' => [
                'current_assets' => Account::currentAssets()->count(),
                'fixed_assets' => Account::fixedAssets()->count(),
                'current_liabilities' => Account::currentLiabilities()->count(),
                'long_term_liabilities' => Account::longTermLiabilities()->count(),
                'sales_revenue' => Account::salesRevenue()->count(),
                'operating_expenses' => Account::operatingExpenses()->count(),
            ],
            'by_level' => Account::selectRaw('level, COUNT(*) as count')
                ->groupBy('level')
                ->orderBy('level')
                ->pluck('count', 'level'),
        ];

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function getChartOfAccounts(Request $request)
    {
        $storeId = $request->get('store_id');
        $endDate = $request->get('end_date');

        $accounts = Account::with(['parent', 'children'])
            ->active()
            ->orderBy('account_code')
            ->get();

        $chartOfAccounts = $accounts->map(function($account) use ($storeId, $endDate) {
            return [
                'id' => $account->id,
                'account_code' => $account->account_code,
                'name' => $account->name,
                'type' => $account->type,
                'sub_type' => $account->sub_type,
                'level' => $account->level,
                'parent_id' => $account->parent_id,
                'balance' => $account->getBalance($storeId, $endDate),
            ];
        });

        return response()->json(['success' => true, 'data' => $chartOfAccounts]);
    }

    public function initializeDefaultAccounts()
    {
        if (Account::count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Chart of accounts already exists'
            ], 422);
        }

        Account::createDefaultChartOfAccounts();

        return response()->json([
            'success' => true,
            'message' => 'Default chart of accounts created successfully'
        ]);
    }
}

