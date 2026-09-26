<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function index(Request $request): JsonResponse
    {
        $notifications = $this->notificationService->paginate($this->filters($request), $request->user());

        return $this->success(
            data: [
                'items' => NotificationResource::collection($notifications->items()),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'last_page' => $notifications->lastPage(),
                ],
            ],
            message: 'Notifications retrieved successfully',
        );
    }

    public function show(Request $request, Notification $notification): JsonResponse
    {
        return $this->success(
            data: new NotificationResource($this->notificationService->show($notification, $request->user())),
            message: 'Notification retrieved successfully',
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success(
            data: ['count' => $this->notificationService->unreadCount($request->user())],
            message: 'Unread notification count retrieved successfully',
        );
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        return $this->success(
            data: new NotificationResource($this->notificationService->markAsRead($notification, $request->user())),
            message: 'Notification marked as read',
        );
    }

    public function readAll(Request $request): JsonResponse
    {
        return $this->success(
            data: ['count' => $this->notificationService->markAllAsRead($request->user())],
            message: 'All notifications marked as read',
        );
    }

    /** @return array{read?: string|null, type?: string|null, per_page: int|null} */
    private function filters(Request $request): array
    {
        $read = $request->query('read');
        $read = $read === null ? null : (string) $read;

        return [
            'read' => $read,
            'type' => $request->query('type'),
            'per_page' => (int) $request->query('per_page', 15),
        ];
    }
}
