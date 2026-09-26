<?php

namespace Tests\Feature\Http\Auth;

use App\Models\Department;

class AuthAuthenticatedUserTest extends AuthTestCase
{
    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_me_rejects_invalid_token(): void
    {
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer not-a-valid-token'])
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_me_returns_authenticated_user_with_role_and_department(): void
    {
        $department = Department::create(['name' => 'Information Technology', 'code' => 'IT']);
        $user = $this->createUser('staff', [], ['department_id' => $department->id]);

        $token = $this->actingAsUser($user);

        $this->getJson('/api/v1/auth/me', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Authenticated user')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role.slug', 'staff')
            ->assertJsonPath('data.user.department.code', 'IT');
    }

    public function test_me_returns_null_department_when_not_assigned(): void
    {
        $user = $this->createUser('staff');
        $token = $this->actingAsUser($user);

        $this->getJson('/api/v1/auth/me', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.user.department', null);
    }

    public function test_me_never_exposes_token_password_or_credentials(): void
    {
        $user = $this->createUser('staff');
        $token = $this->actingAsUser($user);

        $this->getJson('/api/v1/auth/me', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user.token')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.password_hash')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/v1/auth/logout')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = $this->createUser('staff');
        $token = $this->actingAsUser($user);

        $this->postJson('/api/v1/auth/logout', [], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout successful')
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->getJson('/api/v1/auth/me', $this->authorizationHeader($token))
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_logout_revokes_only_the_token_in_use(): void
    {
        $user = $this->createUser('staff');
        $firstToken = $this->actingAsUser($user);
        $secondToken = $user->createToken('nexora-api')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], $this->authorizationHeader($firstToken))
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->getJson('/api/v1/auth/me', $this->authorizationHeader($firstToken))
            ->assertStatus(401);
        $this->getJson('/api/v1/auth/me', $this->authorizationHeader($secondToken))
            ->assertOk();
    }
}
