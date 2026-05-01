<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Carbon\Carbon;

class ExpensesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get category IDs
        $vendorPayments = ExpenseCategory::where('name', 'Vendor Payments')->first();
        $salaries = ExpenseCategory::where('name', 'Employee Salaries')->first();
        $utilities = ExpenseCategory::where('name', 'Utilities')->first();
        $logistics = ExpenseCategory::where('name', 'Logistics')->first();
        $marketing = ExpenseCategory::where('name', 'Marketing')->first();
        $maintenance = ExpenseCategory::where('name', 'Maintenance')->first();
        $insurance = ExpenseCategory::where('name', 'Insurance')->first();
        $supplies = ExpenseCategory::where('name', 'Supplies')->first();
        $travel = ExpenseCategory::where('name', 'Travel')->first();
        $training = ExpenseCategory::where('name', 'Training')->first();
        $software = ExpenseCategory::where('name', 'Software Licenses')->first();
        $bankCharges = ExpenseCategory::where('name', 'Bank Charges')->first();
        $miscellaneous = ExpenseCategory::where('name', 'Miscellaneous')->first();

        $expenses = [
            // One-time expenses
            [
                'category_id' => $vendorPayments->id,
                'store_id' => 1, // Main Store
                'created_by' => 1, // Admin user
                'expense_number' => 'EXP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
                'description' => 'Raw Material Purchase - Q4 2024: Bulk purchase of raw materials for production',
                'reference_number' => 'REF-2024-001',
                'amount' => 45000.00,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 45000.00,
                'paid_amount' => 0,
                'outstanding_amount' => 45000.00,
                'status' => 'approved',
                'payment_status' => 'unpaid',
                'expense_type' => 'vendor_payment',
                'expense_date' => Carbon::now()->subDays(5),
                'due_date' => Carbon::now()->addDays(10),
                'is_recurring' => false,
                'approved_by' => 1,
                'approved_at' => Carbon::now()->subDays(3),
                'metadata' => json_encode([
                    'vendor_name' => 'ABC Suppliers Ltd',
                    'invoice_number' => 'INV-2024-001',
                    'payment_terms' => 'Net 30 days'
                ]),
            ],
            [
                'category_id' => $utilities->id,
                'store_id' => 1,
                'created_by' => 1,
                'expense_number' => 'EXP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
                'description' => 'Electricity Bill - October 2024: Monthly electricity consumption for office and warehouse',
                'reference_number' => 'DESCO-OCT-2024',
                'amount' => 12500.00,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 12500.00,
                'paid_amount' => 12500.00,
                'outstanding_amount' => 0,
                'status' => 'completed',
                'payment_status' => 'paid',
                'expense_type' => 'utility_bill',
                'expense_date' => Carbon::now()->subDays(15),
                'due_date' => Carbon::now()->subDays(5),
                'is_recurring' => true,
                'recurrence_type' => 'monthly',
                'recurrence_interval' => 1,
                'approved_by' => 1,
                'approved_at' => Carbon::now()->subDays(12),
                'processed_at' => Carbon::now()->subDays(5),
                'completed_at' => Carbon::now()->subDays(5),
                'metadata' => json_encode([
                    'utility_provider' => 'Dhaka Electric Supply Company',
                    'account_number' => 'DESCO-123456',
                    'billing_period' => 'October 1-31, 2024'
                ]),
            ],
            [
                'category_id' => $marketing->id,
                'store_id' => 1,
                'created_by' => 1,
                'expense_number' => 'EXP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
                'description' => 'Social Media Advertising Campaign: Facebook and Instagram ads for product promotion',
                'reference_number' => 'SMAD-2024-Q4',
                'amount' => 25000.00,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 25000.00,
                'paid_amount' => 0,
                'outstanding_amount' => 25000.00,
                'status' => 'pending_approval',
                'payment_status' => 'unpaid',
                'expense_type' => 'marketing',
                'expense_date' => Carbon::now(),
                'due_date' => Carbon::now()->addDays(7),
                'is_recurring' => false,
                'metadata' => json_encode([
                    'campaign_name' => 'Winter Collection Launch',
                    'platforms' => ['Facebook', 'Instagram'],
                    'target_audience' => '18-35 years, Dhaka region'
                ]),
            ],
            [
                'category_id' => $salaries->id,
                'store_id' => 1,
                'created_by' => 1,
                'expense_number' => 'EXP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
                'description' => 'Monthly Salary Payment - October 2024: Salary payment for all employees',
                'reference_number' => 'SAL-OCT-2024',
                'amount' => 85000.00,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 85000.00,
                'paid_amount' => 0,
                'outstanding_amount' => 85000.00,
                'status' => 'approved',
                'payment_status' => 'unpaid',
                'expense_type' => 'salary_payment',
                'expense_date' => Carbon::now()->subDays(1),
                'due_date' => Carbon::now()->addDays(5),
                'is_recurring' => true,
                'recurrence_type' => 'monthly',
                'recurrence_interval' => 1,
                'approved_by' => 1,
                'approved_at' => Carbon::now()->subDays(1),
                'metadata' => json_encode([
                    'payroll_period' => 'October 1-31, 2024',
                    'number_of_employees' => 15,
                    'payment_method' => 'Bank Transfer'
                ]),
            ],
            [
                'category_id' => $logistics->id,
                'store_id' => 1,
                'created_by' => 1,
                'expense_number' => 'EXP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
                'description' => 'Courier Service Charges: Shipping charges for customer orders',
                'reference_number' => 'PATHAO-NOV-2024',
                'amount' => 8500.00,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 8500.00,
                'paid_amount' => 8500.00,
                'outstanding_amount' => 0,
                'status' => 'completed',
                'payment_status' => 'paid',
                'expense_type' => 'logistics',
                'expense_date' => Carbon::now()->subDays(10),
                'due_date' => Carbon::now()->subDays(2),
                'is_recurring' => false,
                'approved_by' => 1,
                'approved_at' => Carbon::now()->subDays(8),
                'processed_at' => Carbon::now()->subDays(2),
                'completed_at' => Carbon::now()->subDays(2),
                'metadata' => json_encode([
                    'courier_service' => 'Pathao Courier',
                    'number_of_shipments' => 45,
                    'service_type' => 'Express Delivery'
                ]),
            ],
            [
                'category_id' => $maintenance->id,
                'store_id' => 1,
                'created_by' => 1,
                'expense_number' => 'EXP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6)),
                'description' => 'Equipment Maintenance: Quarterly maintenance of production equipment',
                'reference_number' => 'MAINT-Q4-2024',
                'amount' => 18000.00,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 18000.00,
                'paid_amount' => 0,
                'outstanding_amount' => 18000.00,
                'status' => 'approved',
                'payment_status' => 'unpaid',
                'expense_type' => 'maintenance',
                'expense_date' => Carbon::now()->subDays(20),
                'due_date' => Carbon::now()->addDays(10),
                'is_recurring' => true,
                'recurrence_type' => 'quarterly',
                'recurrence_interval' => 3,
                'approved_by' => 1,
                'approved_at' => Carbon::now()->subDays(18),
                'metadata' => json_encode([
                    'maintenance_type' => 'Preventive Maintenance',
                    'equipment_list' => ['Sewing Machines', 'Packaging Equipment'],
                    'service_provider' => 'TechMaintenance Ltd'
                ]),
            ],
        ];

        foreach ($expenses as $expenseData) {
            Expense::create($expenseData);
        }
    }
}
