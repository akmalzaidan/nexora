<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Inventory\StoreItemCategoryRequest;
use App\Http\Requests\Inventory\UpdateItemCategoryRequest;
use App\Http\Resources\ItemCategoryResource;
use App\Models\ItemCategory;
use App\Services\ItemCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemCategoryController extends Controller
{
    public function __construct(private readonly ItemCategoryService $itemCategoryService) {}

    public function index(Request $request): JsonResponse
    {
        $itemCategories = $this->itemCategoryService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => ItemCategoryResource::collection($itemCategories->items()),
                'pagination' => [
                    'current_page' => $itemCategories->currentPage(),
                    'per_page' => $itemCategories->perPage(),
                    'total' => $itemCategories->total(),
                    'last_page' => $itemCategories->lastPage(),
                ],
            ],
            message: 'Item categories retrieved successfully',
        );
    }

    public function show(ItemCategory $itemCategory): JsonResponse
    {
        $itemCategory->loadCount('items');

        return $this->success(
            data: new ItemCategoryResource($itemCategory),
            message: 'Item category retrieved successfully',
        );
    }

    public function store(StoreItemCategoryRequest $request): JsonResponse
    {
        $itemCategory = $this->itemCategoryService->create($request->validated(), $request->user());
        $itemCategory->loadCount('items');

        return $this->success(
            data: new ItemCategoryResource($itemCategory),
            message: 'Item category created successfully',
            status: 201,
        );
    }

    public function update(UpdateItemCategoryRequest $request, ItemCategory $itemCategory): JsonResponse
    {
        $itemCategory = $this->itemCategoryService->update($itemCategory, $request->validated(), $request->user());
        $itemCategory->loadCount('items');

        return $this->success(
            data: new ItemCategoryResource($itemCategory),
            message: 'Item category updated successfully',
        );
    }

    public function destroy(Request $request, ItemCategory $itemCategory): JsonResponse
    {
        $this->itemCategoryService->delete($itemCategory, $request->user());

        return $this->success(
            data: null,
            message: 'Item category deleted successfully',
        );
    }

    /** @return array{search?: string|null, sort?: string|null, direction?: string|null, per_page?: int} */
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
