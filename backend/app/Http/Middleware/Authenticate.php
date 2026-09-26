<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

/**
 * API-only authentication middleware.
 *
 * Never redirects to a web login route; unauthenticated API requests always
 * surface a 401 (which the exception renderer turns into the JSON envelope).
 */
class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
