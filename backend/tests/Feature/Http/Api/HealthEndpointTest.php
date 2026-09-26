<?php

namespace Tests\Feature\Http\Api;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_successful_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'NEXORA API is healthy')
            ->assertJsonPath('data.application', 'NEXORA')
            ->assertJsonPath('data.version', '1.0.0')
            ->assertJsonPath('data.environment', app()->environment());
    }
}
