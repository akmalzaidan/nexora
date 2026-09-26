<?php

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditResourceType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication business logic for the API.
 *
 * Controllers stay thin; everything about finding users, verifying
 * credentials, assigning the default role, and issuing Sanctum tokens
 * lives here.
 */
class AuthService
{
    private const TOKEN_NAME = 'nexora-api';

    public function __construct(private readonly AuditLogService $auditLogs) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        $staffRole = Role::where('slug', 'staff')->firstOrFail();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $staffRole->id,
            'is_active' => true,
        ]);

        $user->load(['role', 'department']);

        $token = $user->createToken(self::TOKEN_NAME)->plainTextToken;

        $this->auditLogs->recordEvent(
            AuditAction::REGISTERED,
            AuditResourceType::USER,
            $user->id,
            $user,
            "Account registered for {$user->email}",
        );

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * @param  array{email: string, password: string}  $data
     * @return array{user: User, token: string}
     */
    public function login(array $data): array
    {
        $user = User::with(['role', 'department'])->where('email', $data['email'])->first();

        $authenticated = $user
            && $user->is_active
            && Hash::check($data['password'], $user->password);

        if (! $authenticated) {
            throw new InvalidCredentialsException;
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken(self::TOKEN_NAME)->plainTextToken;

        $this->auditLogs->recordEvent(
            AuditAction::LOGGED_IN,
            AuditResourceType::USER,
            $user->id,
            $user,
            "User {$user->email} logged in",
        );

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * Revoke only the token currently used for this request.
     *
     * The auth guard caches the resolved user for the lifetime of the
     * application instance, so dropping it guarantees subsequent requests in
     * the same process (tests, queue workers, Laravel Octane) must re-auth.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();

        Auth::forgetGuards();

        $this->auditLogs->recordEvent(
            AuditAction::LOGGED_OUT,
            AuditResourceType::USER,
            $user->id,
            $user,
            "User {$user->email} logged out",
        );
    }
}
