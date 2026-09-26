<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Asset\StoreAssetAssignmentRequest;
use App\Http\Resources\AssetAssignmentResource;
use App\Models\AssetAssignment;
use App\Services\AssetAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssetAssignmentController extends Controller
{
    public function __construct(private readonly AssetAssignmentService $assignmentService) {}

    public function index(Request $request): JsonResponse
    {
        $assignments = $this->assignmentService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => AssetAssignmentResource::collection($assignments->items()),
                'pagination' => [
                    'current_page' => $assignments->currentPage(),
                    'per_page' => $assignments->perPage(),
                    'total' => $assignments->total(),
                    'last_page' => $assignments->lastPage(),
                ],
            ],
            message: 'Asset assignments retrieved successfully',
        );
    }

    public function show(AssetAssignment $assetAssignment): JsonResponse
    {
        $assetAssignment->load(['asset', 'user', 'requester', 'location']);

        return $this->success(
            data: new AssetAssignmentResource($assetAssignment),
            message: 'Asset assignment retrieved successfully',
        );
    }

    public function store(StoreAssetAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignmentService->create(
            $request->validated(),
            $request->user()->id,
        );

        return $this->success(
            data: new AssetAssignmentResource($assignment),
            message: 'Asset assigned successfully',
            status: 201,
        );
    }

    public function return(Request $request, AssetAssignment $assetAssignment): JsonResponse
    {
        $assignment = $this->assignmentService->returnAssignment($assetAssignment, $request->user()->id);

        return $this->success(
            data: new AssetAssignmentResource($assignment),
            message: 'Asset returned successfully',
        );
    }

    /** @return array{search?: string|null, asset_id?: int|null, user_id?: int|null, location_id?: int|null, status?: string|null, sort?: string|null, direction?: string|null, per_page?: int|null} */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'asset_id' => $request->query('asset_id'),
            'user_id' => $request->query('user_id'),
            'location_id' => $request->query('location_id'),
            'status' => $request->query('status'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
