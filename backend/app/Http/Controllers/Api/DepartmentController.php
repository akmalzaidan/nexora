<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Organization\StoreDepartmentRequest;
use App\Http\Requests\Organization\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $departmentService) {}

    public function index(Request $request): JsonResponse
    {
        $departments = $this->departmentService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => DepartmentResource::collection($departments->items()),
                'pagination' => [
                    'current_page' => $departments->currentPage(),
                    'per_page' => $departments->perPage(),
                    'total' => $departments->total(),
                    'last_page' => $departments->lastPage(),
                ],
            ],
            message: 'Departments retrieved successfully',
        );
    }

    public function show(Department $department): JsonResponse
    {
        return $this->success(
            data: new DepartmentResource(
                $department->load('manager')->loadCount('users'),
            ),
            message: 'Department retrieved successfully',
        );
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->departmentService->create($request->validated());

        return $this->success(
            data: new DepartmentResource($department),
            message: 'Department created successfully',
            status: 201,
        );
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department = $this->departmentService->update($department, $request->validated());

        return $this->success(
            data: new DepartmentResource($department),
            message: 'Department updated successfully',
        );
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->departmentService->delete($department);

        return $this->success(data: null, message: 'Department deleted successfully');
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
