<?php

namespace Tests\Feature\Http\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

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

    /**
     * The auth guard memoizes the resolved user for the lifetime of the
     * application instance, and in tests that instance spans the whole test
     * method. Production re-resolves the user from the token on every request;
     * to reproduce that here each assertion has to drop the cached guard, the
     * same way `AuthService::logout` does.
     */
    private function reauthenticate(): void
    {
        Auth::forgetGuards();
    }

    public function test_existing_token_stops_working_once_the_user_is_deactivated(): void
    {
        $user = $this->createUser('staff');
        $headers = $this->authorizationHeader($this->actingAsUser($user));

        $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertOk();

        // An admin deactivates the account. The token was minted while the user
        // was active and Sanctum tokens do not expire, so without an explicit
        // check the old session would survive the deactivation.
        $user->update(['is_active' => false]);
        $this->reauthenticate();

        $this->withHeaders($headers)->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');

        // Same generic message as any other bad credential — no account-state leak.
        $this->reauthenticate();

        $this->withHeaders($headers)->getJson('/api/v1/auth/me')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_deactivating_a_user_revokes_their_existing_tokens(): void
    {
        $user = $this->createUser('staff');
        $headers = $this->authorizationHeader($this->actingAsUser($user));

        $user->update(['is_active' => false]);
        $this->reauthenticate();

        // First rejected request performs the revocation.
        $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_deactivating_a_user_does_not_touch_other_users_sessions(): void
    {
        $role = $this->createRole('staff');

        $deactivated = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $unaffected = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $deactivatedHeaders = $this->authorizationHeader($this->actingAsUser($deactivated));
        $unaffectedHeaders = $this->authorizationHeader($this->actingAsUser($unaffected));

        $deactivated->update(['is_active' => false]);
        $this->reauthenticate();

        $this->withHeaders($deactivatedHeaders)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->reauthenticate();

        $this->withHeaders($unaffectedHeaders)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_reactivating_a_user_requires_a_fresh_login(): void
    {
        $user = $this->createUser('staff');
        $headers = $this->authorizationHeader($this->actingAsUser($user));

        $user->update(['is_active' => false]);
        $this->reauthenticate();
        $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertStatus(401);

        $user->update(['is_active' => true]);
        $this->reauthenticate();

        // The token was deleted, not just rejected, so reactivation alone does
        // not resurrect the old session.
        $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }
}
