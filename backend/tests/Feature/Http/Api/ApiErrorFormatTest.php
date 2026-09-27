<?php

namespace Tests\Feature\Http\Api;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiErrorFormatTest extends TestCase
{
    private function registerThrowingRoute(): void
    {
        Route::middleware('api')->get('/api/v1/__error-probe/boom', function () {
            throw new RuntimeException('db password=hunter2-internal-only');
        });
    }

    public function test_missing_api_route_returns_json_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'errors' => [],
            ])
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_web_routes_are_not_forced_into_api_error_envelope(): void
    {
        $this->get('/')
            ->assertOk();
    }

    public function test_unexpected_exception_is_sanitized_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);
        $this->registerThrowingRoute();

        $response = $this->getJson('/api/v1/__error-probe/boom');

        $response->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Something went wrong.',
                'errors' => [],
            ]);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('hunter2-internal-only', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString(__FILE__, $body);
        $this->assertStringNotContainsString('trace', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    public function test_unexpected_exception_never_exposes_a_stack_trace_even_in_debug(): void
    {
        config(['app.debug' => true]);
        $this->registerThrowingRoute();

        $response = $this->getJson('/api/v1/__error-probe/boom');

        $response->assertStatus(500)
            ->assertJsonPath('success', false);

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('"trace"', $body);
        $this->assertStringNotContainsString('"exception"', $body);
        $this->assertStringNotContainsString('"file"', $body);
    }
}
