<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Consistent JSON envelope for every NEXORA API response.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>|mixed  $data
     * @param  array<string, string>  $headers
     */
    public static function success(
        mixed $data = [],
        string $message = 'Operation successful',
        int $status = 200,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status, $headers);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $message = 'Something went wrong.',
        array $errors = [],
        int $status = 500,
        array $headers = [],
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status, $headers);
    }
}
