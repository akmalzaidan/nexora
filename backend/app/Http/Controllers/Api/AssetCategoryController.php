<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Asset\StoreAssetCategoryRequest;
use App\Http\Requests\Asset\UpdateAssetCategoryRequest;
use App\Http\Resources\AssetCategoryResource;
use App\Models\AssetCategory;
use App\Services\AssetCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetCategoryController extends Controller
{
    public function __construct(private readonly AssetCategoryService $categoryService) {}

    public function index(Request $request): JsonResponse
    {
        $categories = $this->categoryService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => AssetCategoryResource::collection($categories->items()),
                'pagination' => [
                    'current_page' => $categories->currentPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                    'last_page' => $categories->lastPage(),
                ],
            ],
            message: 'Asset categories retrieved successfully',
        );
    }

    public function show(AssetCategory $assetCategory): JsonResponse
    {
        return $this->success(
            data: new AssetCategoryResource($assetCategory->loadCount('assets')),
            message: 'Asset category retrieved successfully',
        );
    }

    public function store(StoreAssetCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create($request->validated());

        return $this->success(
            data: new AssetCategoryResource($category->loadCount('assets')),
            message: 'Asset category created successfully',
            status: 201,
        );
    }

    public function update(UpdateAssetCategoryRequest $request, AssetCategory $assetCategory): JsonResponse
    {
        $category = $this->categoryService->update($assetCategory, $request->validated());

        return $this->success(
            data: new AssetCategoryResource($category->loadCount('assets')),
            message: 'Asset category updated successfully',
        );
    }

    public function destroy(AssetCategory $assetCategory): JsonResponse
    {
        $this->categoryService->delete($assetCategory);

        return $this->success(data: null, message: 'Asset category deleted successfully');
    }

    /** @return array{search: string|null, sort: string|null, direction: string|null, per_page: int|null} */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
