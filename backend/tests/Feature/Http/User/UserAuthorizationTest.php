<?php

namespace Tests\Feature\Http\User;

class UserAuthorizationTest extends UserTestCase
{
    public function test_unauthenticated_user_cannot_list_users(): void
    {
        $this->getJson('/api/v1/users')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_user_cannot_create_users(): void
    {
        $this->postJson('/api/v1/users', [
            'name' => 'New Hire',
            'email' => 'newhire@nexora.test',
            'password' => 'secret1234',
            'role_id' => $this->roleId('staff'),
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_user_cannot_update_users(): void
    {
        $user = $this->userWithRole('staff');

        $this->putJson("/api/v1/users/{$user->id}", ['name' => 'Changed'])
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_unauthenticated_user_cannot_delete_users(): void
    {
        $user = $this->userWithRole('staff');

        $this->deleteJson("/api/v1/users/{$user->id}")
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_staff_without_permission_cannot_list_users(): void
    {
        $token = $this->actingAsUser($this->userWithRole('staff'));

        $this->getJson('/api/v1/users', $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_staff_without_permission_cannot_create_users(): void
    {
        $token = $this->actingAsUser($this->userWithRole('staff'));

        $this->postJson('/api/v1/users', [
            'name' => 'New Hire',
            'email' => 'newhire@nexora.test',
            'password' => 'secret1234',
            'role_id' => $this->roleId('staff'),
        ], $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_staff_without_permission_cannot_update_users(): void
    {
        $token = $this->actingAsUser($this->userWithRole('staff'));
        $target = $this->userWithRole('staff');

        $this->putJson("/api/v1/users/{$target->id}", ['name' => 'Changed'], $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_staff_without_permission_cannot_delete_users(): void
    {
        $token = $this->actingAsUser($this->userWithRole('staff'));
        $target = $this->userWithRole('staff');

        $this->deleteJson("/api/v1/users/{$target->id}", [], $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_admin_has_full_access(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson('/api/v1/users', $this->authorizationHeader($token))->assertOk();

        $this->postJson('/api/v1/users', [
            'name' => 'New Admin',
            'email' => 'newadmin@nexora.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => $this->roleId('admin'),
        ], $this->authorizationHeader($token))
            ->assertStatus(201);
    }

    public function test_super_admin_has_full_access(): void
    {
        $token = $this->actingAsUser($this->userWithRole('super_admin'));

        $target = $this->userWithRole('staff');

        $this->getJson('/api/v1/users', $this->authorizationHeader($token))->assertOk();
        $this->getJson("/api/v1/users/{$target->id}", $this->authorizationHeader($token))->assertOk();

        $this->postJson('/api/v1/users', [
            'name' => 'New Super',
            'email' => 'newsuper@nexora.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => $this->roleId('super_admin'),
        ], $this->authorizationHeader($token))
            ->assertStatus(201);
    }

    public function test_route_uses_permission_middleware_not_direct_role_checks(): void
    {
        $router = $this->app->make('router');

        $routeGets = $router->getRoutes()->getByName('api.users.index');
        $routePost = $router->getRoutes()->getByName('api.users.store');

        $this->assertStringContainsString('permission:view_users', implode('|', $routeGets->gatherMiddleware()));
        $this->assertStringContainsString('permission:manage_users', implode('|', $routePost->gatherMiddleware()));
        $this->assertStringContainsString('auth:sanctum', implode('|', $routeGets->gatherMiddleware()));
    }
}
