<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            Role::where('slug', 'super_admin')->first()?->id => [
                'view_dashboard',
                'view_users',
                'manage_users',
                'manage_roles',
                'view_departments',
                'manage_departments',
                'view_locations',
                'manage_locations',
                'view_assets',
                'manage_assets',
                'view_asset_categories',
                'manage_asset_categories',
                'assign_assets',
                'approve_asset_assignments',
                'view_inventory',
                'manage_inventory',
                'manage_stock',
                'view_tickets',
                'manage_tickets',
                'assign_tickets',
                'view_maintenance',
                'manage_maintenance',
                'view_reports',
                'view_audit_logs',
                'view_asset_assignments',
                'manage_asset_assignments',
            ],
            Role::where('slug', 'admin')->first()?->id => [
                'view_dashboard',
                'view_users',
                'manage_users',
                'manage_roles',
                'view_departments',
                'manage_departments',
                'view_locations',
                'manage_locations',
                'view_assets',
                'manage_assets',
                'view_asset_categories',
                'manage_asset_categories',
                'assign_assets',
                'approve_asset_assignments',
                'view_inventory',
                'manage_inventory',
                'manage_stock',
                'view_tickets',
                'manage_tickets',
                'assign_tickets',
                'view_maintenance',
                'manage_maintenance',
                'view_reports',
                'view_audit_logs',
                'view_asset_assignments',
                'manage_asset_assignments',
            ],
            Role::where('slug', 'manager')->first()?->id => [
                'view_dashboard',
                'view_assets',
                'assign_assets',
                'view_inventory',
                'view_tickets',
                'manage_tickets',
                'assign_tickets',
                'view_maintenance',
                'view_reports',
                'view_asset_assignments',
            ],
            Role::where('slug', 'staff')->first()?->id => [
                'view_dashboard',
                'view_assets',
                'view_inventory',
                'view_tickets',
                'view_maintenance',
            ],
            Role::where('slug', 'technician')->first()?->id => [
                'view_assets',
                'view_tickets',
                'manage_tickets',
                'assign_tickets',
                'view_maintenance',
                'manage_maintenance',
                'view_asset_assignments',
            ],
            Role::where('slug', 'warehouse_staff')->first()?->id => [
                'view_dashboard',
                'view_assets',
                'view_inventory',
                'manage_inventory',
                'manage_stock',
                'view_asset_assignments',
            ],
        ];

        foreach ($permissions as $roleId => $slugs) {
            if ($roleId === null) {
                continue;
            }

            $ids = Permission::whereIn('slug', $slugs)->pluck('id');

            Role::find($roleId)?->permissions()->syncWithoutDetaching($ids);
        }
    }
}
