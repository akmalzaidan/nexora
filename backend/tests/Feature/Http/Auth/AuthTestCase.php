<?php

namespace Tests\Feature\Http\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Shared setup for authentication tests. Builds roles/permissions without
 * running the full seeder so suites stay fast, and clears the auth rate
 * limiters between tests (the array cache persists across tests in-process).
 */
abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login');
        RateLimiter::clear('register');
    }

    protected function createRole(string $slug, array $permissionSlugs = []): Role
    {
        $role = Role::create([
            'name' => ucfirst(str_replace('_', ' ', $slug)),
            'slug' => $slug,
        ]);

        if ($permissionSlugs !== []) {
            $permissionIds = collect($permissionSlugs)->map(function (string $permissionSlug): int {
                return Permission::create([
                    'name' => ucfirst(str_replace('_', ' ', $permissionSlug)),
                    'slug' => $permissionSlug,
                ])->id;
            });

            $role->permissions()->sync($permissionIds->all());
        }

        return $role;
    }

    protected function createUser(string $roleSlug, array $permissionSlugs = [], array $attributes = []): User
    {
        $role = $this->createRole($roleSlug, $permissionSlugs);

        return User::factory()->create(array_merge([
            'role_id' => $role->id,
            'department_id' => null,
            'is_active' => true,
        ], $attributes));
    }

    protected function actingAsUser(User $user): string
    {
        return $user->createToken('nexora-api')->plainTextToken;
    }

    protected function authorizationHeader(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
