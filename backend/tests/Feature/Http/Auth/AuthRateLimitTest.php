<?php

namespace Tests\Feature\Http\Auth;

class AuthRateLimitTest extends AuthTestCase
{
    public function test_login_requests_are_throttled(): void
    {
        $user = $this->createUser('staff');
        $credentials = ['email' => $user->email, 'password' => 'password'];

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', $credentials)->assertOk();
        }

        $this->postJson('/api/v1/auth/login', $credentials)
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }

    public function test_register_requests_are_throttled(): void
    {
        $this->createRole('staff');

        foreach (['first@nexora.test', 'second@nexora.test', 'third@nexora.test'] as $i => $email) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'User '.($i + 1),
                'email' => $email,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])->assertStatus(201);
        }

        $this->postJson('/api/v1/auth/register', [
            'name' => 'User Four',
            'email' => 'fourth@nexora.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertStatus(429)
            ->assertJsonPath('success', false);
    }
}
