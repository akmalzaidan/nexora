<?php

namespace App\Http\Controllers\Api;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Base controller for API-v1 controllers.
 *
 * Holds only presentation helpers. Domain logic lives in services.
 */
abstract class Controller extends \App\Http\Controllers\Controller
{
    /**
     * @param  array<string, mixed>|mixed  $data
     */
    protected function success(
        mixed $data = [],
        string $message = 'Operation successful',
        int $status = 200,
    ): JsonResponse {
        return ApiResponse::success($data, $message, $status);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    protected function error(
        string $message = 'Something went wrong.',
        array $errors = [],
        int $status = 500,
    ): JsonResponse {
        return ApiResponse::error($message, $errors, $status);
    }
}
