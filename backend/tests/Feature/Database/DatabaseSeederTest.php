<?php

namespace Tests\Feature\Database;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_reference_roles_and_permissions(): void
    {
        $this->seed();

        $this->assertSame(6, Role::count());
        $this->assertDatabaseHas('roles', ['slug' => 'super_admin']);
        $this->assertDatabaseHas('roles', ['slug' => 'warehouse_staff']);

        $this->assertSame(26, Permission::count());
        $this->assertDatabaseHas('permissions', ['slug' => 'view_audit_logs']);
    }

    public function test_seeder_assigns_expected_permissions_to_roles(): void
    {
        $this->seed();

        $this->assertSame(26, Role::where('slug', 'super_admin')->first()->permissions()->count());
        $this->assertSame(26, Role::where('slug', 'admin')->first()->permissions()->count());
        $this->assertSame(10, Role::where('slug', 'manager')->first()->permissions()->count());
        $this->assertSame(5, Role::where('slug', 'staff')->first()->permissions()->count());
        $this->assertSame(7, Role::where('slug', 'technician')->first()->permissions()->count());
        $this->assertSame(6, Role::where('slug', 'warehouse_staff')->first()->permissions()->count());
    }

    public function test_seeder_creates_development_user_accounts(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 6);

        $admin = User::where('email', 'admin@nexora.test')->firstOrFail();
        $this->assertSame('admin', $admin->role->slug);
        $this->assertSame('IT', $admin->department->code);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(6, Role::count());
        $this->assertSame(26, Permission::count());
        $this->assertSame(6, User::count());
    }
}
