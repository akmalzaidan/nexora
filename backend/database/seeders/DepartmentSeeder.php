<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Information Technology', 'code' => 'IT', 'description' => 'Systems, infrastructure, and support'],
            ['name' => 'Human Resources', 'code' => 'HR', 'description' => 'People operations'],
            ['name' => 'Finance', 'code' => 'FIN', 'description' => 'Accounting and budgeting'],
            ['name' => 'Operations', 'code' => 'OPS', 'description' => 'Day-to-day operations'],
            ['name' => 'Warehouse', 'code' => 'WH', 'description' => 'Inventory and logistics'],
            ['name' => 'Management', 'code' => 'MGT', 'description' => 'Executive leadership'],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(['code' => $department['code']], $department);
        }
    }
}
