<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserManagementResource;
use App\Models\User;
use App\Services\UserManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserManagementService $userService) {}

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => UserManagementResource::collection($users->items()),
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'last_page' => $users->lastPage(),
                ],
            ],
            message: 'Users retrieved successfully',
        );
    }

    public function show(User $user): JsonResponse
    {
        return $this->success(
            data: new UserManagementResource($this->userService->find($user)),
            message: 'User retrieved successfully',
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated(), $request->user());

        return $this->success(
            data: new UserManagementResource($user),
            message: 'User created successfully',
            status: 201,
        );
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->update($user, $request->validated(), $request->user());

        return $this->success(
            data: new UserManagementResource($user),
            message: 'User updated successfully',
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->userService->delete($user, $request->user());

        return $this->success(data: null, message: 'User deleted successfully');
    }

    /**
     * @return array{search: string|null, is_active: bool|null, role_id: string|null, department_id: string|null, sort: string|null, direction: string|null, per_page: int}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active')
                ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
                : null,
            'role_id' => $request->query('role_id'),
            'department_id' => $request->query('department_id'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
