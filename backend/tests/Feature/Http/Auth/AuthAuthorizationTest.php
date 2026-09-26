<?php

namespace Tests\Feature\Http\Auth;

use Illuminate\Support\Facades\Route;

class AuthAuthorizationTest extends AuthTestCase
{
    public function test_role_middleware_allows_matching_role(): void
    {
        $this->registerTemporaryRoute(['auth:sanctum', 'role:admin,manager']);

        $user = $this->createUser('manager');
        $token = $this->actingAsUser($user);

        $this->getJson('/api/v1/_test/auth-role', $this->authorizationHeader($token))
            ->assertOk();
    }

    public function test_role_middleware_denies_non_matching_role(): void
    {
        $this->registerTemporaryRoute(['auth:sanctum', 'role:admin']);

        $user = $this->createUser('staff');
        $token = $this->actingAsUser($user);

        $this->getJson('/api/v1/_test/auth-role', $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Access denied.')
            ->assertJsonPath('errors', []);
    }

    public function test_permission_middleware_allows_user_with_permission(): void
    {
        $this->registerTemporaryRoute(['auth:sanctum', 'permission:manage_users']);

        $user = $this->createUser('admin', ['manage_users']);
        $token = $this->actingAsUser($user);

        $this->getJson('/api/v1/_test/auth-permission', $this->authorizationHeader($token))
            ->assertOk();
    }

    public function test_permission_middleware_denies_user_without_permission(): void
    {
        $this->registerTemporaryRoute(['auth:sanctum', 'permission:manage_users']);

        $user = $this->createUser('staff');
        $token = $this->actingAsUser($user);

        $this->getJson('/api/v1/_test/auth-permission', $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_super_admin_bypasses_permission_checks(): void
    {
        $this->registerTemporaryRoute(['auth:sanctum', 'permission:manage_users']);

        $user = $this->createUser('super_admin');
        $token = $this->actingAsUser($user);

        $this->getJson('/api/v1/_test/auth-permission', $this->authorizationHeader($token))
            ->assertOk();
    }

    public function test_authentication_is_never_bypassed_for_super_admin(): void
    {
        $this->registerTemporaryRoute(['auth:sanctum', 'permission:manage_users']);

        $this->getJson('/api/v1/_test/auth-permission')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    private function registerTemporaryRoute(array $middleware): void
    {
        Route::middleware($middleware)
            ->get('api/v1/_test/auth-role', static fn (): array => ['ok' => true])
            ->name('test.auth.role');

        Route::middleware($middleware)
            ->get('api/v1/_test/auth-permission', static fn (): array => ['ok' => true])
            ->name('test.auth.permission');
    }
}
