<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Inventory\StoreStockMovementRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\StockMovement;
use App\Services\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function __construct(private readonly StockMovementService $stockMovementService) {}

    public function index(Request $request): JsonResponse
    {
        $movements = $this->stockMovementService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => StockMovementResource::collection($movements->items()),
                'pagination' => [
                    'current_page' => $movements->currentPage(),
                    'per_page' => $movements->perPage(),
                    'total' => $movements->total(),
                    'last_page' => $movements->lastPage(),
                ],
            ],
            message: 'Stock movements retrieved successfully',
        );
    }

    public function show(StockMovement $stockMovement): JsonResponse
    {
        $stockMovement->load(['item', 'warehouse', 'performer']);

        return $this->success(
            data: new StockMovementResource($stockMovement),
            message: 'Stock movement retrieved successfully',
        );
    }

    public function store(StoreStockMovementRequest $request): JsonResponse
    {
        $movement = $this->stockMovementService->create(
            $request->validated(),
            (int) $request->user()->id,
        );
        $movement->load(['item', 'warehouse', 'performer']);

        return $this->success(
            data: new StockMovementResource($movement),
            message: 'Stock movement recorded successfully',
            status: 201,
        );
    }

    /** @return array{item_id?: int|null, warehouse_id?: int|null, type?: string|null, sort?: string|null, direction?: string|null, per_page?: int} */
    private function filters(Request $request): array
    {
        return [
            'item_id' => $request->query('item_id') !== null ? (int) $request->query('item_id') : null,
            'warehouse_id' => $request->query('warehouse_id') !== null ? (int) $request->query('warehouse_id') : null,
            'type' => $request->query('type'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
