<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Account;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\VendorPayment;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AccountingReportController extends Controller
{
    /**
     * Textbook-style T-Account (Debit/Credit Ledger)
     * Shows all transactions for a specific account in double-entry format
     * 
     * GET /api/accounting/t-account/{accountId}
     */
    public function getTAccount(Request $request, $accountId)
    {
        $account = Account::find($accountId);
        
        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found'
            ], 404);
        }

        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        // Get transactions for this account
        $transactions = Transaction::where('account_id', $accountId);
        $this->applyStoreFilter($transactions, $request);
        
        $transactions = $transactions->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // Calculate opening balance (all transactions before date_from)
        $openingBalanceQuery = Transaction::where('account_id', $accountId)
            ->where('transaction_date', '<', $dateFrom);
        $this->applyStoreFilter($openingBalanceQuery, $request);
        
        $openingBalance = $openingBalanceQuery->sum(DB::raw('CASE WHEN type = "debit" THEN amount ELSE -amount END'));

        $debitEntries = [];
        $creditEntries = [];
        $runningBalance = $openingBalance;

        foreach ($transactions as $transaction) {
            $entry = [
                'date' => $transaction->transaction_date->format('Y-m-d'),
                'reference' => $transaction->reference_id,
                'description' => $transaction->description,
                'amount' => number_format((float)$transaction->amount, 2),
                'balance' => null
            ];

            if ($transaction->type === 'debit') {
                $runningBalance += $transaction->amount;
                $entry['balance'] = number_format((float)$runningBalance, 2);
                $debitEntries[] = $entry;
            } else {
                $runningBalance -= $transaction->amount;
                $entry['balance'] = number_format((float)$runningBalance, 2);
                $creditEntries[] = $entry;
            }
        }

        // Calculate totals
        $totalDebits = $transactions->where('type', 'debit')->sum('amount');
        $totalCredits = $transactions->where('type', 'credit')->sum('amount');
        $closingBalance = $openingBalance + $totalDebits - $totalCredits;

        return response()->json([
            'success' => true,
            'data' => [
                'account' => [
                    'id' => $account->id,
                    'account_code' => $account->account_code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'sub_type' => $account->sub_type
                ],
                'period' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ],
                'opening_balance' => number_format((float)$openingBalance, 2),
                'debit_side' => $debitEntries,
                'credit_side' => $creditEntries,
                'totals' => [
                    'total_debits' => number_format((float)$totalDebits, 2),
                    'total_credits' => number_format((float)$totalCredits, 2),
                    'closing_balance' => number_format((float)$closingBalance, 2)
                ]
            ]
        ]);
    }

    /**
     * Textbook-style Trial Balance
     * Lists all accounts with debit and credit balances
     * 
     * GET /api/accounting/trial-balance
     */
    public function getTrialBalance(Request $request)
    {
        $asOfDate = $request->input('as_of_date', now()->toDateString());

        $accounts = Account::where('is_active', true)
            ->orderBy('account_code')
            ->get();

        $accountBalances = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($accounts as $account) {
            $balanceQuery = Transaction::where('account_id', $account->id)
                ->where('transaction_date', '<=', $asOfDate)
                ->where('status', 'completed');
            $this->applyStoreFilter($balanceQuery, $request);
            
            $balance = $balanceQuery->sum(DB::raw('CASE WHEN type = "debit" THEN amount ELSE -amount END'));

            if ($balance != 0) {
                $debitBalance = $balance > 0 ? $balance : 0;
                $creditBalance = $balance < 0 ? abs($balance) : 0;

                $totalDebits += $debitBalance;
                $totalCredits += $creditBalance;

                $accountBalances[] = [
                    'account_code' => $account->account_code,
                    'account_name' => $account->name,
                    'account_type' => $account->type,
                    'debit_balance' => $debitBalance > 0 ? number_format($debitBalance, 2) : '-',
                    'credit_balance' => $creditBalance > 0 ? number_format($creditBalance, 2) : '-',
                    'raw_balance' => $balance
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'title' => 'Trial Balance',
                'as_of_date' => $asOfDate,
                'accounts' => $accountBalances,
                'totals' => [
                    'total_debits' => number_format($totalDebits, 2),
                    'total_credits' => number_format($totalCredits, 2),
                    'difference' => number_format($totalDebits - $totalCredits, 2),
                    'is_balanced' => abs($totalDebits - $totalCredits) < 0.01
                ]
            ]
        ]);
    }

    /**
     * Textbook-style Income Statement (Profit & Loss)
     * 
     * GET /api/accounting/income-statement
     */
    public function getIncomeStatement(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        // Revenue: Credit entries to Sales Revenue account = Gross Sales
        $salesRevenueAccountId = Transaction::getSalesRevenueAccountId();
        $revenueQuery = Transaction::where('account_id', $salesRevenueAccountId)
            ->where('type', 'credit')
            ->where('status', 'completed')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo]);
        $this->applyStoreFilter($revenueQuery, $request);
        
        $totalRevenue = $revenueQuery->sum('amount');

        $salesCountQuery = Order::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'completed');
        $this->applyStoreFilter($salesCountQuery, $request);
        
        $salesCount = $salesCountQuery->count();

        // [ARCHITECTURAL FIX] COGS now queries the COGS Account ledger (debit = expense increase)
        // This respects manual journal entries and exchange adjustments.
        $cogsAccountId = Transaction::getCOGSAccountId();
        $cogsQuery = Transaction::where('account_id', $cogsAccountId)
            ->where('type', 'debit')
            ->where('status', 'completed')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo]);
        $this->applyStoreFilter($cogsQuery, $request);
        
        $cogs = $cogsQuery->sum('amount');

        // Gross Profit
        $grossProfit = $totalRevenue - $cogs;
        $grossProfitMargin = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;

        // Operating Expenses — query the ledger (respects manual journal entries)
        // Debit entries against expense-type accounts (excluding COGS which is already above)
        $expenseAccounts = Account::where('type', 'expense')
            ->where('is_active', true)
            ->where('account_code', '!=', '5002') // Exclude COGS account (already captured above)
            ->pluck('id');

        $expenseLedgerQuery = Transaction::whereIn('account_id', $expenseAccounts)
            ->where('type', 'debit')
            ->where('status', 'completed')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo]);
        $this->applyStoreFilter($expenseLedgerQuery, $request);
        $totalExpenses = $expenseLedgerQuery->sum('amount');

        // Category breakdown still uses the Expense model for display labelling
        // (ledger entries don't carry category names, so we use the model as supplementary)
        $expenseModelQuery = Expense::whereBetween('expense_date', [$dateFrom, $dateTo])
            ->where('status', 'approved')
            ->with('category');
        $this->applyStoreFilter($expenseModelQuery, $request);
        $expensesByCategory = $expenseModelQuery->get()
            ->groupBy('category.name')
            ->map(function ($group) {
                return [
                    'category'        => $group->first()->category->name ?? 'Uncategorized',
                    'total'           => $group->sum('total_amount'),
                    'count'           => $group->count(),
                    'formatted_total' => number_format($group->sum('total_amount'), 2),
                ];
            })->values();

        // Net Profit
        $netProfit = $grossProfit - $totalExpenses;
        $netProfitMargin = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'title' => 'Income Statement (Profit & Loss)',
                'period' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ],
                'revenue' => [
                    'sales_revenue' => number_format($totalRevenue, 2),
                    'sales_count' => $salesCount
                ],
                'cost_of_goods_sold' => number_format($cogs, 2),
                'gross_profit' => [
                    'amount' => number_format($grossProfit, 2),
                    'margin_percentage' => number_format($grossProfitMargin, 2)
                ],
                'operating_expenses' => [
                    'by_category' => $expensesByCategory,
                    'total' => number_format($totalExpenses, 2)
                ],
                'net_profit' => [
                    'amount' => number_format($netProfit, 2),
                    'margin_percentage' => number_format($netProfitMargin, 2),
                    'is_profit' => $netProfit >= 0
                ]
            ]
        ]);
    }

    /**
     * Textbook-style Balance Sheet
     * Assets = Liabilities + Equity
     * 
     * GET /api/accounting/balance-sheet
     */
    public function getBalanceSheet(Request $request)
    {
        $asOfDate = $request->input('as_of_date', now()->toDateString());

        // ASSETS
        // Cash and Bank Balances — find accounts by name containing 'Cash' within current assets
        $cashAccounts = Account::where('type', 'asset')
            ->where('sub_type', 'current_asset')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('name', 'like', '%Cash%')
                  ->orWhere('name', 'like', '%Bank%');
            })
            ->get();

        $totalCash = 0;
        $cashBreakdown = [];

        foreach ($cashAccounts as $account) {
            $balanceQuery = Transaction::where('account_id', $account->id)
                ->where('transaction_date', '<=', $asOfDate)
                ->where('status', 'completed');
            $this->applyStoreFilter($balanceQuery, $request);
            
            $balance = $balanceQuery->sum(DB::raw('CASE WHEN type = "debit" THEN amount ELSE -amount END'));
            
            if ($balance != 0) {
                $totalCash += $balance;
                $cashBreakdown[] = [
                    'account' => $account->name,
                    'balance' => number_format($balance, 2)
                ];
            }
        }

        // [ARCHITECTURAL FIX] Inventory Value: Use the Inventory Account balance from the ledger.
        // This respects manual write-offs and journal adjustments to the Inventory account.
        $inventoryAccountId = Transaction::getInventoryAccountId();
        $inventoryQuery = Transaction::where('account_id', $inventoryAccountId)
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'completed');
        $this->applyStoreFilter($inventoryQuery, $request);
        
        $inventoryValue = $inventoryQuery->sum(DB::raw('CASE WHEN type = "debit" THEN amount ELSE -amount END'));

        // Accounts Receivable (unpaid orders)
        $arQuery = Order::where('status', 'completed')
            ->where('created_at', '<=', $asOfDate)
            ->whereIn('payment_status', ['pending', 'partially_paid']);
        $this->applyStoreFilter($arQuery, $request);
        
        $accountsReceivable = $arQuery->sum('outstanding_amount');

        $totalCurrentAssets = $totalCash + $inventoryValue + $accountsReceivable;

        // LIABILITIES
        // Accounts Payable (unpaid vendor payments)
        $apQuery = DB::table('purchase_orders')
            ->where('status', 'received')
            ->where('created_at', '<=', $asOfDate)
            ->whereNotIn('payment_status', ['paid', 'fully_paid']);
        $this->applyStoreFilter($apQuery, $request);
        
        $accountsPayable = $apQuery->sum('total_amount');

        // Other liabilities from liability accounts
        $liabilityAccounts = Account::where('type', 'liability')
            ->where('is_active', true)
            ->get();

        $otherLiabilities = 0;
        $liabilityBreakdown = [];

        foreach ($liabilityAccounts as $account) {
            $balanceQuery = Transaction::where('account_id', $account->id)
                ->where('transaction_date', '<=', $asOfDate)
                ->where('status', 'completed');
            $this->applyStoreFilter($balanceQuery, $request);
            
            $balance = $balanceQuery->sum(DB::raw('CASE WHEN type = "credit" THEN amount ELSE -amount END'));
            
            if ($balance != 0) {
                $otherLiabilities += $balance;
                $liabilityBreakdown[] = [
                    'account' => $account->name,
                    'balance' => number_format($balance, 2)
                ];
            }
        }

        $totalLiabilities = $accountsPayable + $otherLiabilities;

        // EQUITY
        $equityAccounts = Account::where('type', 'equity')
            ->where('is_active', true)
            ->get();

        $ownerEquity = 0;
        $equityBreakdown = [];

        foreach ($equityAccounts as $account) {
            $balanceQuery = Transaction::where('account_id', $account->id)
                ->where('transaction_date', '<=', $asOfDate)
                ->where('status', 'completed');
            $this->applyStoreFilter($balanceQuery, $request);
            
            $balance = $balanceQuery->sum(DB::raw('CASE WHEN type = "credit" THEN amount ELSE -amount END'));
            
            if ($balance != 0) {
                $ownerEquity += $balance;
                $equityBreakdown[] = [
                    'account' => $account->name,
                    'balance' => number_format($balance, 2)
                ];
            }
        }

        // Retained Earnings (Net Profit for the period)
        $retainedEarnings = $this->calculateRetainedEarnings($asOfDate, $request);
        $totalEquity = $ownerEquity + $retainedEarnings;

        $totalLiabilitiesAndEquity = $totalLiabilities + $totalEquity;

        return response()->json([
            'success' => true,
            'data' => [
                'title' => 'Balance Sheet',
                'as_of_date' => $asOfDate,
                'assets' => [
                    'current_assets' => [
                        'cash_and_bank' => [
                            'breakdown' => $cashBreakdown,
                            'total' => number_format($totalCash, 2)
                        ],
                        'inventory' => number_format($inventoryValue, 2),
                        'accounts_receivable' => number_format($accountsReceivable, 2),
                        'total_current_assets' => number_format($totalCurrentAssets, 2)
                    ],
                    'total_assets' => number_format($totalCurrentAssets, 2)
                ],
                'liabilities' => [
                    'current_liabilities' => [
                        'accounts_payable' => number_format($accountsPayable, 2),
                        'other_liabilities' => [
                            'breakdown' => $liabilityBreakdown,
                            'total' => number_format($otherLiabilities, 2)
                        ],
                        'total_current_liabilities' => number_format($totalLiabilities, 2)
                    ],
                    'total_liabilities' => number_format($totalLiabilities, 2)
                ],
                'equity' => [
                    'owner_equity' => [
                        'breakdown' => $equityBreakdown,
                        'total' => number_format($ownerEquity, 2)
                    ],
                    'retained_earnings' => number_format($retainedEarnings, 2),
                    'total_equity' => number_format($totalEquity, 2)
                ],
                'total_liabilities_and_equity' => number_format($totalLiabilitiesAndEquity, 2),
                'accounting_equation' => [
                    'assets' => number_format($totalCurrentAssets, 2),
                    'liabilities_plus_equity' => number_format($totalLiabilitiesAndEquity, 2),
                    'difference' => number_format($totalCurrentAssets - $totalLiabilitiesAndEquity, 2),
                    'is_balanced' => abs($totalCurrentAssets - $totalLiabilitiesAndEquity) < 0.01
                ]
            ]
        ]);
    }

    /**
     * Textbook-style Cash Flow Statement
     * Built from the transaction ledger so it is always consistent with the Balance Sheet.
     *
     * GET /api/accounting/cash-flow-statement
     */
    public function getCashFlowStatement(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to',   now()->toDateString());

        // Resolve account IDs once
        $cashAccountId            = Transaction::getCashAccountId();
        $salesRevenueAccountId    = Transaction::getSalesRevenueAccountId();
        $serviceRevenueAccountId  = Transaction::getServiceRevenueAccountId();
        $cogsAccountId            = Transaction::getCOGSAccountId();
        $inventoryAccountId       = Transaction::getInventoryAccountId();

        $expenseAccountIds = Account::where('type', 'expense')
            ->where('is_active', true)
            ->where('account_code', '!=', '5002') // Exclude COGS
            ->pluck('id');

        // ── OPERATING ACTIVITIES ─────────────────────────────────────────────
        // Cash received from customers = debits to Cash from OrderPayment / ServiceOrderPayment references
        $cashFromSalesQuery = Transaction::where('account_id', $cashAccountId)
            ->where('type', 'debit')
            ->where('status', 'completed')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->whereIn('reference_type', [
                \App\Models\OrderPayment::class,
                \App\Models\ServiceOrderPayment::class,
            ]);
        $this->applyStoreFilter($cashFromSalesQuery, $request);
        $cashFromSales = $cashFromSalesQuery->sum('amount');

        // Cash paid to vendors = credits to Cash from VendorPayment references
        $cashToVendorsQuery = Transaction::where('account_id', $cashAccountId)
            ->where('type', 'credit')
            ->where('status', 'completed')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->where('reference_type', \App\Models\VendorPayment::class);
        $this->applyStoreFilter($cashToVendorsQuery, $request);
        $cashPaidToVendors = $cashToVendorsQuery->sum('amount');

        // Cash paid for expenses = credits to Cash from Expense / ExpensePayment references
        $cashForExpensesQuery = Transaction::where('account_id', $cashAccountId)
            ->where('type', 'credit')
            ->where('status', 'completed')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->whereIn('reference_type', [
                \App\Models\Expense::class,
                \App\Models\ExpensePayment::class,
            ]);
        $this->applyStoreFilter($cashForExpensesQuery, $request);
        $cashPaidForExpenses = $cashForExpensesQuery->sum('amount');

        // Cash out from refunds = credits to Cash from Refund references
        $cashRefundsQuery = Transaction::where('account_id', $cashAccountId)
            ->where('type', 'credit')
            ->where('status', 'completed')
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->whereIn('reference_type', [
                \App\Models\Refund::class,
                \App\Models\OrderPayment::class, // payment-level refunds from observer
            ])
            ->where('description', 'like', '%Refund%');
        $this->applyStoreFilter($cashRefundsQuery, $request);
        $cashPaidAsRefunds = $cashRefundsQuery->sum('amount');

        $netCashFromOperations = $cashFromSales - $cashPaidToVendors - $cashPaidForExpenses - $cashPaidAsRefunds;

        // ── INVESTING ACTIVITIES (placeholder — fixed asset purchases would go here) ──
        $netCashFromInvesting = 0;

        // ── FINANCING ACTIVITIES (placeholder — equity injections, loan repayments) ──
        $netCashFromFinancing = 0;

        // ── CASH SUMMARY ──────────────────────────────────────────────────────
        $netCashChange = $netCashFromOperations + $netCashFromInvesting + $netCashFromFinancing;
        $openingCash   = $this->getCashBalance($dateFrom, '<', $request);
        $closingCash   = $openingCash + $netCashChange;

        return response()->json([
            'success' => true,
            'data' => [
                'title'  => 'Cash Flow Statement',
                'period' => ['from' => $dateFrom, 'to' => $dateTo],
                'cash_flow_from_operating_activities' => [
                    'cash_received_from_customers' => number_format($cashFromSales, 2),
                    'cash_paid_to_vendors'          => number_format(-$cashPaidToVendors, 2),
                    'cash_paid_for_expenses'        => number_format(-$cashPaidForExpenses, 2),
                    'cash_paid_as_refunds'          => number_format(-$cashPaidAsRefunds, 2),
                    'net_cash_from_operations'      => number_format($netCashFromOperations, 2),
                ],
                'cash_flow_from_investing_activities' => [
                    'net_cash_from_investing' => number_format($netCashFromInvesting, 2),
                ],
                'cash_flow_from_financing_activities' => [
                    'net_cash_from_financing' => number_format($netCashFromFinancing, 2),
                ],
                'net_increase_decrease_in_cash' => number_format($netCashChange, 2),
                'cash_summary' => [
                    'opening_cash' => number_format($openingCash, 2),
                    'net_change'   => number_format($netCashChange, 2),
                    'closing_cash' => number_format($closingCash, 2),
                ],
            ],
        ]);
    }

    /**
     * Textbook-style Cost Sheet
     * 
     * GET /api/accounting/cost-sheet
     */
    public function getCostSheet(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());
        $productId = $request->input('product_id'); // Optional: specific product

        $query = Order::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'completed')
            ->with('items.batch.product');

        $orders = $query->get();

        // Direct Material Cost
        $directMaterialCost = $orders->sum(function($order) use ($productId) {
            return $order->items
                ->when($productId, function($items) use ($productId) {
                    return $items->where('product_id', $productId);
                })
                ->sum(function($item) {
                    return $item->quantity * ($item->batch->cost_price ?? 0);
                });
        });

        // Direct Labor Cost (if tracked - placeholder)
        $directLaborCost = 0;

        // Prime Cost
        $primeCost = $directMaterialCost + $directLaborCost;

        // Factory Overheads (portion of expenses)
        $factoryOverheads = Expense::whereBetween('expense_date', [$dateFrom, $dateTo])
            ->where('status', 'approved')
            ->whereHas('category', function($q) {
                $q->whereIn('name', ['Manufacturing', 'Factory', 'Production', 'Utilities']);
            })
            ->sum('total_amount');

        // Production/Works Cost
        $worksCost = $primeCost + $factoryOverheads;

        // Administrative Overheads
        $adminOverheads = Expense::whereBetween('expense_date', [$dateFrom, $dateTo])
            ->where('status', 'approved')
            ->whereHas('category', function($q) {
                $q->whereIn('name', ['Administrative', 'Office', 'Salaries']);
            })
            ->sum('total_amount');

        // Cost of Production
        $costOfProduction = $worksCost + $adminOverheads;

        // Selling & Distribution Overheads
        $sellingOverheads = Expense::whereBetween('expense_date', [$dateFrom, $dateTo])
            ->where('status', 'approved')
            ->whereHas('category', function($q) {
                $q->whereIn('name', ['Marketing', 'Delivery', 'Sales', 'Advertising']);
            })
            ->sum('total_amount');

        // Total Cost of Sales
        $totalCostOfSales = $costOfProduction + $sellingOverheads;

        // Sales Revenue
        $salesRevenue = $orders->sum('total_amount');

        // Profit/Loss
        $profit = $salesRevenue - $totalCostOfSales;
        $profitMargin = $salesRevenue > 0 ? ($profit / $salesRevenue) * 100 : 0;

        // Units sold
        $unitsSold = $orders->sum(function($order) use ($productId) {
            return $order->items
                ->when($productId, function($items) use ($productId) {
                    return $items->where('product_id', $productId);
                })
                ->sum('quantity');
        });

        return response()->json([
            'success' => true,
            'data' => [
                'title' => 'Cost Sheet',
                'period' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ],
                'units_sold' => $unitsSold,
                'direct_costs' => [
                    'direct_material_cost' => number_format($directMaterialCost, 2),
                    'direct_labor_cost' => number_format($directLaborCost, 2),
                    'prime_cost' => number_format($primeCost, 2)
                ],
                'factory_overheads' => number_format($factoryOverheads, 2),
                'works_cost' => number_format($worksCost, 2),
                'administrative_overheads' => number_format($adminOverheads, 2),
                'cost_of_production' => number_format($costOfProduction, 2),
                'selling_distribution_overheads' => number_format($sellingOverheads, 2),
                'total_cost_of_sales' => number_format($totalCostOfSales, 2),
                'sales_revenue' => number_format($salesRevenue, 2),
                'profit_loss' => [
                    'amount' => number_format($profit, 2),
                    'margin_percentage' => number_format($profitMargin, 2),
                    'is_profit' => $profit >= 0
                ],
                'per_unit_analysis' => $unitsSold > 0 ? [
                    'cost_per_unit' => number_format($totalCostOfSales / $unitsSold, 2),
                    'selling_price_per_unit' => number_format($salesRevenue / $unitsSold, 2),
                    'profit_per_unit' => number_format($profit / $unitsSold, 2)
                ] : null
            ]
        ]);
    }

    /**
     * Journal Entry format for transactions
     * 
     * GET /api/accounting/journal-entries
     */
    public function getJournalEntries(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $query = Transaction::whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->where('status', 'completed')
            ->with(['account', 'store', 'createdBy']);
        $this->applyStoreFilter($query, $request);
        
        $transactions = $query->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        // Group transactions by group_id (UUID) or fallback to reference pair to show double entries together
        $journalEntries = $transactions->groupBy(function ($item) {
            return $item->group_id ?? ("{$item->reference_type}-{$item->reference_id}");
        })->map(function($group) {
            $first = $group->first();
            $entry = [
                'date' => $first->transaction_date->format('Y-m-d'),
                'group_id' => $first->group_id,
                'reference_id' => $first->reference_id,
                'reference_type' => $first->reference_type,
                'description' => $first->description,
                'entries' => []
            ];

            $totalDebit = 0;
            $totalCredit = 0;

            foreach ($group as $transaction) {
                $amount = (float)$transaction->amount;
                
                $entry['entries'][] = [
                    'account_code' => $transaction->account->account_code ?? null,
                    'account_name' => $transaction->account->name ?? null,
                    'debit' => $transaction->type === 'debit' ? number_format($amount, 2) : '-',
                    'credit' => $transaction->type === 'credit' ? number_format($amount, 2) : '-'
                ];

                if ($transaction->type === 'debit') {
                    $totalDebit += $amount;
                } else {
                    $totalCredit += $amount;
                }
            }

            $entry['totals'] = [
                'debit' => number_format($totalDebit, 2),
                'credit' => number_format($totalCredit, 2),
                'is_balanced' => abs($totalDebit - $totalCredit) < 0.01
            ];

            return $entry;
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'title' => 'Journal Entries',
                'period' => [
                    'from' => $dateFrom,
                    'to' => $dateTo
                ],
                'entries' => $journalEntries,
                'total_entries' => $journalEntries->count()
            ]
        ]);
    }

    /**
     * Helper: Calculate retained earnings up to a date
     */
    private function calculateRetainedEarnings($asOfDate, Request $request)
    {
        $salesRevenueAccountId = Transaction::getSalesRevenueAccountId();
        $revenueQuery = Transaction::where('account_id', $salesRevenueAccountId)
            ->where('type', 'credit')
            ->where('status', 'completed')
            ->where('transaction_date', '<=', $asOfDate);
        $this->applyStoreFilter($revenueQuery, $request);
        $revenue = $revenueQuery->sum('amount');

        // COGS from ledger
        $cogsAccountId = Transaction::getCOGSAccountId();
        $cogsQuery = Transaction::where('account_id', $cogsAccountId)
            ->where('type', 'debit')
            ->where('status', 'completed')
            ->where('transaction_date', '<=', $asOfDate);
        $this->applyStoreFilter($cogsQuery, $request);
        $cogs = $cogsQuery->sum('amount');

        // Operating expenses from ledger (all expense accounts excluding COGS)
        $expenseAccountIds = Account::where('type', 'expense')
            ->where('is_active', true)
            ->where('account_code', '!=', '5002')
            ->pluck('id');
        $expensesQuery = Transaction::whereIn('account_id', $expenseAccountIds)
            ->where('type', 'debit')
            ->where('status', 'completed')
            ->where('transaction_date', '<=', $asOfDate);
        $this->applyStoreFilter($expensesQuery, $request);
        $expenses = $expensesQuery->sum('amount');

        return $revenue - $cogs - $expenses;
    }

    /**
     * Helper: Get cash balance from the ledger at a specific date
     */
    private function getCashBalance($date, $operator = '<=', Request $request)
    {
        // Find cash/bank accounts by correct column name (type, not account_type)
        $cashAccountIds = Account::where('type', 'asset')
            ->where('sub_type', 'current_asset')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('name', 'like', '%Cash%')
                  ->orWhere('name', 'like', '%Bank%');
            })
            ->pluck('id');

        if ($cashAccountIds->isEmpty()) {
            // Fallback: use the resolved cash account ID
            $cashAccountIds = collect([Transaction::getCashAccountId()]);
        }

        $query = Transaction::whereIn('account_id', $cashAccountIds)
            ->where('transaction_date', $operator, $date)
            ->where('status', 'completed');
        $this->applyStoreFilter($query, $request);

        return $query->sum(DB::raw('CASE WHEN type = "debit" THEN amount ELSE -amount END'));
    }

    /**
     * Helper: Apply store filter based on request
     */
    private function applyStoreFilter($query, Request $request)
    {
        $storeId = $request->input('store_id');

        if ($storeId === 'all' || $storeId === '' || $storeId === null) {
            return $query;
        }

        if ($storeId === 'global' || $storeId === 'Deshio') {
            return $query->whereNull('store_id');
        }

        return $query->where('store_id', $storeId);
    }
}
