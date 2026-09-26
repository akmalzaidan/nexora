<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Inventory\StoreItemRequest;
use App\Http\Requests\Inventory\UpdateItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function __construct(private readonly ItemService $itemService) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->itemService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => ItemResource::collection($items->items()),
                'pagination' => [
                    'current_page' => $items->currentPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                    'last_page' => $items->lastPage(),
                ],
            ],
            message: 'Items retrieved successfully',
        );
    }

    public function show(Item $item): JsonResponse
    {
        $item = $this->itemService->show($item);

        return $this->success(
            data: new ItemResource($item),
            message: 'Item retrieved successfully',
        );
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->itemService->create($request->validated(), $request->user());
        $item->load(['category']);

        return $this->success(
            data: new ItemResource($item),
            message: 'Item created successfully',
            status: 201,
        );
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $item = $this->itemService->update($item, $request->validated(), $request->user());
        $item->load(['category']);

        return $this->success(
            data: new ItemResource($item),
            message: 'Item updated successfully',
        );
    }

    public function destroy(Request $request, Item $item): JsonResponse
    {
        $this->itemService->delete($item, $request->user());

        return $this->success(
            data: null,
            message: 'Item deleted successfully',
        );
    }

    /** @return array{search?: string|null, item_category_id?: int|null, warehouse_id?: int|null, is_active?: bool|null, sort?: string|null, direction?: string|null, per_page?: int} */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'item_category_id' => $request->query('item_category_id') !== null ? (int) $request->query('item_category_id') : null,
            'warehouse_id' => $request->query('warehouse_id') !== null ? (int) $request->query('warehouse_id') : null,
            'is_active' => $request->has('is_active') ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN) : null,
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
