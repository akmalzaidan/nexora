<?php

namespace Tests\Feature\Http\Ticket;

use App\Models\Role;
use App\Models\TicketCategory;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class TicketTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $manager;

    protected User $technician;

    protected User $staff;

    protected TicketCategory $hardware;

    protected TicketCategory $network;

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

        $this->hardware = TicketCategory::factory()->create(['name' => 'Hardware', 'code' => 'HARDWARE']);
        $this->network = TicketCategory::factory()->create(['name' => 'Network', 'code' => 'NETWORK']);
    }

    protected function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }
}
