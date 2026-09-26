<?php

namespace Tests\Unit\Support;

use App\Support\Exceptions\ApiExceptionRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

class ApiExceptionRendererTest extends TestCase
{
    private function render(\Throwable $exception): array
    {
        $response = (new ApiExceptionRenderer)->render($exception, Request::create('/api/v1/test'));

        return [
            'status' => $response->getStatusCode(),
            'payload' => $response->getData(true),
        ];
    }

    public function test_renders_unauthenticated_as_401(): void
    {
        ['status' => $status, 'payload' => $payload] = $this->render(new AuthenticationException);

        $this->assertSame(401, $status);
        $this->assertSame('Unauthenticated.', $payload['message']);
        $this->assertSame(false, $payload['success']);
    }

    public function test_renders_forbidden_as_403(): void
    {
        ['status' => $status] = $this->render(new AuthorizationException);

        $this->assertSame(403, $status);
    }

    public function test_renders_validation_as_422_with_errors(): void
    {
        $exception = ValidationException::withMessages([
            'email' => ['The email field is required.'],
        ]);

        ['status' => $status, 'payload' => $payload] = $this->render($exception);

        $this->assertSame(422, $status);
        $this->assertSame('Validation failed.', $payload['message']);
        $this->assertSame(['email' => ['The email field is required.']], $payload['errors']);
    }

    public function test_renders_not_found_as_404(): void
    {
        ['status' => $status, 'payload' => $payload] = $this->render(new NotFoundHttpException);

        $this->assertSame(404, $status);
        $this->assertSame('The requested resource was not found.', $payload['message']);
    }

    public function test_renders_throttle_as_429(): void
    {
        ['status' => $status] = $this->render(new TooManyRequestsHttpException);

        $this->assertSame(429, $status);
    }

    public function test_renders_unexpected_exception_as_500(): void
    {
        ['status' => $status, 'payload' => $payload] = $this->render(new \RuntimeException('boom'));

        $this->assertSame(500, $status);
        $this->assertSame(false, $payload['success']);
        $this->assertSame([], $payload['errors']);
    }
}
