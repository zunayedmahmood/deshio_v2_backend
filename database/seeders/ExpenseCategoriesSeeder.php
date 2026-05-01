<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ExpenseCategory;

class ExpenseCategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            // Operating Expenses
            [
                'name' => 'Vendor Payments',
                'code' => 'VENDOR_PAY',
                'description' => 'Payments to suppliers and vendors for goods and services',
                'type' => 'operational',
                'parent_id' => null,
                'monthly_budget' => null,
                'yearly_budget' => null,
                'requires_approval' => true,
                'approval_threshold' => 50000.00,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Employee Salaries',
                'code' => 'EMP_SALARY',
                'description' => 'Monthly salary payments to employees',
                'type' => 'personnel',
                'parent_id' => null,
                'monthly_budget' => null,
                'yearly_budget' => null,
                'requires_approval' => true,
                'approval_threshold' => 100000.00,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Utilities',
                'code' => 'UTILITIES',
                'description' => 'Electricity, water, gas, and other utility bills',
                'type' => 'utilities',
                'parent_id' => null,
                'monthly_budget' => 50000.00,
                'yearly_budget' => 600000.00,
                'requires_approval' => true,
                'approval_threshold' => 25000.00,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Logistics',
                'code' => 'LOGISTICS',
                'description' => 'Shipping, transportation, and delivery costs',
                'type' => 'logistics',
                'parent_id' => null,
                'monthly_budget' => 75000.00,
                'yearly_budget' => 900000.00,
                'requires_approval' => true,
                'approval_threshold' => 30000.00,
                'is_active' => true,
                'sort_order' => 4,
            ],
            [
                'name' => 'Maintenance',
                'code' => 'MAINTENANCE',
                'description' => 'Equipment and facility maintenance costs',
                'type' => 'maintenance',
                'parent_id' => null,
                'monthly_budget' => 25000.00,
                'yearly_budget' => 300000.00,
                'requires_approval' => true,
                'approval_threshold' => 15000.00,
                'is_active' => true,
                'sort_order' => 5,
            ],
            [
                'name' => 'Marketing',
                'code' => 'MARKETING',
                'description' => 'Advertising, promotions, and marketing expenses',
                'type' => 'marketing',
                'parent_id' => null,
                'monthly_budget' => 100000.00,
                'yearly_budget' => 1200000.00,
                'requires_approval' => true,
                'approval_threshold' => 50000.00,
                'is_active' => true,
                'sort_order' => 6,
            ],
            [
                'name' => 'Insurance',
                'code' => 'INSURANCE',
                'description' => 'Business insurance premiums',
                'type' => 'insurance',
                'parent_id' => null,
                'monthly_budget' => 30000.00,
                'yearly_budget' => 360000.00,
                'requires_approval' => true,
                'approval_threshold' => 20000.00,
                'is_active' => true,
                'sort_order' => 7,
            ],
            [
                'name' => 'Taxes',
                'code' => 'TAXES',
                'description' => 'Business taxes and related fees',
                'type' => 'taxes',
                'parent_id' => null,
                'monthly_budget' => null,
                'yearly_budget' => null,
                'requires_approval' => true,
                'approval_threshold' => 50000.00,
                'is_active' => true,
                'sort_order' => 8,
            ],
            [
                'name' => 'Supplies',
                'code' => 'SUPPLIES',
                'description' => 'Office supplies and consumables',
                'type' => 'administrative',
                'parent_id' => null,
                'monthly_budget' => 15000.00,
                'yearly_budget' => 180000.00,
                'requires_approval' => false,
                'approval_threshold' => 5000.00,
                'is_active' => true,
                'sort_order' => 9,
            ],
            [
                'name' => 'Travel',
                'code' => 'TRAVEL',
                'description' => 'Business travel and accommodation expenses',
                'type' => 'operational',
                'parent_id' => null,
                'monthly_budget' => 50000.00,
                'yearly_budget' => 600000.00,
                'requires_approval' => true,
                'approval_threshold' => 25000.00,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'Training',
                'code' => 'TRAINING',
                'description' => 'Employee training and development costs',
                'type' => 'personnel',
                'parent_id' => null,
                'monthly_budget' => 30000.00,
                'yearly_budget' => 360000.00,
                'requires_approval' => true,
                'approval_threshold' => 15000.00,
                'is_active' => true,
                'sort_order' => 11,
            ],
            [
                'name' => 'Software Licenses',
                'code' => 'SOFTWARE',
                'description' => 'Software subscriptions and licensing fees',
                'type' => 'operational',
                'parent_id' => null,
                'monthly_budget' => 25000.00,
                'yearly_budget' => 300000.00,
                'requires_approval' => true,
                'approval_threshold' => 10000.00,
                'is_active' => true,
                'sort_order' => 12,
            ],
            [
                'name' => 'Bank Charges',
                'code' => 'BANK_CHARGES',
                'description' => 'Bank fees, transaction charges, and service fees',
                'type' => 'administrative',
                'parent_id' => null,
                'monthly_budget' => 5000.00,
                'yearly_budget' => 60000.00,
                'requires_approval' => false,
                'approval_threshold' => 2000.00,
                'is_active' => true,
                'sort_order' => 13,
            ],
            [
                'name' => 'Depreciation',
                'code' => 'DEPRECIATION',
                'description' => 'Asset depreciation expenses',
                'type' => 'other',
                'parent_id' => null,
                'monthly_budget' => null,
                'yearly_budget' => null,
                'requires_approval' => true,
                'approval_threshold' => 50000.00,
                'is_active' => true,
                'sort_order' => 14,
            ],
            [
                'name' => 'Miscellaneous',
                'code' => 'MISC',
                'description' => 'Other operating expenses not categorized elsewhere',
                'type' => 'other',
                'parent_id' => null,
                'monthly_budget' => 20000.00,
                'yearly_budget' => 240000.00,
                'requires_approval' => false,
                'approval_threshold' => 10000.00,
                'is_active' => true,
                'sort_order' => 15,
            ],
        ];

        // Create categories with proper parent relationships
        $createdCategories = [];
        foreach ($categories as $categoryData) {
            $parentId = $categoryData['parent_id'];
            if ($parentId && isset($createdCategories[$parentId - 1])) {
                $categoryData['parent_id'] = $createdCategories[$parentId - 1]->id;
            } elseif ($parentId) {
                // Skip sub-categories if parent not found yet
                continue;
            }

            $category = ExpenseCategory::create($categoryData);
            $createdCategories[] = $category;
        }

        // Create sub-categories that were skipped
        $vendorPaymentCategory = ExpenseCategory::where('name', 'Vendor Payments')->first();
        $marketingCategory = ExpenseCategory::where('name', 'Marketing')->first();

        $subCategories = [
            [
                'name' => 'Raw Materials',
                'code' => 'RAW_MAT',
                'description' => 'Payment for raw materials and components',
                'type' => 'operational',
                'parent_id' => $vendorPaymentCategory->id,
                'monthly_budget' => null,
                'yearly_budget' => null,
                'requires_approval' => true,
                'approval_threshold' => 30000.00,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Packaging',
                'code' => 'PACKAGING',
                'description' => 'Payment for packaging materials',
                'type' => 'operational',
                'parent_id' => $vendorPaymentCategory->id,
                'monthly_budget' => 20000.00,
                'yearly_budget' => 240000.00,
                'requires_approval' => true,
                'approval_threshold' => 15000.00,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Services',
                'code' => 'SERVICES',
                'description' => 'Payment for professional services',
                'type' => 'operational',
                'parent_id' => $vendorPaymentCategory->id,
                'monthly_budget' => 50000.00,
                'yearly_budget' => 600000.00,
                'requires_approval' => true,
                'approval_threshold' => 25000.00,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Digital Marketing',
                'code' => 'DIGITAL_MKT',
                'description' => 'Online advertising and digital marketing expenses',
                'type' => 'marketing',
                'parent_id' => $marketingCategory->id,
                'monthly_budget' => 40000.00,
                'yearly_budget' => 480000.00,
                'requires_approval' => true,
                'approval_threshold' => 20000.00,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Print Media',
                'code' => 'PRINT_MEDIA',
                'description' => 'Newspaper, magazine, and print advertising',
                'type' => 'marketing',
                'parent_id' => $marketingCategory->id,
                'monthly_budget' => 30000.00,
                'yearly_budget' => 360000.00,
                'requires_approval' => true,
                'approval_threshold' => 15000.00,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Events',
                'code' => 'EVENTS',
                'description' => 'Trade shows, exhibitions, and promotional events',
                'type' => 'marketing',
                'parent_id' => $marketingCategory->id,
                'monthly_budget' => 30000.00,
                'yearly_budget' => 360000.00,
                'requires_approval' => true,
                'approval_threshold' => 15000.00,
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($subCategories as $subCategoryData) {
            ExpenseCategory::create($subCategoryData);
        }
    }
}
