<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Notification inbox logic.
 *
 * Notifications are per-user rows produced by the domain services — never from
 * client input. Read state is derived from the nullable read_at column (null =
 * unread, stamped server-side on read), and every read path enforces ownership:
 * another user's notification is indistinguishable from a missing one (HTTP
 * 404), so the endpoint never leaks whether a notification exists.
 */
class NotificationService
{
    /**
     * @param  array{read?: string|null, type?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<Notification>
     */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $query = Notification::query()->where('user_id', $actor->id);

        $read = $filters['read'] ?? null;
        if ($read !== null && $read !== '') {
            if (filter_var($read, FILTER_VALIDATE_BOOL)) {
                $query->whereNotNull('read_at');
            } else {
                $query->whereNull('read_at');
            }
        }

        if (! empty($filters['type'])) {
            $query->where('type', (string) $filters['type']);
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    public function show(Notification $notification, User $actor): Notification
    {
        $this->assertOwnedBy($notification, $actor);

        return $notification;
    }

    public function unreadCount(User $actor): int
    {
        return Notification::query()
            ->where('user_id', $actor->id)
            ->whereNull('read_at')
            ->count();
    }

    public function markAsRead(Notification $notification, User $actor): Notification
    {
        $this->assertOwnedBy($notification, $actor);

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return $notification->refresh();
    }

    /**
     * Marks every unread notification of the actor as read. Idempotent: the
     * second call has nothing left to update and returns 0.
     */
    public function markAllAsRead(User $actor): int
    {
        return Notification::query()
            ->where('user_id', $actor->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Server-side notification creation for the domain services. Never
     * client-reachable: recipients are computed from domain state, not from
     * request input.
     *
     * @param  array<string, mixed>  $data
     */
    public function notify(int $userId, string $type, string $title, string $message, array $data = []): Notification
    {
        return DB::transaction(function () use ($userId, $type, $title, $message, $data): Notification {
            return Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'read_at' => null,
            ]);
        });
    }

    private function assertOwnedBy(Notification $notification, User $actor): void
    {
        if ((int) $notification->user_id !== (int) $actor->id) {
            abort(404, 'Notification not found');
        }
    }

    private function perPage(mixed $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
