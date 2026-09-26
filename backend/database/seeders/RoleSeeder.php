<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Super Admin', 'slug' => 'super_admin', 'description' => 'Full platform access'],
            ['name' => 'Admin', 'slug' => 'admin', 'description' => 'Platform administration'],
            ['name' => 'Manager', 'slug' => 'manager', 'description' => 'People and operations management'],
            ['name' => 'Staff', 'slug' => 'staff', 'description' => 'Standard employee access'],
            ['name' => 'Technician', 'slug' => 'technician', 'description' => 'Maintenance and repair work'],
            ['name' => 'Warehouse Staff', 'slug' => 'warehouse_staff', 'description' => 'Inventory operations'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
