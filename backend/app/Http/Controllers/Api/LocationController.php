<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Organization\StoreLocationRequest;
use App\Http\Requests\Organization\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function __construct(private readonly LocationService $locationService) {}

    public function index(Request $request): JsonResponse
    {
        $locations = $this->locationService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => LocationResource::collection($locations->items()),
                'pagination' => [
                    'current_page' => $locations->currentPage(),
                    'per_page' => $locations->perPage(),
                    'total' => $locations->total(),
                    'last_page' => $locations->lastPage(),
                ],
            ],
            message: 'Locations retrieved successfully',
        );
    }

    public function show(Location $location): JsonResponse
    {
        return $this->success(
            data: new LocationResource($location),
            message: 'Location retrieved successfully',
        );
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = $this->locationService->create($request->validated());

        return $this->success(
            data: new LocationResource($location),
            message: 'Location created successfully',
            status: 201,
        );
    }

    public function update(UpdateLocationRequest $request, Location $location): JsonResponse
    {
        $location = $this->locationService->update($location, $request->validated());

        return $this->success(
            data: new LocationResource($location),
            message: 'Location updated successfully',
        );
    }

    public function destroy(Location $location): JsonResponse
    {
        $this->locationService->delete($location);

        return $this->success(data: null, message: 'Location deleted successfully');
    }

    /**
     * @return array{search: string|null, is_active: bool|null, sort: string|null, direction: string|null, per_page: int|null}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active')
                ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
                : null,
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
