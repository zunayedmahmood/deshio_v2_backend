<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * RolesSeeder — Errum V2 RBAC Refactor (2026-03-30)
 *
 * Seeds the canonical 7 business roles. Levels and permissions are removed;
 * access control is enforced entirely on the frontend based on role slug.
 *
 * Roles:
 *  1. super-admin     – Full system orchestrator
 *  2. admin           – General administrator
 *  3. branch-manager  – Operations leader for a specific branch
 *  4. online-moderator– Handles social-commerce orders & store assignment
 *  5. pos-salesman    – POS counter duty & online order fulfilment
 *  6. employee        – Basic staff for customer interaction and inventory
 *
 * NOTE: This seeder uses updateOrInsert keyed on `slug` so it is safe to
 * re-run on a live system without dropping employee foreign key constraints.
 */
class RolesSeeder extends Seeder
{
    private const ROLES = [
        [
            'title'       => 'Super Admin',
            'slug'        => 'super-admin',
            'description' => 'System orchestrator — full access to every feature and setting.',
            'guard_name'  => 'api',
            'is_active'   => true,
            'is_default'  => false,
        ],
        [
            'title'       => 'Admin',
            'slug'        => 'admin',
            'description' => 'General administrator with global visibility across all stores.',
            'guard_name'  => 'api',
            'is_active'   => true,
            'is_default'  => false,
        ],
        [
            'title'       => 'Branch Manager',
            'slug'        => 'branch-manager',
            'description' => 'Operations leader responsible for overseeing a specific store/branch.',
            'guard_name'  => 'api',
            'is_active'   => true,
            'is_default'  => false,
        ],
        [
            'title'       => 'Online Moderator',
            'slug'        => 'online-moderator',
            'description' => 'Handles social-commerce orders and store assignment for all online orders.',
            'guard_name'  => 'api',
            'is_active'   => true,
            'is_default'  => false,
        ],
        [
            'title'       => 'POS Salesman',
            'slug'        => 'pos-salesman',
            'description' => 'Handles POS counter duty and online order fulfilment for their branch.',
            'guard_name'  => 'api',
            'is_active'   => true,
            'is_default'  => false,
        ],
        [
            'title'       => 'Employee',
            'slug'        => 'employee',
            'description' => 'Basic staff for customer interaction and inventory tasks within their branch.',
            'guard_name'  => 'api',
            'is_active'   => true,
            'is_default'  => true,
        ],
    ];

    public function run(): void
    {
        $count = 0;

        foreach (self::ROLES as $roleData) {
            $slug = $roleData['slug'];

            $exists = Role::where('slug', $slug)->first();

            if ($exists) {
                // Update title, description, guard_name, flags — but never touch
                // role_id of existing employees, so we skip delete/recreate.
                $exists->update([
                    'title'       => $roleData['title'],
                    'description' => $roleData['description'],
                    'guard_name'  => $roleData['guard_name'],
                    'is_active'   => $roleData['is_active'],
                    'is_default'  => $roleData['is_default'],
                ]);
            } else {
                Role::create($roleData);
                $count++;
            }
        }

        // Detach permissions from all roles (permissions are no longer used
        // for access control — the frontend manages this via role slugs).
        Role::each(function (Role $role) {
            $role->permissions()->detach();
        });

        // Report any legacy roles that no longer belong to the new schema
        $validSlugs = collect(self::ROLES)->pluck('slug')->toArray();
        $legacyRoles = Role::whereNotIn('slug', $validSlugs)->get();

        if ($legacyRoles->isNotEmpty()) {
            $this->command->warn('The following legacy roles still exist (employees may be assigned to them):');
            foreach ($legacyRoles as $role) {
                $employeeCount = $role->employees()->count();
                $this->command->warn("  - [{$role->slug}] \"{$role->title}\" — {$employeeCount} employee(s) assigned");
            }
            $this->command->warn('Re-assign these employees and then delete/deactivate the legacy roles via the admin panel.');
        }

        $this->command->info('Roles seeded successfully (' . count(self::ROLES) . ' canonical roles, ' . $count . ' newly created).');
    }
}
