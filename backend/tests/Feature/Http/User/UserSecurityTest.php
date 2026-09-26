<?php

namespace Tests\Feature\Http\User;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSecurityTest extends UserTestCase
{
    public function test_list_response_never_exposes_credentials_or_tokens(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $this->userWithRole('staff');

        $this->getJson('/api/v1/users', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonMissingPath('data.items.0.password')
            ->assertJsonMissingPath('data.items.0.remember_token')
            ->assertJsonMissingPath('data.items.0.tokens')
            ->assertJsonMissingPath('data.items.0.token')
            ->assertJsonMissingPath('data.items.0.personal_access_tokens');

        $body = $this->getJson('/api/v1/users', $this->authorizationHeader($token))->content();
        $this->assertStringNotContainsString('plainTextToken', $body);
    }

    public function test_detail_response_never_exposes_credentials(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $target = $this->userWithRole('staff');

        $this->getJson("/api/v1/users/{$target->id}", $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.tokens');
    }

    public function test_create_response_never_exposes_password_or_token(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', [
            'name' => 'Safe User',
            'email' => 'safe@nexora.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => $this->roleId('staff'),
        ], $this->authorizationHeader($token))
            ->assertStatus(201)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.token');
    }

    public function test_created_password_is_hash_correct(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', [
            'name' => 'Hashed User',
            'email' => 'hasheduser@nexora.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => $this->roleId('staff'),
        ], $this->authorizationHeader($token))->assertStatus(201);

        $user = User::where('email', 'hasheduser@nexora.test')->firstOrFail();
        $this->assertNotSame($user->password, 'secret1234');
        $this->assertTrue(Hash::check('secret1234', $user->password));
        $this->assertFalse(Hash::check('not-the-password', $user->password));
    }

    public function test_updated_password_is_hash_correct(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $target = $this->userWithRole('staff');

        $this->putJson("/api/v1/users/{$target->id}", [
            'password' => 'rotated-pass',
            'password_confirmation' => 'rotated-pass',
        ], $this->authorizationHeader($token))->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue(Hash::check('rotated-pass', $fresh->password));
        $this->assertFalse(Hash::check('password', $fresh->password));
    }

    public function test_inactive_user_cannot_login_after_admin_deactivates_them(): void
    {
        $admin = $this->userWithRole('admin');
        $token = $this->actingAsUser($admin);
        $target = $this->userWithRole('staff');

        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => 'password',
        ])->assertOk();

        $this->putJson("/api/v1/users/{$target->id}", [
            'is_active' => false,
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->postJson('/api/v1/auth/login', [
            'email' => $target->email,
            'password' => 'password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials');
    }
}
