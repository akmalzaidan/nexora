<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetHistory;
use App\Models\Notification;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AssetAssignmentService
{
    private const SORTABLE_COLUMNS = ['status', 'assigned_at', 'created_at'];

    private const ASSIGNABLE_STATUSES = ['ACTIVE', 'INACTIVE', 'MAINTENANCE'];

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogService $auditLogs,
    ) {}

    /** @param array{search?: string|null, asset_id?: int|null, user_id?: int|null, location_id?: int|null, status?: string|null, sort?: string, direction?: string, per_page?: int} $filters */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = AssetAssignment::query()
            ->with(['asset', 'user', 'location']);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->whereHas('asset', function ($aq) use ($search): void {
                    $aq->whereLike('asset_code', "%{$search}%")
                        ->orWhereLike('name', "%{$search}%");
                })->orWhereHas('user', function ($uq) use ($search): void {
                    $uq->whereLike('name', "%{$search}%");
                });
            });
        }

        if (! empty($filters['asset_id'])) {
            $query->where('asset_id', (int) $filters['asset_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $sort = in_array($filters['sort'] ?? 'assigned_at', self::SORTABLE_COLUMNS, true)
            ? $filters['sort']
            : 'assigned_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, int $authenticatedUserId): AssetAssignment
    {
        return DB::transaction(function () use ($data, $authenticatedUserId) {
            $asset = Asset::lockForUpdate()->findOrFail($data['asset_id']);

            $this->validateAssetAssignable($asset);
            $this->validateUserActive($data['user_id']);
            $this->validateNoActiveAssignment($asset);

            $assignment = AssetAssignment::create([
                'asset_id' => $asset->id,
                'user_id' => $data['user_id'],
                'requested_by' => $authenticatedUserId,
                'location_id' => $data['location_id'] ?? null,
                'status' => 'ACTIVE',
                'assigned_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $asset->current_user_id = $data['user_id'];
            $asset->saveQuietly();

            $this->createHistory($asset, 'ASSIGNED', $data['user_id'], $authenticatedUserId, null);

            $this->auditLogs->recordEvent(
                AuditAction::ASSIGNED,
                AuditResourceType::ASSET_ASSIGNMENT,
                $assignment->id,
                User::find($authenticatedUserId),
                "Asset {$asset->asset_code} assigned to user #{$data['user_id']}",
                newValues: ['asset_id' => $asset->id, 'user_id' => (int) $data['user_id'], 'status' => 'ACTIVE'],
            );

            if ((int) $data['user_id'] !== (int) $authenticatedUserId) {
                $this->notifications->notify(
                    (int) $data['user_id'],
                    Notification::TYPE_ASSET_ASSIGNED,
                    'Asset assigned to you',
                    "Asset {$asset->asset_code} has been assigned to you.",
                    ['asset_id' => $asset->id, 'assignment_id' => $assignment->id],
                );
            }

            return $assignment->load(['asset', 'user', 'location']);
        });
    }

    public function returnAssignment(AssetAssignment $assignment, ?int $performedBy = null): AssetAssignment
    {
        return DB::transaction(function () use ($assignment, $performedBy) {
            // The actor is resolved before the mutation so the audit row can be
            // written inside the same transaction as the domain change.
            if ($assignment->status !== 'ACTIVE') {
                abort(409, 'Asset assignment cannot be returned because it is not active');
            }

            $asset = Asset::lockForUpdate()->findOrFail($assignment->asset_id);

            $assignment->status = 'RETURNED';
            $assignment->returned_at = now();
            $assignment->saveQuietly();

            $asset->current_user_id = null;
            $asset->saveQuietly();

            $this->createHistory($asset, 'RETURNED', $assignment->user_id, $assignment->user_id, $assignment->notes);

            $this->auditLogs->recordEvent(
                AuditAction::RETURNED,
                AuditResourceType::ASSET_ASSIGNMENT,
                $assignment->id,
                $performedBy !== null ? User::find($performedBy) : null,
                "Asset {$asset->asset_code} returned by user #{$assignment->user_id}",
                oldValues: ['status' => 'ACTIVE'],
                newValues: ['status' => 'RETURNED'],
            );

            if ($performedBy === null || (int) $assignment->user_id !== (int) $performedBy) {
                $this->notifications->notify(
                    (int) $assignment->user_id,
                    Notification::TYPE_ASSET_RETURNED,
                    'Asset returned',
                    "Asset {$asset->asset_code} has been returned.",
                    ['asset_id' => $asset->id],
                );
            }

            return $assignment->load(['asset', 'user', 'location']);
        });
    }

    /** @return array<string, mixed> */
    private function validateAssetAssignable(Asset $asset): void
    {
        if (! in_array($asset->status, self::ASSIGNABLE_STATUSES, true)) {
            abort(422, "Asset cannot be assigned because it is not in an assignable state (current status: {$asset->status})");
        }
    }

    private function validateUserActive(int $userId): void
    {
        $user = User::find($userId);
        if ($user === null || ! $user->is_active) {
            abort(422, 'Asset cannot be assigned to an inactive or nonexistent user');
        }
    }

    private function validateNoActiveAssignment(Asset $asset): void
    {
        if ($asset->assignments()->where('status', 'ACTIVE')->exists()) {
            abort(409, 'Asset cannot be assigned because it already has an active assignment');
        }
    }

    private function createHistory(
        Asset $asset,
        string $action,
        ?int $userId,
        int $performingUserId,
        ?string $notes
    ): void {
        AssetHistory::create([
            'asset_id' => $asset->id,
            'user_id' => $userId,
            'action' => $action,
            'old_status' => $asset->status,
            'new_status' => $asset->status,
            'old_location_id' => $asset->location_id,
            'new_location_id' => $asset->location_id,
            'notes' => $notes ?? "Asset {$action}",
            'created_at' => now(),
        ]);
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
