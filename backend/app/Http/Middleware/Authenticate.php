<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

/**
 * API-only authentication middleware.
 *
 * Never redirects to a web login route; unauthenticated API requests always
 * surface a 401 (which the exception renderer turns into the JSON envelope).
 *
 * It also owns the deactivation kill switch. `AuthService::login` rejects an
 * inactive account, but a token minted *before* an admin deactivated the user
 * would otherwise keep working until it expired — and Sanctum tokens do not
 * expire. Rejecting here means "deactivate this user" actually ends access on
 * the next request, and the offending token is deleted so a retry does not
 * re-resolve it. The thrown AuthenticationException renders as the same generic
 * 401 as any other bad credential, so this reveals nothing about the account.
 */
class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }

    /**
     * Signature must stay untyped: it widens the framework's own
     * `handle($request, Closure $next, ...$guards)`.
     */
    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);

        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            $user->currentAccessToken()?->delete();

            throw new AuthenticationException('Unauthenticated.', $guards);
        }

        return $next($request);
    }
}
