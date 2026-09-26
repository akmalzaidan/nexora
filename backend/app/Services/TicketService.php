<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketHistory;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Ticket domain logic.
 *
 * The ticket lifecycle is explicit: OPEN -> IN_PROGRESS -> RESOLVED -> CLOSED,
 * with CLOSED -> OPEN as the only way back (reopen). Every status and
 * assignment change writes an append-only entry into ticket_histories inside
 * the same transaction that changes the ticket, so the timeline always
 * matches the current state.
 *
 * Visibility: users holding manage_tickets or assign_tickets (agents) can see
 * and handle every ticket; users with only view_tickets (staff) can only see
 * and comment on the tickets they raised themselves.
 */
class TicketService
{
    private const SORTABLE_COLUMNS = ['ticket_number', 'title', 'priority', 'status', 'created_at', 'updated_at'];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Allowed status transitions. A transition is a no-op when the status does
     * not change; anything else that is not listed here is rejected with 422.
     */
    private const STATUS_TRANSITIONS = [
        Ticket::STATUS_OPEN => [Ticket::STATUS_IN_PROGRESS],
        Ticket::STATUS_IN_PROGRESS => [Ticket::STATUS_RESOLVED],
        Ticket::STATUS_RESOLVED => [Ticket::STATUS_CLOSED],
        Ticket::STATUS_CLOSED => [Ticket::STATUS_OPEN],
    ];

    /** Read-path relations; requester/assignee carry role + department for the UserResource. */
    private const EAGER_LOAD = [
        'requester.role',
        'requester.department',
        'assignee.role',
        'assignee.department',
        'category',
        'department',
        'location',
    ];

    /**
     * @param  array{search?: string|null, status?: string|null, priority?: string|null, category_id?: int|string|null, requester_id?: int|string|null, assigned_to?: int|string|null, department_id?: int|string|null, location_id?: int|string|null, sort?: string|null, direction?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<Ticket>
     */
    public function paginate(array $filters = [], ?User $actor = null): LengthAwarePaginator
    {
        $query = Ticket::query()->with(self::EAGER_LOAD);

        if (! $this->isAgent($actor)) {
            $query->where('requester_id', $actor?->id);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereLike('ticket_number', "%{$search}%")
                    ->orWhereLike('title', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', (string) $filters['priority']);
        }

        foreach (['category_id', 'requester_id', 'assigned_to', 'department_id', 'location_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, (int) $filters[$field]);
            }
        }

        $requested = $filters['sort'] ?? 'created_at';
        $sort = in_array($requested, self::SORTABLE_COLUMNS, true) ? $requested : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    public function show(Ticket $ticket, User $actor): Ticket
    {
        $this->assertCanView($ticket, $actor);

        return $ticket->load(self::EAGER_LOAD);
    }

    /**
     * Create a ticket on behalf of the authenticated user. The requester is
     * always the actor (never client-supplied) and the ticket number is always
     * generated server-side, so a client cannot spoof ownership or take an
     * existing number.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Ticket
    {
        return DB::transaction(function () use ($data, $actor): Ticket {
            $ticket = Ticket::create([
                'ticket_number' => $this->generateTicketNumber(),
                'title' => $data['title'],
                'description' => $data['description'],
                'category_id' => $data['category_id'] ?? null,
                'requester_id' => $actor->id,
                'assigned_to' => null,
                'department_id' => $data['department_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
                'priority' => $data['priority'] ?? Ticket::PRIORITY_MEDIUM,
                'status' => Ticket::STATUS_OPEN,
                'closed_at' => null,
            ]);

            $this->recordHistory($ticket, $actor, TicketHistory::ACTION_CREATED, notes: 'Ticket raised');

            $this->auditLogs->recordEvent(
                AuditAction::CREATED,
                AuditResourceType::TICKET,
                $ticket->id,
                $actor,
                "Ticket {$ticket->ticket_number} raised",
                newValues: ['ticket_number' => $ticket->ticket_number, 'priority' => $ticket->priority, 'status' => $ticket->status],
            );

            return $ticket->load(self::EAGER_LOAD);
        });
    }

    /**
     * Update editable fields, run the status transition, and (for users with
     * assign_tickets) change the assignee. Each change writes history in the
     * same transaction while the ticket row is locked, so a concurrent update
     * cannot produce a divergent timeline.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Ticket $ticket, array $data, User $actor): Ticket
    {
        return DB::transaction(function () use ($ticket, $data, $actor): Ticket {
            $locked = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);

            $statusChanged = false;
            $assigneeId = null;
            // Pre-captured for the audit row: getOriginal() is reset on save.
            $originalStatus = $locked->status;
            $originalAssignee = $locked->assigned_to;

            if (array_key_exists('status', $data) && $data['status'] !== $locked->status) {
                $this->assertTransition((string) $locked->status, (string) $data['status']);
                $this->recordHistory(
                    $locked,
                    $actor,
                    TicketHistory::ACTION_STATUS_CHANGED,
                    $locked->status,
                    (string) $data['status'],
                );
                $locked->status = (string) $data['status'];
                $locked->closed_at = $data['status'] === Ticket::STATUS_CLOSED ? now() : null;
                $statusChanged = true;
            }

            if (array_key_exists('assigned_to', $data)
                && (int) $data['assigned_to'] !== (int) $locked->getOriginal('assigned_to')) {
                $this->assertCanAssign($actor);
                $assigneeId = $data['assigned_to'] !== null ? (int) $data['assigned_to'] : null;
                if ($assigneeId !== null) {
                    $this->assertAssignableUser($assigneeId);
                }
                $this->recordHistory(
                    $locked,
                    $actor,
                    TicketHistory::ACTION_ASSIGNMENT_CHANGED,
                    notes: $this->assignmentNote($assigneeId),
                );
                $locked->assigned_to = $assigneeId;
            }

            $fieldNotes = $this->applyFieldChanges($locked, $data);

            if ($locked->isDirty()) {
                $locked->save();
            }

            if ($fieldNotes !== []) {
                $this->recordHistory($locked, $actor, TicketHistory::ACTION_UPDATED, notes: implode('; ', $fieldNotes));
            }

            // One governance audit row per committed update, describing what
            // actually changed — status, assignment, or field edits.
            if ($statusChanged) {
                $this->auditLogs->recordEvent(
                    AuditAction::STATUS_CHANGED,
                    AuditResourceType::TICKET,
                    $locked->id,
                    $actor,
                    "Ticket {$locked->ticket_number} status changed from {$originalStatus} to {$locked->status}",
                    oldValues: ['status' => $originalStatus],
                    newValues: ['status' => $locked->status],
                );
            } elseif ($assigneeId !== null || array_key_exists('assigned_to', $data)) {
                $this->auditLogs->recordEvent(
                    AuditAction::ASSIGNED,
                    AuditResourceType::TICKET,
                    $locked->id,
                    $actor,
                    "Ticket {$locked->ticket_number} assignment changed to ".($assigneeId !== null ? "user #{$assigneeId}" : 'unassigned'),
                    oldValues: ['assigned_to' => $originalAssignee],
                    newValues: ['assigned_to' => $assigneeId],
                );
            } elseif ($fieldNotes !== []) {
                $this->auditLogs->recordUpdated(
                    AuditResourceType::TICKET,
                    $locked->id,
                    $actor,
                    "Ticket {$locked->ticket_number} updated ({$fieldNotes[0]})",
                    newValues: ['changed_fields' => implode(', ', array_map(
                        fn (string $note): string => (string) str_contains($note, ' changed from ') ? explode(' ', $note)[0] : $note,
                        $fieldNotes,
                    ))],
                );
            }

            $this->notifyOnTicketChanges($locked, $statusChanged, $assigneeId, $actor);

            return $locked->load(self::EAGER_LOAD);
        });
    }

    /**
     * @param  array{per_page?: int|null}  $filters
     * @return LengthAwarePaginator<TicketComment>
     */
    public function comments(Ticket $ticket, array $filters, User $actor): LengthAwarePaginator
    {
        $this->assertCanView($ticket, $actor);

        $query = $ticket->comments()->with(['user.role', 'user.department']);

        if (! $this->isAgent($actor)) {
            $query->where('is_internal', false);
        }

        return $query->latest('created_at')
            ->paginate($this->perPage($filters['per_page'] ?? null))
            ->withQueryString();
    }

    /**
     * Comment author is always the authenticated user. Only agents may mark a
     * comment as internal; staff-supplied values are ignored so a requester
     * cannot hide a comment from the agent working the ticket.
     *
     * @param  array<string, mixed>  $data
     */
    public function storeComment(Ticket $ticket, array $data, User $actor): TicketComment
    {
        $this->assertCanView($ticket, $actor);

        return TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'comment' => $data['comment'],
            'is_internal' => $this->isAgent($actor) && ($data['is_internal'] ?? false),
        ])->load(['user.role', 'user.department']);
    }

    /**
     * Append-only timeline. Replaying the rows reproduces every status and
     * assignment change in order; there are no update/delete endpoints.
     *
     * @param  array{per_page?: int|null}  $filters
     * @return LengthAwarePaginator<TicketHistory>
     */
    public function history(Ticket $ticket, array $filters, User $actor): LengthAwarePaginator
    {
        $this->assertCanView($ticket, $actor);

        return $ticket->histories()
            ->with(['user.role', 'user.department'])
            ->oldest('created_at')
            ->oldest('id')
            ->paginate($this->perPage($filters['per_page'] ?? null))
            ->withQueryString();
    }

    /**
     * Agents see every ticket. Everyone else (staff) only has access to the
     * tickets they raised themselves.
     */
    private function isAgent(?User $actor): bool
    {
        return $actor?->hasAnyPermission(['manage_tickets', 'assign_tickets']) ?? false;
    }

    private function assertCanView(Ticket $ticket, User $actor): void
    {
        if ($this->isAgent($actor)) {
            return;
        }

        if ((int) $ticket->requester_id !== (int) $actor->id) {
            abort(403, 'You do not have access to this ticket');
        }
    }

    private function assertCanAssign(User $actor): void
    {
        if (! $actor->hasPermission('assign_tickets')) {
            abort(403, 'You do not have permission to assign tickets');
        }
    }

    private function assertAssignableUser(int $assigneeId): void
    {
        $assignee = User::find($assigneeId);

        if (! $assignee) {
            throw new UnprocessableEntityHttpException('Assigned user does not exist');
        }

        if (! $assignee->is_active) {
            throw new UnprocessableEntityHttpException('Cannot assign a ticket to an inactive user');
        }

        if (! $assignee->hasPermission('assign_tickets')) {
            throw new UnprocessableEntityHttpException('Assigned user is not allowed to handle tickets');
        }
    }

    private function assertTransition(string $from, string $to): void
    {
        if (in_array($to, self::STATUS_TRANSITIONS[$from] ?? [], true)) {
            return;
        }

        throw new UnprocessableEntityHttpException("Invalid status transition from {$from} to {$to}");
    }

    /**
     * Change-detection idempotence: only a real status or assignment change
     * (already guaranteed by the guards in update()) produces a notification,
     * and it is written inside the same transaction as the ticket mutation, so
     * a replayed or concurrent-successful update never duplicates a row. The
     * acting user is never notified about their own action.
     */
    private function notifyOnTicketChanges(Ticket $ticket, bool $statusChanged, ?int $assigneeId, User $actor): void
    {
        if ($statusChanged && $ticket->requester_id !== null && (int) $ticket->requester_id !== (int) $actor->id) {
            $this->notifications->notify(
                (int) $ticket->requester_id,
                Notification::TYPE_TICKET_STATUS_CHANGED,
                'Ticket status updated',
                "Ticket {$ticket->ticket_number} is now {$ticket->status}.",
                ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'status' => $ticket->status],
            );
        }

        if ($assigneeId !== null && (int) $assigneeId !== (int) $actor->id) {
            $this->notifications->notify(
                $assigneeId,
                Notification::TYPE_TICKET_ASSIGNED,
                'Ticket assigned to you',
                "Ticket {$ticket->ticket_number} has been assigned to you.",
                ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number],
            );
        }
    }

    /**
     * Apply non-status, non-assignment editable fields and collect human
     * readable change notes for the UPDATED history entry.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function applyFieldChanges(Ticket $ticket, array $data): array
    {
        $labels = [
            'title' => 'Title',
            'description' => 'Description',
            'category_id' => 'Category',
            'department_id' => 'Department',
            'location_id' => 'Location',
            'priority' => 'Priority',
        ];

        $notes = [];

        foreach ($labels as $field => $label) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field] !== null ? (string) $data[$field] : null;
            $original = $ticket->getOriginal($field) !== null ? (string) $ticket->getOriginal($field) : null;

            if ($value === $original) {
                continue;
            }

            $ticket->{$field} = $data[$field];
            $notes[] = "{$label} changed from ".($original ?? 'none').' to '.($value ?? 'none');
        }

        return $notes;
    }

    private function recordHistory(
        Ticket $ticket,
        User $actor,
        string $action,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        ?string $notes = null,
    ): void {
        TicketHistory::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'action' => $action,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function assignmentNote(?int $assigneeId): string
    {
        if ($assigneeId === null) {
            return 'Unassigned';
        }

        $assignee = User::find($assigneeId);

        return 'Assigned to '.($assignee?->name ?? "user #{$assigneeId}");
    }

    /**
     * The number is always generated server-side; the unique constraint is the
     * final guard against a collision, but the random space makes one unlikely.
     */
    private function generateTicketNumber(): string
    {
        do {
            $candidate = 'TCK-'.strtoupper(Str::random(8));
        } while (Ticket::query()->where('ticket_number', $candidate)->exists());

        return $candidate;
    }

    private function perPage(mixed $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
