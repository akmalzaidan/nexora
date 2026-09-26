<?php

namespace Tests\Unit\Support;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_success_builds_the_envelope(): void
    {
        $response = ApiResponse::success(['id' => 1], 'Created, baby', 201);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(201, $response->getStatusCode());

        $payload = $response->getData(true);

        $this->assertSame([
            'success' => true,
            'message' => 'Created, baby',
            'data' => ['id' => 1],
        ], $payload);
    }

    public function test_success_uses_defaults(): void
    {
        $response = ApiResponse::success([]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'Operation successful',
            'data' => [],
        ], $response->getData(true));
    }

    public function test_error_builds_the_envelope(): void
    {
        $response = ApiResponse::error('Validation failed.', ['email' => ['The email field is required.']], 422);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => ['email' => ['The email field is required.']],
        ], $response->getData(true));
    }
}
