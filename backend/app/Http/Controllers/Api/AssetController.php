<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function __construct(private readonly AssetService $assetService) {}

    public function index(Request $request): JsonResponse
    {
        $assets = $this->assetService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => AssetResource::collection($assets->items()),
                'pagination' => [
                    'current_page' => $assets->currentPage(),
                    'per_page' => $assets->perPage(),
                    'total' => $assets->total(),
                    'last_page' => $assets->lastPage(),
                ],
            ],
            message: 'Assets retrieved successfully',
        );
    }

    public function show(Asset $asset): JsonResponse
    {
        $asset->load(['category', 'location', 'currentUser']);

        return $this->success(
            data: new AssetResource($asset),
            message: 'Asset retrieved successfully',
        );
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $asset = $this->assetService->create($request->validated(), $request->user());
        $asset->load(['category', 'location', 'currentUser']);

        return $this->success(
            data: new AssetResource($asset),
            message: 'Asset created successfully',
            status: 201,
        );
    }

    public function update(UpdateAssetRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->assetService->update($asset, $request->validated(), $request->user());
        $asset->load(['category', 'location', 'currentUser']);

        return $this->success(
            data: new AssetResource($asset),
            message: 'Asset updated successfully',
        );
    }

    public function destroy(Request $request, Asset $asset): JsonResponse
    {
        $this->assetService->delete($asset, $request->user());

        return $this->success(data: null, message: 'Asset deleted successfully');
    }

    /** @return array{search: string|null, asset_category_id: int|null, location_id: int|null, status: string|null, sort: string|null, direction: string|null, per_page: int|null} */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'asset_category_id' => $request->query('asset_category_id'),
            'location_id' => $request->query('location_id'),
            'status' => $request->query('status'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
