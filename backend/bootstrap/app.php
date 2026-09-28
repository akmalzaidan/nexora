<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Support\Exceptions\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/*
 * Render terminates TLS at its edge and forwards the real client address in
 * X-Forwarded-*. Laravel only auto-trusts Forge/Vapor/Laravel Cloud hosts, so a
 * deployed proxy has to be declared explicitly; without it Request::ip() is the
 * edge proxy and audit logs stop recording the real caller.
 *
 * TRUSTED_PROXIES is deliberately NOT defaulted to `*`. The Render hostname is
 * reachable directly, so trusting every proxy would let any client forge
 * X-Forwarded-For and write an arbitrary IP into the audit log. Unset means
 * trust nothing, which is the pre-deployment behaviour. Set it in Render to
 * the proxy CIDR list to opt in.
 */
$trustedProxies = env('TRUSTED_PROXIES');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) use ($trustedProxies): void {
        $middleware->alias([
            'auth' => Authenticate::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);

        if (filled($trustedProxies)) {
            $middleware->trustProxies(
                at: $trustedProxies,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return (new ApiExceptionRenderer)->render($exception, $request);
        });
    })->create();
