<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'View Dashboard', 'slug' => 'view_dashboard'],
            ['name' => 'View Users', 'slug' => 'view_users'],
            ['name' => 'Manage Users', 'slug' => 'manage_users'],
            ['name' => 'Manage Roles', 'slug' => 'manage_roles'],
            ['name' => 'View Departments', 'slug' => 'view_departments'],
            ['name' => 'Manage Departments', 'slug' => 'manage_departments'],
            ['name' => 'View Locations', 'slug' => 'view_locations'],
            ['name' => 'Manage Locations', 'slug' => 'manage_locations'],
            ['name' => 'View Assets', 'slug' => 'view_assets'],
            ['name' => 'Manage Assets', 'slug' => 'manage_assets'],
            ['name' => 'View Asset Categories', 'slug' => 'view_asset_categories'],
            ['name' => 'Manage Asset Categories', 'slug' => 'manage_asset_categories'],
            ['name' => 'Assign Assets', 'slug' => 'assign_assets'],
            ['name' => 'Approve Asset Assignments', 'slug' => 'approve_asset_assignments'],
            ['name' => 'View Inventory', 'slug' => 'view_inventory'],
            ['name' => 'Manage Inventory', 'slug' => 'manage_inventory'],
            ['name' => 'Manage Stock', 'slug' => 'manage_stock'],
            ['name' => 'View Tickets', 'slug' => 'view_tickets'],
            ['name' => 'Manage Tickets', 'slug' => 'manage_tickets'],
            ['name' => 'Assign Tickets', 'slug' => 'assign_tickets'],
            ['name' => 'View Maintenance', 'slug' => 'view_maintenance'],
            ['name' => 'Manage Maintenance', 'slug' => 'manage_maintenance'],
            ['name' => 'View Reports', 'slug' => 'view_reports'],
            ['name' => 'View Audit Logs', 'slug' => 'view_audit_logs'],
            ['name' => 'View Asset Assignments', 'slug' => 'view_asset_assignments'],
            ['name' => 'Manage Asset Assignments', 'slug' => 'manage_asset_assignments'],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['slug' => $permission['slug']], $permission);
        }
    }
}
