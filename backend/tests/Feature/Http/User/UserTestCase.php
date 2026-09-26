<?php

namespace Tests\Feature\Http\User;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared setup for User Management tests. Seeds the real roles + permissions
 * + role-permission mapping so authorization tests verify the production
 * seeder, never a factory-only approximation.
 */
abstract class UserTestCase extends TestCase
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

    protected function userWithRole(string $slug, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
        ], $attributes));
    }

    protected function makeDepartment(array $attributes = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Information Technology',
            'code' => 'IT',
            'is_active' => true,
        ], $attributes));
    }

    protected function roleId(string $slug): int
    {
        return (int) Role::where('slug', $slug)->value('id');
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
