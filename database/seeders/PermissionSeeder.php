<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed all predefined RBAC permissions.
     *
     * Uses updateOrCreate so the seeder can be re-run safely without duplicating data.
     */
    public function run(): void
    {
        $permissions = [
            // Dashboard
            ['name' => 'view_dashboard', 'display_name' => 'View Dashboard', 'group_name' => 'Dashboard'],

            // Orders
            ['name' => 'view_orders', 'display_name' => 'View Orders', 'group_name' => 'Orders'],
            ['name' => 'manage_orders', 'display_name' => 'Manage Orders', 'group_name' => 'Orders'],

            // Users
            ['name' => 'view_users', 'display_name' => 'View Users', 'group_name' => 'Users'],
            ['name' => 'manage_users', 'display_name' => 'Manage Users', 'group_name' => 'Users'],

            // Vendors
            ['name' => 'view_vendors', 'display_name' => 'View Vendors', 'group_name' => 'Vendors'],
            ['name' => 'manage_vendors', 'display_name' => 'Manage Vendors', 'group_name' => 'Vendors'],

            // Products
            ['name' => 'view_products', 'display_name' => 'View Products', 'group_name' => 'Products'],
            ['name' => 'manage_products', 'display_name' => 'Manage Products', 'group_name' => 'Products'],

            // Agents
            ['name' => 'view_agents', 'display_name' => 'View Agents', 'group_name' => 'Agents'],
            ['name' => 'manage_agents', 'display_name' => 'Manage Agents', 'group_name' => 'Agents'],

            // Finance
            ['name' => 'view_revenue', 'display_name' => 'View Revenue', 'group_name' => 'Finance'],
            ['name' => 'manage_pricing', 'display_name' => 'Manage Pricing', 'group_name' => 'Finance'],
            ['name' => 'view_withdrawals', 'display_name' => 'View Withdrawals', 'group_name' => 'Finance'],
            ['name' => 'manage_withdrawals', 'display_name' => 'Manage Withdrawals', 'group_name' => 'Finance'],

            // Administration
            ['name' => 'manage_roles', 'display_name' => 'Manage Roles', 'group_name' => 'Administration'],
            ['name' => 'manage_admins', 'display_name' => 'Manage Admin Users', 'group_name' => 'Administration'],
            ['name' => 'view_audit_log', 'display_name' => 'View Audit Log', 'group_name' => 'Administration'],

            // Content
            ['name' => 'manage_blog', 'display_name' => 'Manage Blog', 'group_name' => 'Content'],
            ['name' => 'manage_slides', 'display_name' => 'Manage Slides', 'group_name' => 'Content'],
            ['name' => 'manage_notifications', 'display_name' => 'Manage Notifications', 'group_name' => 'Content'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                [
                    'display_name' => $permission['display_name'],
                    'group_name' => $permission['group_name'],
                ]
            );
        }
    }
}
