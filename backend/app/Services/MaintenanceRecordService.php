<?php

namespace App\Services;

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Maintenance record (work order) domain logic.
 *
 * A maintenance record is actual work performed against a maintenance request.
 * Creating a record opens the work: the record must belong to the same asset as
 * its request, is started now (unless a started_at is supplied), and — when the
 * request was still APPROVED — the request moves to IN_PROGRESS in the same
 * transaction as the record insert.
 *
 * Records are the module's history (DR-003 / §27): they are never hard-deleted,
 * stock movements are never generated implicitly, and only the work fields
 * (description, timestamps, result, cost, technician) can change after the fact.
 */
class MaintenanceRecordService
{
    private const SORTABLE_COLUMNS = ['created_at', 'started_at', 'completed_at', 'cost'];

    public function __construct(private readonly AuditLogService $auditLogs) {}

    private const EAGER_LOAD = [
        'request',
        'asset',
        'technician.role',
        'technician.department',
    ];

    /**
     * @param  array{maintenance_request_id?: int|string|null, asset_id?: int|string|null, technician_id?: int|string|null, sort?: string|null, direction?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<MaintenanceRecord>
     */
    public function paginate(array $filters = [], ?User $actor = null): LengthAwarePaginator
    {
        $query = MaintenanceRecord::query()->with(self::EAGER_LOAD);

        if (! $this->canManage($actor)) {
            $query->whereHas('request', fn ($q) => $q->where('requested_by', $actor?->id));
        }

        foreach (['maintenance_request_id', 'asset_id', 'technician_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, (int) $filters[$field]);
            }
        }

        $sort = in_array($filters['sort'] ?? 'created_at', self::SORTABLE_COLUMNS, true)
            ? ($filters['sort'] ?? 'created_at')
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    public function show(MaintenanceRecord $maintenanceRecord, User $actor): MaintenanceRecord
    {
        $this->assertCanView($maintenanceRecord, $actor);

        return $maintenanceRecord->load([...self::EAGER_LOAD, 'parts.item']);
    }

    /**
     * Open a work order for a request. The whole operation is atomic: the
     * request row is locked, the request (if APPROVED) moves to IN_PROGRESS,
     * and the record is inserted — success leaves request + record consistent,
     * failure rolls everything back.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, MaintenanceRequest $maintenanceRequest, User $actor): MaintenanceRecord
    {
        return DB::transaction(function () use ($data, $maintenanceRequest, $actor): MaintenanceRecord {
            $lockedRequest = MaintenanceRequest::query()->lockForUpdate()->findOrFail($maintenanceRequest->id);

            if (! in_array($lockedRequest->status, [MaintenanceRequest::STATUS_APPROVED, MaintenanceRequest::STATUS_IN_PROGRESS], true)) {
                throw new UnprocessableEntityHttpException('Maintenance work can only be started on an approved request.');
            }

            if ($lockedRequest->status === MaintenanceRequest::STATUS_APPROVED) {
                $lockedRequest->status = MaintenanceRequest::STATUS_IN_PROGRESS;
                $lockedRequest->save();

                $this->auditLogs->recordEvent(
                    AuditAction::STATUS_CHANGED,
                    AuditResourceType::MAINTENANCE_REQUEST,
                    $lockedRequest->id,
                    $actor,
                    "Maintenance request '{$lockedRequest->title}' status changed from ".MaintenanceRequest::STATUS_APPROVED.' to '.MaintenanceRequest::STATUS_IN_PROGRESS,
                    oldValues: ['status' => MaintenanceRequest::STATUS_APPROVED],
                    newValues: ['status' => MaintenanceRequest::STATUS_IN_PROGRESS],
                );
            }

            $technicianId = $this->resolveTechnician($data, $lockedRequest, $actor);

            $maintenanceRecord = MaintenanceRecord::create([
                'maintenance_request_id' => $lockedRequest->id,
                'asset_id' => $lockedRequest->asset_id,
                'technician_id' => $technicianId,
                'started_at' => $data['started_at'] ?? now(),
                'completed_at' => $data['completed_at'] ?? null,
                'description' => $data['description'],
                'result' => $data['result'] ?? null,
                'cost' => $data['cost'] ?? null,
            ]);

            $this->auditLogs->recordCreated(
                AuditResourceType::MAINTENANCE_RECORD,
                $maintenanceRecord->id,
                $actor,
                "Maintenance work started on '{$lockedRequest->title}'",
                newValues: ['maintenance_request_id' => $lockedRequest->id, 'asset_id' => $lockedRequest->asset_id, 'technician_id' => $technicianId],
            );

            return $maintenanceRecord->load(self::EAGER_LOAD);
        });
    }

    /**
     * Update work fields. The asset and the request can never change on a work
     * order, so the record always stays truthful about what was worked on.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(MaintenanceRecord $maintenanceRecord, array $data, User $actor): MaintenanceRecord
    {
        return DB::transaction(function () use ($maintenanceRecord, $data, $actor): MaintenanceRecord {
            $locked = MaintenanceRecord::query()->lockForUpdate()->with('request')->findOrFail($maintenanceRecord->id);

            $this->assertCanView($locked, $actor);

            if (array_key_exists('technician_id', $data)
                && (int) $data['technician_id'] !== (int) $locked->getOriginal('technician_id')) {
                $technicianId = $data['technician_id'] !== null ? (int) $data['technician_id'] : null;
                if ($technicianId !== null) {
                    $this->assertAssignableTechnician($technicianId);
                }
                $locked->technician_id = $technicianId;
            }

            foreach (['description', 'started_at', 'completed_at', 'result', 'cost'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $data[$field];
                }
            }

            if ($locked->isDirty()) {
                $locked->save();

                $this->auditLogs->recordUpdated(
                    AuditResourceType::MAINTENANCE_RECORD,
                    $locked->id,
                    $actor,
                    "Maintenance work on request #{$locked->maintenance_request_id} updated",
                );
            }

            return $locked->load(self::EAGER_LOAD);
        });
    }

    /**
     * The record technician is the first present, valid value from: the client
     * (server-validated), the request assignee, or the acting technician. A
     * client can never bypass capability validation by omitting the field.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTechnician(array $data, MaintenanceRequest $request, User $actor): ?int
    {
        $candidate = $data['technician_id'] ?? $request->assigned_to ?? ($this->canManage($actor) ? $actor->id : null);

        if ($candidate === null) {
            return null;
        }

        $this->assertAssignableTechnician((int) $candidate);

        return (int) $candidate;
    }

    private function assertAssignableTechnician(int $technicianId): void
    {
        $technician = User::find($technicianId);

        if (! $technician) {
            throw new UnprocessableEntityHttpException('Technician does not exist');
        }

        if (! $technician->is_active) {
            throw new UnprocessableEntityHttpException('Cannot assign maintenance work to an inactive user');
        }

        if (! $technician->hasPermission('manage_maintenance')) {
            throw new UnprocessableEntityHttpException('Assigned user is not allowed to perform maintenance work');
        }
    }

    private function canManage(?User $actor): bool
    {
        return $actor?->hasPermission('manage_maintenance') ?? false;
    }

    private function assertCanView(MaintenanceRecord $maintenanceRecord, User $actor): void
    {
        if ($this->canManage($actor)) {
            return;
        }

        $requesterId = $maintenanceRecord->relationLoaded('request')
            ? (int) $maintenanceRecord->request->requested_by
            : (int) $maintenanceRecord->request()->value('requested_by');

        if ($requesterId !== (int) $actor->id) {
            abort(403, 'You do not have access to this maintenance record');
        }
    }

    private function perPage(mixed $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
