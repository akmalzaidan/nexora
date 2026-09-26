<?php

namespace Tests\Feature\Http\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthRegistrationTest extends AuthTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }

    public function test_user_can_register(): void
    {
        $this->createRole('staff');

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Registration successful')
            ->assertJsonPath('data.user.name', 'John Doe')
            ->assertJsonPath('data.user.email', 'john@example.com')
            ->assertJsonPath('data.user.is_active', true)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email', 'role', 'is_active'],
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'is_active' => true,
        ]);
    }

    public function test_registered_password_is_hashed(): void
    {
        $this->createRole('staff');

        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(201);

        $user = User::where('email', 'john@example.com')->firstOrFail();

        $this->assertNotSame('Password123!', $user->password);
        $this->assertTrue(Hash::check('Password123!', $user->password));
    }

    public function test_registered_user_has_default_staff_role(): void
    {
        $staff = $this->createRole('staff');
        $this->createRole('admin');

        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(201);

        $user = User::where('email', 'john@example.com')->firstOrFail();

        $this->assertSame($staff->id, $user->role_id);
        $this->assertTrue($user->is_active);
    }

    public function test_public_registration_cannot_choose_privileged_role(): void
    {
        $staff = $this->createRole('staff');
        $admin = $this->createRole('admin');

        $this->postJson('/api/v1/auth/register', $this->payload([
            'role_id' => $admin->id,
            'is_active' => false,
            'department_id' => 999,
        ]))
            ->assertStatus(201)
            ->assertJsonPath('data.user.role.slug', 'staff');

        $user = User::where('email', 'john@example.com')->firstOrFail();

        $this->assertSame($staff->id, $user->role_id);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->department_id);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->createRole('staff');

        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(201);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_password_is_rejected(): void
    {
        $this->createRole('staff');

        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $this->createRole('staff');

        $this->postJson('/api/v1/auth/register', $this->payload([
            'password_confirmation' => 'Different123!',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_validation_failure_uses_error_envelope(): void
    {
        $this->createRole('staff');

        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors' => ['name', 'email', 'password']]);
    }

    public function test_response_never_exposes_password_or_credentials(): void
    {
        $this->createRole('staff');

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(201)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.password_hash')
            ->assertJsonMissingPath('data.user.remember_token');
    }
}
