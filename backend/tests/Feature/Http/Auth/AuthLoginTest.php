<?php

namespace Tests\Feature\Http\Auth;

use App\Models\User;

class AuthLoginTest extends AuthTestCase
{
    public function test_user_with_valid_credentials_can_login(): void
    {
        $user = $this->createUser('staff');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful')
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role.slug', 'staff')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'role'], 'token']]);
    }

    public function test_wrong_password_is_rejected_with_generic_401(): void
    {
        $user = $this->createUser('staff');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_unknown_email_is_rejected_with_same_generic_message(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@nexora.test',
            'password' => 'password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->createUser('staff', [], ['is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_token_is_generated_on_login(): void
    {
        $user = $this->createUser('staff');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'nexora-api',
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_last_login_at_is_updated_on_login(): void
    {
        $user = $this->createUser('staff');

        $this->assertNull($user->last_login_at);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
