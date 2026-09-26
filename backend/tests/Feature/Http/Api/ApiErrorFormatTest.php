<?php

namespace Tests\Feature\Http\Api;

use Tests\TestCase;

class ApiErrorFormatTest extends TestCase
{
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
}
