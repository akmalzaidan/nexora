<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Ticket\StoreTicketCategoryRequest;
use App\Http\Requests\Ticket\UpdateTicketCategoryRequest;
use App\Http\Resources\TicketCategoryResource;
use App\Models\TicketCategory;
use App\Services\TicketCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketCategoryController extends Controller
{
    public function __construct(private readonly TicketCategoryService $categoryService) {}

    public function index(Request $request): JsonResponse
    {
        $categories = $this->categoryService->paginate($this->filters($request));

        return $this->success(
            data: [
                'items' => TicketCategoryResource::collection($categories->items()),
                'pagination' => [
                    'current_page' => $categories->currentPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                    'last_page' => $categories->lastPage(),
                ],
            ],
            message: 'Ticket categories retrieved successfully',
        );
    }

    public function show(TicketCategory $ticketCategory): JsonResponse
    {
        return $this->success(
            data: new TicketCategoryResource($ticketCategory->loadCount('tickets')),
            message: 'Ticket category retrieved successfully',
        );
    }

    public function store(StoreTicketCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create($request->validated());

        return $this->success(
            data: new TicketCategoryResource($category->loadCount('tickets')),
            message: 'Ticket category created successfully',
            status: 201,
        );
    }

    public function update(UpdateTicketCategoryRequest $request, TicketCategory $ticketCategory): JsonResponse
    {
        $category = $this->categoryService->update($ticketCategory, $request->validated());

        return $this->success(
            data: new TicketCategoryResource($category->loadCount('tickets')),
            message: 'Ticket category updated successfully',
        );
    }

    public function destroy(TicketCategory $ticketCategory): JsonResponse
    {
        $this->categoryService->delete($ticketCategory);

        return $this->success(data: null, message: 'Ticket category deleted successfully');
    }

    /** @return array{search: string|null, sort: string|null, direction: string|null, per_page: int|null} */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
