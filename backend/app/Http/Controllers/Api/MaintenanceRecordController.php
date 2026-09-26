<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Maintenance\StoreMaintenanceRecordRequest;
use App\Http\Requests\Maintenance\UpdateMaintenanceRecordRequest;
use App\Http\Resources\MaintenanceRecordResource;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Services\MaintenanceRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceRecordController extends Controller
{
    public function __construct(private readonly MaintenanceRecordService $maintenanceRecordService) {}

    public function index(Request $request): JsonResponse
    {
        $records = $this->maintenanceRecordService->paginate($this->filters($request), $request->user());

        return $this->success(
            data: [
                'items' => MaintenanceRecordResource::collection($records->items()),
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
            message: 'Maintenance records retrieved successfully',
        );
    }

    public function store(StoreMaintenanceRecordRequest $request): JsonResponse
    {
        $maintenanceRequest = MaintenanceRequest::query()->findOrFail((int) $request->validated()['maintenance_request_id']);

        $record = $this->maintenanceRecordService->create(
            $request->validated(),
            $maintenanceRequest,
            $request->user(),
        );

        return $this->success(
            data: new MaintenanceRecordResource($record),
            message: 'Maintenance record created successfully',
            status: 201,
        );
    }

    public function show(Request $request, MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        return $this->success(
            data: new MaintenanceRecordResource($this->maintenanceRecordService->show($maintenanceRecord, $request->user())),
            message: 'Maintenance record retrieved successfully',
        );
    }

    public function update(UpdateMaintenanceRecordRequest $request, MaintenanceRecord $maintenanceRecord): JsonResponse
    {
        $maintenanceRecord = $this->maintenanceRecordService->update(
            $maintenanceRecord,
            $request->validated(),
            $request->user(),
        );

        return $this->success(
            data: new MaintenanceRecordResource($maintenanceRecord),
            message: 'Maintenance record updated successfully',
        );
    }

    /** @return array{maintenance_request_id?: int|string|null, asset_id?: int|string|null, technician_id?: int|string|null, sort?: string|null, direction?: string|null, per_page: int|null} */
    private function filters(Request $request): array
    {
        return [
            'maintenance_request_id' => $request->query('maintenance_request_id'),
            'asset_id' => $request->query('asset_id'),
            'technician_id' => $request->query('technician_id'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
