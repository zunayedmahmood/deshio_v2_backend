<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing permissions
        Permission::query()->delete();

        $permissions = [];

        // Dashboard & Analytics
        $permissions = array_merge($permissions, $this->getDashboardPermissions());

        // User Management
        $permissions = array_merge($permissions, $this->getUserManagementPermissions());

        // Product Management
        $permissions = array_merge($permissions, $this->getProductManagementPermissions());

        // Inventory Management
        $permissions = array_merge($permissions, $this->getInventoryManagementPermissions());

        // Customer Management
        $permissions = array_merge($permissions, $this->getCustomerManagementPermissions());

        // Order Management
        $permissions = array_merge($permissions, $this->getOrderManagementPermissions());

        // Service Management
        $permissions = array_merge($permissions, $this->getServiceManagementPermissions());

        // Financial Management
        $permissions = array_merge($permissions, $this->getFinancialManagementPermissions());

        // Store Management
        $permissions = array_merge($permissions, $this->getStoreManagementPermissions());

        // System Administration
        $permissions = array_merge($permissions, $this->getSystemAdministrationPermissions());

        // Reporting & Analytics
        $permissions = array_merge($permissions, $this->getReportingPermissions());

        // Insert all permissions
        foreach ($permissions as $permission) {
            Permission::create($permission);
        }

        $this->command->info('Created ' . count($permissions) . ' permissions');
    }

    private function getDashboardPermissions(): array
    {
        return [
            [
                'title' => 'View Dashboard',
                'slug' => 'dashboard.view',
                'description' => 'Access to main dashboard',
                'module' => 'dashboard',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Analytics',
                'slug' => 'dashboard.analytics',
                'description' => 'Access to dashboard analytics and charts',
                'module' => 'dashboard',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getUserManagementPermissions(): array
    {
        return [
            // Employees
            [
                'title' => 'View Employees',
                'slug' => 'employees.view',
                'description' => 'View employee list and details',
                'module' => 'employees',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Employees',
                'slug' => 'employees.create',
                'description' => 'Create new employees',
                'module' => 'employees',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Employees',
                'slug' => 'employees.edit',
                'description' => 'Edit employee information',
                'module' => 'employees',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Employees',
                'slug' => 'employees.delete',
                'description' => 'Delete employees',
                'module' => 'employees',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Manage Employee Roles',
                'slug' => 'employees.manage_roles',
                'description' => 'Assign and remove employee roles',
                'module' => 'employees',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Employee Sessions',
                'slug' => 'employees.view_sessions',
                'description' => 'View employee login sessions',
                'module' => 'employees',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Roles & Permissions
            [
                'title' => 'View Roles',
                'slug' => 'roles.view',
                'description' => 'View roles and their permissions',
                'module' => 'roles',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Roles',
                'slug' => 'roles.create',
                'description' => 'Create new roles',
                'module' => 'roles',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Roles',
                'slug' => 'roles.edit',
                'description' => 'Edit role permissions and details',
                'module' => 'roles',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Roles',
                'slug' => 'roles.delete',
                'description' => 'Delete roles',
                'module' => 'roles',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Manage Permissions',
                'slug' => 'permissions.manage',
                'description' => 'Create, edit, and delete permissions',
                'module' => 'permissions',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getProductManagementPermissions(): array
    {
        return [
            // Products
            [
                'title' => 'View Products',
                'slug' => 'products.view',
                'description' => 'View product catalog',
                'module' => 'products',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Products',
                'slug' => 'products.create',
                'description' => 'Create new products',
                'module' => 'products',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Products',
                'slug' => 'products.edit',
                'description' => 'Edit product information',
                'module' => 'products',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Products',
                'slug' => 'products.delete',
                'description' => 'Delete products',
                'module' => 'products',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Import Products',
                'slug' => 'products.import',
                'description' => 'Import products from CSV/Excel',
                'module' => 'products',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Export Products',
                'slug' => 'products.export',
                'description' => 'Export products to CSV/Excel',
                'module' => 'products',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Manage Product Images',
                'slug' => 'products.manage_images',
                'description' => 'Upload and manage product images',
                'module' => 'products',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Manage Product Barcodes',
                'slug' => 'products.manage_barcodes',
                'description' => 'Generate and manage product barcodes',
                'module' => 'products',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Product Batches
            [
                'title' => 'View Product Batches',
                'slug' => 'product_batches.view',
                'description' => 'View product batch information',
                'module' => 'product_batches',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Product Batches',
                'slug' => 'product_batches.create',
                'description' => 'Create new product batches',
                'module' => 'product_batches',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Product Batches',
                'slug' => 'product_batches.edit',
                'description' => 'Edit product batch information',
                'module' => 'product_batches',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Product Batches',
                'slug' => 'product_batches.delete',
                'description' => 'Delete product batches',
                'module' => 'product_batches',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Categories
            [
                'title' => 'View Categories',
                'slug' => 'categories.view',
                'description' => 'View product categories',
                'module' => 'categories',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Categories',
                'slug' => 'categories.create',
                'description' => 'Create new product categories',
                'module' => 'categories',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Categories',
                'slug' => 'categories.edit',
                'description' => 'Edit product categories',
                'module' => 'categories',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Categories',
                'slug' => 'categories.delete',
                'description' => 'Delete product categories',
                'module' => 'categories',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Vendors
            [
                'title' => 'View Vendors',
                'slug' => 'vendors.view',
                'description' => 'View vendor information',
                'module' => 'vendors',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Vendors',
                'slug' => 'vendors.create',
                'description' => 'Create new vendors',
                'module' => 'vendors',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Vendors',
                'slug' => 'vendors.edit',
                'description' => 'Edit vendor information',
                'module' => 'vendors',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Vendors',
                'slug' => 'vendors.delete',
                'description' => 'Delete vendors',
                'module' => 'vendors',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getInventoryManagementPermissions(): array
    {
        return [
            // Inventory
            [
                'title' => 'View Inventory',
                'slug' => 'inventory.view',
                'description' => 'View inventory levels and details',
                'module' => 'inventory',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Adjust Inventory',
                'slug' => 'inventory.adjust',
                'description' => 'Manually adjust inventory levels',
                'module' => 'inventory',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Inventory Movements',
                'slug' => 'inventory.view_movements',
                'description' => 'View inventory movement history',
                'module' => 'inventory',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Product Dispatches
            [
                'title' => 'View Product Dispatches',
                'slug' => 'product_dispatches.view',
                'description' => 'View product dispatch records',
                'module' => 'product_dispatches',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Product Dispatches',
                'slug' => 'product_dispatches.create',
                'description' => 'Create new product dispatches',
                'module' => 'product_dispatches',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Product Dispatches',
                'slug' => 'product_dispatches.edit',
                'description' => 'Edit product dispatch information',
                'module' => 'product_dispatches',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Product Dispatches',
                'slug' => 'product_dispatches.delete',
                'description' => 'Delete product dispatches',
                'module' => 'product_dispatches',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Inventory Rebalancing
            [
                'title' => 'View Inventory Rebalancing',
                'slug' => 'inventory_rebalancing.view',
                'description' => 'View inventory rebalancing records',
                'module' => 'inventory_rebalancing',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Inventory Rebalancing',
                'slug' => 'inventory_rebalancing.create',
                'description' => 'Create inventory rebalancing entries',
                'module' => 'inventory_rebalancing',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Approve Inventory Rebalancing',
                'slug' => 'inventory_rebalancing.approve',
                'description' => 'Approve inventory rebalancing requests',
                'module' => 'inventory_rebalancing',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getCustomerManagementPermissions(): array
    {
        return [
            [
                'title' => 'View Customers',
                'slug' => 'customers.view',
                'description' => 'View customer list and details',
                'module' => 'customers',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Customers',
                'slug' => 'customers.create',
                'description' => 'Create new customers',
                'module' => 'customers',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Customers',
                'slug' => 'customers.edit',
                'description' => 'Edit customer information',
                'module' => 'customers',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Customers',
                'slug' => 'customers.delete',
                'description' => 'Delete customers',
                'module' => 'customers',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Import Customers',
                'slug' => 'customers.import',
                'description' => 'Import customers from CSV/Excel',
                'module' => 'customers',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Export Customers',
                'slug' => 'customers.export',
                'description' => 'Export customers to CSV/Excel',
                'module' => 'customers',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Customer Purchase History',
                'slug' => 'customers.view_purchase_history',
                'description' => 'View customer purchase history and analytics',
                'module' => 'customers',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getOrderManagementPermissions(): array
    {
        return [
            // Orders
            [
                'title' => 'View Orders',
                'slug' => 'orders.view',
                'description' => 'View order list and details',
                'module' => 'orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Orders',
                'slug' => 'orders.create',
                'description' => 'Create new orders',
                'module' => 'orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Orders',
                'slug' => 'orders.edit',
                'description' => 'Edit order information',
                'module' => 'orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Orders',
                'slug' => 'orders.delete',
                'description' => 'Delete orders',
                'module' => 'orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Process Orders',
                'slug' => 'orders.process',
                'description' => 'Process and fulfill orders',
                'module' => 'orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Cancel Orders',
                'slug' => 'orders.cancel',
                'description' => 'Cancel orders',
                'module' => 'orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Order Payments
            [
                'title' => 'View Order Payments',
                'slug' => 'order_payments.view',
                'description' => 'View order payment records',
                'module' => 'order_payments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Process Order Payments',
                'slug' => 'order_payments.process',
                'description' => 'Process and record order payments',
                'module' => 'order_payments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Refund Order Payments',
                'slug' => 'order_payments.refund',
                'description' => 'Process refunds for order payments',
                'module' => 'order_payments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Manage Installment Payments',
                'slug' => 'order_payments.manage_installments',
                'description' => 'Setup and manage installment payment plans',
                'module' => 'order_payments',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Shipments
            [
                'title' => 'View Shipments',
                'slug' => 'shipments.view',
                'description' => 'View shipment records',
                'module' => 'shipments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Shipments',
                'slug' => 'shipments.create',
                'description' => 'Create new shipments',
                'module' => 'shipments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Shipments',
                'slug' => 'shipments.edit',
                'description' => 'Edit shipment information',
                'module' => 'shipments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Shipments',
                'slug' => 'shipments.delete',
                'description' => 'Delete shipments',
                'module' => 'shipments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Update Shipment Status',
                'slug' => 'shipments.update_status',
                'description' => 'Update shipment delivery status',
                'module' => 'shipments',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Returns & Refunds
            [
                'title' => 'View Product Returns',
                'slug' => 'product_returns.view',
                'description' => 'View product return requests',
                'module' => 'product_returns',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Process Product Returns',
                'slug' => 'product_returns.process',
                'description' => 'Process and approve product returns',
                'module' => 'product_returns',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Refunds',
                'slug' => 'refunds.view',
                'description' => 'View refund records',
                'module' => 'refunds',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Process Refunds',
                'slug' => 'refunds.process',
                'description' => 'Process and approve refunds',
                'module' => 'refunds',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getServiceManagementPermissions(): array
    {
        return [
            // Services
            [
                'title' => 'View Services',
                'slug' => 'services.view',
                'description' => 'View service catalog',
                'module' => 'services',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Services',
                'slug' => 'services.create',
                'description' => 'Create new services',
                'module' => 'services',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Services',
                'slug' => 'services.edit',
                'description' => 'Edit service information',
                'module' => 'services',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Services',
                'slug' => 'services.delete',
                'description' => 'Delete services',
                'module' => 'services',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Service Orders
            [
                'title' => 'View Service Orders',
                'slug' => 'service_orders.view',
                'description' => 'View service order list and details',
                'module' => 'service_orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Service Orders',
                'slug' => 'service_orders.create',
                'description' => 'Create new service orders',
                'module' => 'service_orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Service Orders',
                'slug' => 'service_orders.edit',
                'description' => 'Edit service order information',
                'module' => 'service_orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Service Orders',
                'slug' => 'service_orders.delete',
                'description' => 'Delete service orders',
                'module' => 'service_orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Process Service Orders',
                'slug' => 'service_orders.process',
                'description' => 'Process and fulfill service orders',
                'module' => 'service_orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Cancel Service Orders',
                'slug' => 'service_orders.cancel',
                'description' => 'Cancel service orders',
                'module' => 'service_orders',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Service Order Payments
            [
                'title' => 'View Service Order Payments',
                'slug' => 'service_order_payments.view',
                'description' => 'View service order payment records',
                'module' => 'service_order_payments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Process Service Order Payments',
                'slug' => 'service_order_payments.process',
                'description' => 'Process and record service order payments',
                'module' => 'service_order_payments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Refund Service Order Payments',
                'slug' => 'service_order_payments.refund',
                'description' => 'Process refunds for service order payments',
                'module' => 'service_order_payments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Manage Service Installment Payments',
                'slug' => 'service_order_payments.manage_installments',
                'description' => 'Setup and manage service installment payment plans',
                'module' => 'service_order_payments',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getFinancialManagementPermissions(): array
    {
        return [
            // Payment Methods
            [
                'title' => 'View Payment Methods',
                'slug' => 'payment_methods.view',
                'description' => 'View available payment methods',
                'module' => 'payment_methods',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Payment Methods',
                'slug' => 'payment_methods.create',
                'description' => 'Create new payment methods',
                'module' => 'payment_methods',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Payment Methods',
                'slug' => 'payment_methods.edit',
                'description' => 'Edit payment method settings',
                'module' => 'payment_methods',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Payment Methods',
                'slug' => 'payment_methods.delete',
                'description' => 'Delete payment methods',
                'module' => 'payment_methods',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Expenses
            [
                'title' => 'View Expenses',
                'slug' => 'expenses.view',
                'description' => 'View expense records',
                'module' => 'expenses',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Expenses',
                'slug' => 'expenses.create',
                'description' => 'Create new expense records',
                'module' => 'expenses',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Expenses',
                'slug' => 'expenses.edit',
                'description' => 'Edit expense information',
                'module' => 'expenses',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Expenses',
                'slug' => 'expenses.delete',
                'description' => 'Delete expense records',
                'module' => 'expenses',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Approve Expenses',
                'slug' => 'expenses.approve',
                'description' => 'Approve expense requests',
                'module' => 'expenses',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Expense Categories
            [
                'title' => 'View Expense Categories',
                'slug' => 'expense_categories.view',
                'description' => 'View expense categories',
                'module' => 'expense_categories',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Expense Categories',
                'slug' => 'expense_categories.create',
                'description' => 'Create new expense categories',
                'module' => 'expense_categories',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Expense Categories',
                'slug' => 'expense_categories.edit',
                'description' => 'Edit expense categories',
                'module' => 'expense_categories',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Expense Categories',
                'slug' => 'expense_categories.delete',
                'description' => 'Delete expense categories',
                'module' => 'expense_categories',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Accounting & Transactions
            [
                'title' => 'View Transactions',
                'slug' => 'transactions.view',
                'description' => 'View financial transactions',
                'module' => 'transactions',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Accounts',
                'slug' => 'accounts.view',
                'description' => 'View account balances and details',
                'module' => 'accounts',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Manage Accounts',
                'slug' => 'accounts.manage',
                'description' => 'Create and manage financial accounts',
                'module' => 'accounts',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Financial Reports',
                'slug' => 'financial_reports.view',
                'description' => 'Access financial reports and statements',
                'module' => 'financial_reports',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getStoreManagementPermissions(): array
    {
        return [
            [
                'title' => 'View Stores',
                'slug' => 'stores.view',
                'description' => 'View store information',
                'module' => 'stores',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Stores',
                'slug' => 'stores.create',
                'description' => 'Create new stores',
                'module' => 'stores',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Stores',
                'slug' => 'stores.edit',
                'description' => 'Edit store information',
                'module' => 'stores',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Stores',
                'slug' => 'stores.delete',
                'description' => 'Delete stores',
                'module' => 'stores',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Manage Store Settings',
                'slug' => 'stores.manage_settings',
                'description' => 'Configure store-specific settings',
                'module' => 'stores',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getSystemAdministrationPermissions(): array
    {
        return [
            // System Settings
            [
                'title' => 'View System Settings',
                'slug' => 'system.settings.view',
                'description' => 'View system configuration settings',
                'module' => 'system',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit System Settings',
                'slug' => 'system.settings.edit',
                'description' => 'Modify system configuration settings',
                'module' => 'system',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Dynamic Fields
            [
                'title' => 'View Dynamic Fields',
                'slug' => 'fields.view',
                'description' => 'View dynamic field configurations',
                'module' => 'fields',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Dynamic Fields',
                'slug' => 'fields.create',
                'description' => 'Create new dynamic fields',
                'module' => 'fields',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Dynamic Fields',
                'slug' => 'fields.edit',
                'description' => 'Edit dynamic field configurations',
                'module' => 'fields',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Dynamic Fields',
                'slug' => 'fields.delete',
                'description' => 'Delete dynamic fields',
                'module' => 'fields',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // Notes
            [
                'title' => 'View Notes',
                'slug' => 'notes.view',
                'description' => 'View system notes and comments',
                'module' => 'notes',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Create Notes',
                'slug' => 'notes.create',
                'description' => 'Create new notes and comments',
                'module' => 'notes',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Edit Notes',
                'slug' => 'notes.edit',
                'description' => 'Edit existing notes',
                'module' => 'notes',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Delete Notes',
                'slug' => 'notes.delete',
                'description' => 'Delete notes and comments',
                'module' => 'notes',
                'guard_name' => 'web',
                'is_active' => true,
            ],

            // System Maintenance
            [
                'title' => 'Run Database Migrations',
                'slug' => 'system.migrations.run',
                'description' => 'Execute database migrations',
                'module' => 'system',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Run Database Seeders',
                'slug' => 'system.seeders.run',
                'description' => 'Execute database seeders',
                'module' => 'system',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Clear System Cache',
                'slug' => 'system.cache.clear',
                'description' => 'Clear application cache',
                'module' => 'system',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View System Logs',
                'slug' => 'system.logs.view',
                'description' => 'Access system log files',
                'module' => 'system',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }

    private function getReportingPermissions(): array
    {
        return [
            [
                'title' => 'View Sales Reports',
                'slug' => 'reports.sales.view',
                'description' => 'Access sales and revenue reports',
                'module' => 'reports',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Inventory Reports',
                'slug' => 'reports.inventory.view',
                'description' => 'Access inventory and stock reports',
                'module' => 'reports',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Customer Reports',
                'slug' => 'reports.customers.view',
                'description' => 'Access customer analytics and reports',
                'module' => 'reports',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Financial Reports',
                'slug' => 'reports.financial.view',
                'description' => 'Access financial statements and reports',
                'module' => 'reports',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'View Performance Reports',
                'slug' => 'reports.performance.view',
                'description' => 'Access system and user performance reports',
                'module' => 'reports',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Export Reports',
                'slug' => 'reports.export',
                'description' => 'Export reports to various formats',
                'module' => 'reports',
                'guard_name' => 'web',
                'is_active' => true,
            ],
            [
                'title' => 'Schedule Reports',
                'slug' => 'reports.schedule',
                'description' => 'Schedule automated report generation',
                'module' => 'reports',
                'guard_name' => 'web',
                'is_active' => true,
            ],
        ];
    }
}
