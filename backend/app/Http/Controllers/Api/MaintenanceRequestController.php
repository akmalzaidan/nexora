<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Maintenance\StoreMaintenanceRequestRequest;
use App\Http\Requests\Maintenance\UpdateMaintenanceRequestRequest;
use App\Http\Resources\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use App\Services\MaintenanceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceRequestController extends Controller
{
    public function __construct(private readonly MaintenanceRequestService $maintenanceRequestService) {}

    public function index(Request $request): JsonResponse
    {
        $requests = $this->maintenanceRequestService->paginate($this->filters($request), $request->user());

        return $this->success(
            data: [
                'items' => MaintenanceRequestResource::collection($requests->items()),
                'pagination' => [
                    'current_page' => $requests->currentPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                    'last_page' => $requests->lastPage(),
                ],
            ],
            message: 'Maintenance requests retrieved successfully',
        );
    }

    public function show(Request $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        return $this->success(
            data: new MaintenanceRequestResource($this->maintenanceRequestService->show($maintenanceRequest, $request->user())),
            message: 'Maintenance request retrieved successfully',
        );
    }

    public function store(StoreMaintenanceRequestRequest $request): JsonResponse
    {
        $maintenanceRequest = $this->maintenanceRequestService->create($request->validated(), $request->user());

        return $this->success(
            data: new MaintenanceRequestResource($maintenanceRequest),
            message: 'Maintenance request created successfully',
            status: 201,
        );
    }

    public function update(UpdateMaintenanceRequestRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $maintenanceRequest = $this->maintenanceRequestService->update(
            $maintenanceRequest,
            $request->validated(),
            $request->user(),
        );

        return $this->success(
            data: new MaintenanceRequestResource($maintenanceRequest),
            message: 'Maintenance request updated successfully',
        );
    }

    /** @return array{search?: string|null, status?: string|null, priority?: string|null, asset_id?: int|string|null, requester_id?: int|string|null, assigned_to?: int|string|null, location_id?: int|string|null, requested_from?: string|null, requested_to?: string|null, sort?: string|null, direction?: string|null, per_page: int|null} */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'asset_id' => $request->query('asset_id'),
            'requester_id' => $request->query('requester_id'),
            'assigned_to' => $request->query('assigned_to'),
            'location_id' => $request->query('location_id'),
            'requested_from' => $request->query('requested_from'),
            'requested_to' => $request->query('requested_to'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
