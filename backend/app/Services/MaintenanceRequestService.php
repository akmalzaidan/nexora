<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Maintenance request domain logic.
 *
 * The request lifecycle mirrors the Phase 03 schema signals — the initial state
 * is REQUESTED and the table carries approved_at / completed_at — so the
 * workflow is REQUESTED -> APPROVED -> IN_PROGRESS -> COMPLETED with CANCELLED
 * reachable from REQUESTED/APPROVED. Every transition outside that map returns
 * 422 and leaves the row untouched.
 *
 * Visibility follows the Helpdesk model: users holding manage_maintenance
 * (technicians, admins) see and handle every request; everyone else with
 * view_maintenance (staff, managers) only sees the requests they raised.
 *
 * Maintenance deliberately does NOT mutate asset status or assignments: the
 * Asset lifecycle (DR-007/008) stays owned by the Asset module, so this module
 * only validates eligibility (non-deleted, not retired/lost/disposed) and never
 * touches current_user_id or assignment history.
 */
class MaintenanceRequestService
{
    private const SORTABLE_COLUMNS = ['priority', 'status', 'requested_at', 'created_at', 'updated_at'];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Asset states a maintenance request may target. Retired, lost, or disposed
     * assets are permanently uneconomical/impossible to maintain.
     *
     * @var array<int, string>
     */
    private const INELIGIBLE_ASSET_STATUSES = ['RETIRED', 'LOST', 'DISPOSED'];

    /**
     * Allowed status transitions. A transition is a no-op when the status does
     * not change; anything else not listed here is rejected with 422.
     *
     * @var array<string, array<int, string>>
     */
    private const STATUS_TRANSITIONS = [
        MaintenanceRequest::STATUS_REQUESTED => [MaintenanceRequest::STATUS_APPROVED, MaintenanceRequest::STATUS_CANCELLED],
        MaintenanceRequest::STATUS_APPROVED => [MaintenanceRequest::STATUS_IN_PROGRESS, MaintenanceRequest::STATUS_CANCELLED],
        MaintenanceRequest::STATUS_IN_PROGRESS => [MaintenanceRequest::STATUS_COMPLETED],
        MaintenanceRequest::STATUS_COMPLETED => [],
        MaintenanceRequest::STATUS_CANCELLED => [],
    ];

    /** Read-path relations; requester/assignee carry role + department for the UserResource. */
    private const EAGER_LOAD = [
        'asset',
        'requester.role',
        'requester.department',
        'assignee.role',
        'assignee.department',
    ];

    /** Detail-only relations: the work order trail for a single request. */
    private const DETAIL_LOAD = [
        'records.asset',
        'records.technician.role',
        'records.technician.department',
        'records.parts.item',
    ];

    /**
     * @param  array{search?: string|null, status?: string|null, priority?: string|null, asset_id?: int|string|null, requester_id?: int|string|null, assigned_to?: int|string|null, location_id?: int|string|null, requested_from?: string|null, requested_to?: string|null, sort?: string|null, direction?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<MaintenanceRequest>
     */
    public function paginate(array $filters = [], ?User $actor = null): LengthAwarePaginator
    {
        $query = MaintenanceRequest::query()->with(self::EAGER_LOAD);

        if (! $this->canManage($actor)) {
            $query->where('requested_by', $actor?->id);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereLike('title', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', (string) $filters['priority']);
        }

        foreach (['asset_id', 'requester_id', 'assigned_to'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field === 'requester_id' ? 'requested_by' : $field, (int) $filters[$field]);
            }
        }

        if (isset($filters['location_id']) && $filters['location_id'] !== '') {
            $query->whereHas('asset', fn ($q) => $q->where('location_id', (int) $filters['location_id']));
        }

        if (! empty($filters['requested_from'])) {
            $query->whereDate('requested_at', '>=', (string) $filters['requested_from']);
        }

        if (! empty($filters['requested_to'])) {
            $query->whereDate('requested_at', '<=', (string) $filters['requested_to']);
        }

        $sort = in_array($filters['sort'] ?? 'requested_at', self::SORTABLE_COLUMNS, true)
            ? ($filters['sort'] ?? 'requested_at')
            : 'requested_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    public function show(MaintenanceRequest $maintenanceRequest, User $actor): MaintenanceRequest
    {
        $this->assertCanView($maintenanceRequest, $actor);

        return $maintenanceRequest->load([...self::EAGER_LOAD, ...self::DETAIL_LOAD]);
    }

    /**
     * Create a request on behalf of the authenticated user. The requester is
     * always the actor, the status is always REQUESTED, and requested_at is
     * always now — a client can never spoof ownership, pre-set a workflow
     * state, or backdate the request.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): MaintenanceRequest
    {
        $this->assertEligibleAsset((int) $data['asset_id']);

        $maintenanceRequest = MaintenanceRequest::create([
            'asset_id' => $data['asset_id'],
            'requested_by' => $actor->id,
            'assigned_to' => null,
            'title' => $data['title'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? MaintenanceRequest::PRIORITY_MEDIUM,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);

        $this->auditLogs->recordCreated(
            AuditResourceType::MAINTENANCE_REQUEST,
            $maintenanceRequest->id,
            $actor,
            "Maintenance request '{$maintenanceRequest->title}' created",
            newValues: ['asset_id' => $maintenanceRequest->asset_id, 'priority' => $maintenanceRequest->priority, 'status' => $maintenanceRequest->status],
        );

        return $maintenanceRequest->load(self::EAGER_LOAD);
    }

    /**
     * Apply the status transition, change the assignee, and apply editable
     * fields. The request row is locked for the duration of the transaction so
     * two concurrent updates cannot interleave status/assignment changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MaintenanceRequest $maintenanceRequest, array $data, User $actor): MaintenanceRequest
    {
        return DB::transaction(function () use ($maintenanceRequest, $data, $actor): MaintenanceRequest {
            $locked = MaintenanceRequest::query()->lockForUpdate()->findOrFail($maintenanceRequest->id);

            $this->assertCanView($locked, $actor);

            $statusChanged = false;
            $assigneeId = null;
            // Pre-captured for the audit row: getOriginal() is reset on save.
            $originalStatus = $locked->status;
            $originalAssignee = $locked->assigned_to;

            if (array_key_exists('status', $data) && $data['status'] !== $locked->status) {
                $this->assertTransition((string) $locked->status, (string) $data['status']);
                $locked->status = (string) $data['status'];
                $statusChanged = true;

                if ($locked->status === MaintenanceRequest::STATUS_APPROVED) {
                    $locked->approved_at = $locked->approved_at ?? now();
                }

                if ($locked->status === MaintenanceRequest::STATUS_COMPLETED) {
                    $locked->completed_at = $locked->completed_at ?? now();
                }
            }

            if (array_key_exists('assigned_to', $data)
                && (int) $data['assigned_to'] !== (int) $locked->getOriginal('assigned_to')) {
                $assigneeId = $data['assigned_to'] !== null ? (int) $data['assigned_to'] : null;
                if ($assigneeId !== null) {
                    $this->assertAssignableUser($assigneeId);
                }
                $locked->assigned_to = $assigneeId;
            }

            foreach (['title', 'description', 'priority'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $data[$field];
                }
            }

            if ($locked->isDirty()) {
                $locked->save();

                // One governance audit row per committed update, describing
                // the dominant change — status transition, assignment, or edit.
                if ($statusChanged) {
                    $this->auditLogs->recordEvent(
                        AuditAction::STATUS_CHANGED,
                        AuditResourceType::MAINTENANCE_REQUEST,
                        $locked->id,
                        $actor,
                        "Maintenance request '{$locked->title}' status changed from {$originalStatus} to {$locked->status}",
                        oldValues: ['status' => $originalStatus],
                        newValues: ['status' => $locked->status],
                    );
                } elseif ($assigneeId !== null || array_key_exists('assigned_to', $data)) {
                    $this->auditLogs->recordEvent(
                        AuditAction::ASSIGNED,
                        AuditResourceType::MAINTENANCE_REQUEST,
                        $locked->id,
                        $actor,
                        "Maintenance request '{$locked->title}' assignment changed to ".($assigneeId !== null ? "user #{$assigneeId}" : 'unassigned'),
                        oldValues: ['assigned_to' => $originalAssignee],
                        newValues: ['assigned_to' => $assigneeId],
                    );
                } else {
                    $this->auditLogs->recordUpdated(
                        AuditResourceType::MAINTENANCE_REQUEST,
                        $locked->id,
                        $actor,
                        "Maintenance request '{$locked->title}' updated",
                    );
                }
            }

            $this->notifyOnRequestChanges($locked, $statusChanged, $assigneeId, $actor);

            return $locked->load(self::EAGER_LOAD);
        });
    }

    private function canManage(?User $actor): bool
    {
        return $actor?->hasPermission('manage_maintenance') ?? false;
    }

    private function assertCanView(MaintenanceRequest $maintenanceRequest, User $actor): void
    {
        if ($this->canManage($actor)) {
            return;
        }

        if ((int) $maintenanceRequest->requested_by !== (int) $actor->id) {
            abort(403, 'You do not have access to this maintenance request');
        }
    }

    private function assertEligibleAsset(int $assetId): void
    {
        $asset = Asset::query()->withoutTrashed()->find($assetId);

        if (! $asset) {
            throw new UnprocessableEntityHttpException('The selected asset does not exist.');
        }

        if (in_array($asset->status, self::INELIGIBLE_ASSET_STATUSES, true)) {
            throw new UnprocessableEntityHttpException('This asset cannot be scheduled for maintenance in its current state.');
        }
    }

    private function assertAssignableUser(int $assigneeId): void
    {
        $assignee = User::find($assigneeId);

        if (! $assignee) {
            throw new UnprocessableEntityHttpException('Assigned user does not exist');
        }

        if (! $assignee->is_active) {
            throw new UnprocessableEntityHttpException('Cannot assign maintenance to an inactive user');
        }

        if (! $assignee->hasPermission('manage_maintenance')) {
            throw new UnprocessableEntityHttpException('Assigned user is not allowed to perform maintenance work');
        }
    }

    private function assertTransition(string $from, string $to): void
    {
        if (in_array($to, self::STATUS_TRANSITIONS[$from] ?? [], true)) {
            return;
        }

        throw new UnprocessableEntityHttpException("Invalid maintenance status transition from {$from} to {$to}");
    }

    /**
     * Notifies the requester when a request is approved or completed and the
     * assignee when the assignee actually changes. Written inside the same
     * transaction as the mutation, so a replayed update never duplicates a row,
     * and the acting user is never notified about their own action.
     */
    private function notifyOnRequestChanges(
        MaintenanceRequest $request,
        bool $statusChanged,
        ?int $assigneeId,
        User $actor,
    ): void {
        if ($statusChanged && $request->requested_by !== null && (int) $request->requested_by !== (int) $actor->id) {
            if ($request->status === MaintenanceRequest::STATUS_APPROVED) {
                $this->notifications->notify(
                    (int) $request->requested_by,
                    Notification::TYPE_MAINTENANCE_APPROVED,
                    'Maintenance request approved',
                    "Maintenance request #{$request->id} has been approved.",
                    ['maintenance_request_id' => $request->id],
                );
            } elseif ($request->status === MaintenanceRequest::STATUS_COMPLETED) {
                $this->notifications->notify(
                    (int) $request->requested_by,
                    Notification::TYPE_MAINTENANCE_COMPLETED,
                    'Maintenance request completed',
                    "Maintenance request #{$request->id} has been completed.",
                    ['maintenance_request_id' => $request->id],
                );
            }
        }

        if ($assigneeId !== null && (int) $assigneeId !== (int) $actor->id) {
            $this->notifications->notify(
                $assigneeId,
                Notification::TYPE_MAINTENANCE_ASSIGNED,
                'Maintenance request assigned to you',
                "Maintenance request #{$request->id} has been assigned to you.",
                ['maintenance_request_id' => $request->id],
            );
        }
    }

    private function perPage(mixed $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
