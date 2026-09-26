<?php

namespace Tests\Feature\Http\Organization;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared setup for Organization (departments/locations) tests. Seeds the
 * real roles + permissions + role-permission mapping so authorization tests
 * verify the production seeder, never a factory-only approximation.
 */
abstract class OrganizationTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    protected function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);
    }

    protected function makeDepartment(array $attributes = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Information Technology',
            'code' => 'IT',
            'is_active' => true,
        ], $attributes));
    }

    protected function actingAsUser(User $user): string
    {
        return $user->createToken('nexora-api')->plainTextToken;
    }

    /**
     * @return array{Authorization: string}
     */
    protected function authorizationHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
