<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Ticket\StoreTicketCommentRequest;
use App\Http\Requests\Ticket\StoreTicketRequest;
use App\Http\Requests\Ticket\UpdateTicketRequest;
use App\Http\Resources\TicketCommentResource;
use App\Http\Resources\TicketHistoryResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $ticketService) {}

    public function index(Request $request): JsonResponse
    {
        $tickets = $this->ticketService->paginate($this->filters($request), $request->user());

        return $this->success(
            data: [
                'items' => TicketResource::collection($tickets->items()),
                'pagination' => [
                    'current_page' => $tickets->currentPage(),
                    'per_page' => $tickets->perPage(),
                    'total' => $tickets->total(),
                    'last_page' => $tickets->lastPage(),
                ],
            ],
            message: 'Tickets retrieved successfully',
        );
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        return $this->success(
            data: new TicketResource($this->ticketService->show($ticket, $request->user())),
            message: 'Ticket retrieved successfully',
        );
    }

    public function store(StoreTicketRequest $request): JsonResponse
    {
        $ticket = $this->ticketService->create($request->validated(), $request->user());

        return $this->success(
            data: new TicketResource($ticket),
            message: 'Ticket created successfully',
            status: 201,
        );
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket): JsonResponse
    {
        $ticket = $this->ticketService->update($ticket, $request->validated(), $request->user());

        return $this->success(
            data: new TicketResource($ticket),
            message: 'Ticket updated successfully',
        );
    }

    public function comments(Request $request, Ticket $ticket): JsonResponse
    {
        $comments = $this->ticketService->comments(
            $ticket,
            ['per_page' => (int) $request->query('per_page', 15)],
            $request->user(),
        );

        return $this->success(
            data: [
                'items' => TicketCommentResource::collection($comments->items()),
                'pagination' => [
                    'current_page' => $comments->currentPage(),
                    'per_page' => $comments->perPage(),
                    'total' => $comments->total(),
                    'last_page' => $comments->lastPage(),
                ],
            ],
            message: 'Ticket comments retrieved successfully',
        );
    }

    public function storeComment(StoreTicketCommentRequest $request, Ticket $ticket): JsonResponse
    {
        $comment = $this->ticketService->storeComment($ticket, $request->validated(), $request->user());

        return $this->success(
            data: new TicketCommentResource($comment),
            message: 'Ticket comment created successfully',
            status: 201,
        );
    }

    public function history(Request $request, Ticket $ticket): JsonResponse
    {
        $history = $this->ticketService->history(
            $ticket,
            ['per_page' => (int) $request->query('per_page', 15)],
            $request->user(),
        );

        return $this->success(
            data: [
                'items' => TicketHistoryResource::collection($history->items()),
                'pagination' => [
                    'current_page' => $history->currentPage(),
                    'per_page' => $history->perPage(),
                    'total' => $history->total(),
                    'last_page' => $history->lastPage(),
                ],
            ],
            message: 'Ticket history retrieved successfully',
        );
    }

    /** @return array{search?: string|null, status?: string|null, priority?: string|null, category_id?: int|string|null, requester_id?: int|string|null, assigned_to?: int|string|null, department_id?: int|string|null, location_id?: int|string|null, sort?: string|null, direction?: string|null, per_page: int|null} */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'category_id' => $request->query('category_id'),
            'requester_id' => $request->query('requester_id'),
            'assigned_to' => $request->query('assigned_to'),
            'department_id' => $request->query('department_id'),
            'location_id' => $request->query('location_id'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
