<?php

namespace App\Support\Exceptions;

use App\Exceptions\InvalidCredentialsException;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps exceptions to the NEXORA JSON envelope for API requests.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): JsonResponse
    {
        $status = $this->statusCode($exception);
        $errors = $exception instanceof ValidationException ? $exception->errors() : [];

        return ApiResponse::error(
            message: $this->message($exception, $status),
            errors: $errors,
            status: $status,
        );
    }

    private function statusCode(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof InvalidCredentialsException => 401,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof AuthorizationException => 403,
            $exception instanceof ValidationException => 422,
            $exception instanceof NotFoundHttpException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
    }

    private function message(Throwable $exception, int $status): string
    {
        $message = match (true) {
            $exception instanceof InvalidCredentialsException => 'Invalid credentials',
            $exception instanceof AuthenticationException => 'Unauthenticated.',
            $exception instanceof AuthorizationException => 'This action is unauthorized.',
            $exception instanceof ValidationException => 'Validation failed.',
            $exception instanceof NotFoundHttpException => 'The requested resource was not found.',
            $exception instanceof HttpExceptionInterface => $exception->getMessage(),
            default => 'Something went wrong.',
        };

        if ($status === 500 && config('app.debug')) {
            $message = $exception->getMessage();
        }

        return $message !== '' ? $message : 'Something went wrong.';
    }
}
