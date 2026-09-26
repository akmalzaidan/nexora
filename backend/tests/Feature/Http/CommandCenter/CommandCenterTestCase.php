<?php

namespace Tests\Feature\Http\CommandCenter;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Base for the Command Center feature tests.
 *
 * Only the identity and permission graph is seeded: every operational record is
 * created by the test itself so each metric can be asserted mathematically
 * instead of compared against seed-data arithmetic.
 */
abstract class CommandCenterTestCase extends TestCase
{
    use RefreshDatabase;

    protected const ENDPOINT = '/api/v1/command-center';

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

    /**
     * `admin` is the reference caller: it holds `view_dashboard` plus every
     * domain view permission and both organization-wide scopes, so it is the only
     * role that can see the complete unfiltered surface.
     */
    protected function snapshotFor(User $actor, string $query = ''): TestResponse
    {
        return $this->actingAs($actor)->getJson(self::ENDPOINT.$query);
    }
}
