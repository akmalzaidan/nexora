<?php

namespace Tests\Feature\Http\Report;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ReportTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $manager;

    protected User $technician;

    protected User $staff;

    protected User $warehouseStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $this->superAdmin = $this->userWithRole('super_admin');
        $this->admin = $this->userWithRole('admin');
        $this->manager = $this->userWithRole('manager');
        $this->technician = $this->userWithRole('technician');
        $this->staff = $this->userWithRole('staff');
        $this->warehouseStaff = $this->userWithRole('warehouse_staff');
    }

    protected function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }
}
