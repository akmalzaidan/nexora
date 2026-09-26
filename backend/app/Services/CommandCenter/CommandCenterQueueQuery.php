<?php

namespace App\Services\CommandCenter;

use App\Models\AssetAssignment;
use App\Models\MaintenanceRequest;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Operational work queues for the Command Center.
 *
 * A queue is a bounded list of records that are *waiting for a person to act*,
 * defined only by stored columns — never by a severity, a score, or a "problem"
 * label that the domain does not define:
 *
 *  - `unassigned_tickets` — open work with no `assigned_to`
 *  - `unassigned_maintenance_requests` — unfinished work with no `assigned_to`
 *  - `pending_asset_assignments` — handover requests still in PENDING (DR-008)
 *
 * Each queue reports the full `count` and only the first `limit` rows, so a
 * dashboard can show "37 waiting" without transferring 37 records. Rows are
 * ordered newest first with an id tiebreak, matching every other list endpoint
 * in this API, so repeated calls are byte-identical.
 *
 * Queue rows obey the same visibility scope as the snapshot: a caller who may
 * only see their own tickets or requests never sees another person's queue, and
 * a queue for a domain the caller cannot read is not returned at all.
 */
class CommandCenterQueueQuery
{
    private const ASSET_ASSIGNMENT_STATUS_PENDING = 'PENDING';

    /**
     * @return array{count: int, limit: int, items: list<array<string, mixed>>}
     */
    public function unassignedTickets(CommandCenterScope $scope, int $limit): array
    {
        $query = $this->ticketsQuery($scope);
        $count = (clone $query)->count();

        $items = (clone $query)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'ticket_number', 'title', 'status', 'created_at'])
            ->map(fn (Ticket $ticket): array => [
                'id' => (int) $ticket->id,
                'type' => 'ticket',
                'reference' => (string) $ticket->ticket_number,
                'title' => (string) $ticket->title,
                'status' => (string) $ticket->status,
                'created_at' => $ticket->created_at?->toISOString(),
            ])
            ->all();

        return $this->queue($count, $limit, $items);
    }

    /**
     * `reference` is null here: a maintenance request has no server-side
     * reference number (unlike a ticket, DR-015), so the row id is its identity
     * and no number is invented for display.
     *
     * @return array{count: int, limit: int, items: list<array<string, mixed>>}
     */
    public function unassignedMaintenanceRequests(CommandCenterScope $scope, int $limit): array
    {
        $query = $this->maintenanceQuery($scope);
        $count = (clone $query)->count();

        $items = (clone $query)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'title', 'status', 'created_at'])
            ->map(fn (MaintenanceRequest $request): array => [
                'id' => (int) $request->id,
                'type' => 'maintenance_request',
                'reference' => null,
                'title' => (string) $request->title,
                'status' => (string) $request->status,
                'created_at' => $request->created_at?->toISOString(),
            ])
            ->all();

        return $this->queue($count, $limit, $items);
    }

    /**
     * Asset assignments have no per-user read scope (any `view_asset_assignments`
     * reader lists them all), so this queue is only offered to that permission.
     *
     * @return array{count: int, limit: int, items: list<array<string, mixed>>}
     */
    public function pendingAssetAssignments(int $limit): array
    {
        $query = AssetAssignment::query()
            ->join('assets', 'assets.id', '=', 'asset_assignments.asset_id')
            ->where('asset_assignments.status', self::ASSET_ASSIGNMENT_STATUS_PENDING);

        $count = (clone $query)->count('asset_assignments.id');

        $items = (clone $query)
            ->orderByDesc('asset_assignments.created_at')
            ->orderByDesc('asset_assignments.id')
            ->limit($limit)
            ->get([
                'asset_assignments.id as id',
                'assets.asset_code as asset_code',
                'assets.name as asset_name',
                'asset_assignments.status as status',
                'asset_assignments.created_at as created_at',
            ])
            ->map(fn (AssetAssignment $assignment): array => [
                'id' => (int) $assignment->id,
                'type' => 'asset_assignment',
                'reference' => (string) $assignment->asset_code,
                'title' => (string) $assignment->asset_name,
                'status' => (string) $assignment->status,
                'created_at' => $assignment->created_at?->toISOString(),
            ])
            ->all();

        return $this->queue($count, $limit, $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{count: int, limit: int, items: list<array<string, mixed>>}
     */
    private function queue(int $count, int $limit, array $items): array
    {
        return [
            'count' => $count,
            'limit' => $limit,
            'items' => $items,
        ];
    }

    private function ticketsQuery(CommandCenterScope $scope): Builder
    {
        $query = Ticket::query()
            ->whereNull('assigned_to')
            ->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_IN_PROGRESS]);

        if (! $scope->seesAllTickets()) {
            $query->where('requester_id', $scope->actorId());
        }

        return $query;
    }

    private function maintenanceQuery(CommandCenterScope $scope): Builder
    {
        $query = MaintenanceRequest::query()
            ->whereNull('assigned_to')
            ->whereIn('status', [
                MaintenanceRequest::STATUS_REQUESTED,
                MaintenanceRequest::STATUS_APPROVED,
                MaintenanceRequest::STATUS_IN_PROGRESS,
            ]);

        if (! $scope->seesAllMaintenance()) {
            $query->where('requested_by', $scope->actorId());
        }

        return $query;
    }
}
