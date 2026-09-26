<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Maintenance\StoreMaintenancePartRequest;
use App\Http\Resources\MaintenancePartResource;
use App\Models\MaintenanceRecord;
use App\Services\MaintenancePartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenancePartController extends Controller
{
    public function __construct(private readonly MaintenancePartService $maintenancePartService) {}

    public function index(Request $request, MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        $parts = $this->maintenancePartService->paginate(
            $maintenanceRecord,
            ['per_page' => (int) $request->query('per_page', 15)],
            $request->user(),
        );

        return $this->success(
            data: [
                'items' => MaintenancePartResource::collection($parts->items()),
                'pagination' => [
                    'current_page' => $parts->currentPage(),
                    'per_page' => $parts->perPage(),
                    'total' => $parts->total(),
                    'last_page' => $parts->lastPage(),
                ],
            ],
            message: 'Maintenance parts retrieved successfully',
        );
    }

    public function store(StoreMaintenancePartRequest $request, MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        $part = $this->maintenancePartService->create(
            $request->validated(),
            $maintenanceRecord,
            $request->user(),
        );

        return $this->success(
            data: new MaintenancePartResource($part),
            message: 'Maintenance part created successfully',
            status: 201,
        );
    }
}
