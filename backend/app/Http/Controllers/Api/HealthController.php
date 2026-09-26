<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return $this->success(
            data: [
                'application' => config('nexora.name'),
                'version' => config('nexora.version'),
                'environment' => app()->environment(),
            ],
            message: 'NEXORA API is healthy',
        );
    }
}
