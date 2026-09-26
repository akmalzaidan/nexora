<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Services\AssetQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetQrController extends Controller
{
    public function __construct(private readonly AssetQrService $qrService) {}

    public function metadata(Request $request, Asset $asset): JsonResponse
    {
        return $this->success(
            data: [
                'asset_id' => $asset->id,
                'identifier' => $asset->asset_code,
                'payload' => $this->qrService->payload($asset),
            ],
            message: 'Asset QR retrieved successfully',
        );
    }

    public function lookup(Request $request, string $identifier): JsonResponse
    {
        $asset = $this->qrService->resolveOrFail($identifier);

        return $this->success(
            data: new AssetResource($asset->load(['category', 'location', 'currentUser'])),
            message: 'Asset identified successfully',
        );
    }
}
