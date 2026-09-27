<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 21A §25 — CORS must stay a narrow allowlist.
 *
 * A wildcard method or header list is not a vulnerability on its own, but it
 * advertises to every browser exactly how much surface the API has, and it
 * silently widens again if a future endpoint is added. These tests fail loudly
 * when someone reintroduces `*` instead of the deliberate list.
 */
class CorsConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_ORIGIN = 'http://localhost:8100';

    public function test_configured_origins_are_an_explicit_allowlist(): void
    {
        $origins = config('cors.allowed_origins');

        $this->assertNotSame('*', $origins);
        $this->assertSame($origins, array_values(array_unique($origins)));

        foreach ($origins as $origin) {
            $this->assertMatchesRegularExpression(
                '#^https?://[a-z0-9.\-]+(:\d+)?$#i',
                (string) $origin,
                "CORS origin [{$origin}] must be an explicit http(s) origin, never a wildcard or regex."
            );
        }
    }

    public function test_allowed_methods_are_narrowed_to_the_verbs_the_api_uses(): void
    {
        $methods = config('cors.allowed_methods');

        $this->assertNotContains('*', $methods);
        $this->assertNotContains('PATCH', $methods, 'The v1 API has no PATCH routes.');
        $this->assertNotContains('TRACE', $methods);

        // Every verb the API actually serves must stay allowed, otherwise
        // legitimate cross-origin calls start failing.
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'] as $verb) {
            $this->assertContains($verb, $methods);
        }
    }

    public function test_allowed_headers_are_narrowed_and_include_authorization(): void
    {
        $headers = config('cors.allowed_headers');

        $this->assertNotContains('*', $headers);

        foreach (['Accept', 'Authorization', 'Content-Type'] as $header) {
            $this->assertContains($header, $headers, "Sanctum bearer auth needs [{$header}].");
        }
    }

    public function test_credentials_are_not_supported_for_bearer_auth(): void
    {
        // The API authenticates with an Authorization header, never a cookie,
        // so credentialed cross-origin requests are not supported on purpose.
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_preflight_advertises_only_the_allowlisted_methods_and_headers(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/assets', [], [], [], [
            'HTTP_ORIGIN' => self::ALLOWED_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type',
        ]);

        $response->assertSuccessful();

        $advertisedMethods = array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Allow-Methods')));
        $advertisedHeaders = array_map('trim', explode(',', (string) $response->headers->get('Access-Control-Allow-Headers')));

        $this->assertNotContains('*', $advertisedMethods);
        $this->assertNotContains('*', $advertisedHeaders);
        $this->assertContains('POST', $advertisedMethods);
        $this->assertNotEmpty(
            array_intersect(['authorization', 'Authorization'], $advertisedHeaders),
            'The preflight must keep advertising the bearer Authorization header.'
        );
        $this->assertSame(self::ALLOWED_ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_preflight_for_a_disallowed_verb_gets_no_allow_header(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/assets', [], [], [], [
            'HTTP_ORIGIN' => self::ALLOWED_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
        ]);

        $this->assertStringNotContainsString(
            'PATCH',
            (string) $response->headers->get('Access-Control-Allow-Methods')
        );
    }
}
