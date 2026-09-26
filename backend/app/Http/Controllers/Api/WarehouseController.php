<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Inventory\StoreWarehouseRequest;
use App\Http\Requests\Inventory\UpdateWarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Services\WarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function __construct(private readonly WarehouseService $warehouseService) {}

    public function index(Request $request): JsonResponse
    {
        $warehouses = $this->warehouseService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => WarehouseResource::collection($warehouses->items()),
                'pagination' => [
                    'current_page' => $warehouses->currentPage(),
                    'per_page' => $warehouses->perPage(),
                    'total' => $warehouses->total(),
                    'last_page' => $warehouses->lastPage(),
                ],
            ],
            message: 'Warehouses retrieved successfully',
        );
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        $warehouse->load(['location']);

        return $this->success(
            data: new WarehouseResource($warehouse),
            message: 'Warehouse retrieved successfully',
        );
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $warehouse = $this->warehouseService->create($request->validated(), $request->user());
        $warehouse->load(['location']);

        return $this->success(
            data: new WarehouseResource($warehouse),
            message: 'Warehouse created successfully',
            status: 201,
        );
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $warehouse = $this->warehouseService->update($warehouse, $request->validated(), $request->user());
        $warehouse->load(['location']);

        return $this->success(
            data: new WarehouseResource($warehouse),
            message: 'Warehouse updated successfully',
        );
    }

    public function destroy(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->warehouseService->delete($warehouse, $request->user());

        return $this->success(
            data: null,
            message: 'Warehouse deleted successfully',
        );
    }

    /** @return array{search?: string|null, location_id?: int|null, is_active?: bool|null, sort?: string|null, direction?: string|null, per_page?: int} */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'location_id' => $request->query('location_id') !== null ? (int) $request->query('location_id') : null,
            'is_active' => $request->has('is_active') ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN) : null,
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
