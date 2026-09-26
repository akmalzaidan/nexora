<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Development-only accounts. The shared password "password" is intentionally
     * weak and must never be used against a production database.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Super Admin', 'email' => 'superadmin@nexora.test', 'role' => 'super_admin', 'department' => 'MGT'],
            ['name' => 'Admin', 'email' => 'admin@nexora.test', 'role' => 'admin', 'department' => 'IT'],
            ['name' => 'Manager', 'email' => 'manager@nexora.test', 'role' => 'manager', 'department' => 'OPS'],
            ['name' => 'Staff', 'email' => 'staff@nexora.test', 'role' => 'staff', 'department' => 'HR'],
            ['name' => 'Technician', 'email' => 'technician@nexora.test', 'role' => 'technician', 'department' => 'IT'],
            ['name' => 'Warehouse Staff', 'email' => 'warehouse@nexora.test', 'role' => 'warehouse_staff', 'department' => 'WH'],
        ];

        foreach ($users as $user) {
            $role = Role::where('slug', $user['role'])->first();
            $department = Department::where('code', $user['department'])->first();

            User::updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => 'password',
                    'role_id' => $role?->id,
                    'department_id' => $department?->id,
                    'is_active' => true,
                ]
            );
        }
    }
}
