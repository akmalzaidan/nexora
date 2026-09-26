<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission-based authorization. Usage: middleware('permission:manage_users').
 * A user passes if they hold any of the given permission slugs. Super admins
 * bypass permission checks (but never authentication).
 */
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (! $request->user()?->hasAnyPermission($permissions)) {
            abort(403, 'Access denied.');
        }

        return $next($request);
    }
}
