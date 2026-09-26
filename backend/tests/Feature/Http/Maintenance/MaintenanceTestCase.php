<?php

namespace Tests\Feature\Http\Maintenance;

use App\Models\Asset;
use App\Models\Item;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class MaintenanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $manager;

    protected User $technician;

    protected User $staff;

    protected User $warehouse;

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
        $this->warehouse = $this->userWithRole('warehouse_staff');
    }

    protected function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function asset(array $attributes = []): Asset
    {
        return Asset::factory()->create(array_merge(['status' => 'ACTIVE'], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function item(array $attributes = []): Item
    {
        return Item::factory()->create(array_merge(['is_active' => true], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeRequest(array $attributes = []): MaintenanceRequest
    {
        return MaintenanceRequest::factory()->create(array_merge([
            'requested_by' => $this->staff->id,
            'asset_id' => $this->asset()->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ], $attributes));
    }

    protected function approvedRequest(): MaintenanceRequest
    {
        return MaintenanceRequest::factory()->create([
            'requested_by' => $this->staff->id,
            'asset_id' => $this->asset()->id,
            'status' => MaintenanceRequest::STATUS_APPROVED,
            'approved_at' => now(),
        ]);
    }

    protected function inProgressRequest(): MaintenanceRequest
    {
        $request = $this->approvedRequest();

        MaintenanceRecord::factory()->create([
            'maintenance_request_id' => $request->id,
            'asset_id' => $request->asset_id,
            'technician_id' => $this->technician->id,
        ]);

        $request->update(['status' => MaintenanceRequest::STATUS_IN_PROGRESS]);

        return $request;
    }
}
