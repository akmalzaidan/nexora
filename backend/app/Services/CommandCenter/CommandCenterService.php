<?php

namespace App\Services\CommandCenter;

use App\Models\User;

/**
 * Command Center / operational intelligence façade.
 *
 * Assembles one read-only snapshot for the caller: what the organization looks
 * like right now, what is waiting for a person, and what happened most recently.
 * It is deliberately **not** a second Reports API — the reports answer "what
 * happened, over this period" and are organization-wide; the Command Center
 * answers "what is true now, and what is waiting", and it is scoped to what the
 * caller is actually allowed to read (see `CommandCenterScope`).
 *
 * Nothing in this path writes. It performs no ticket, maintenance, stock, asset
 * assignment or notification mutation, creates no notification, and caches
 * nothing: every call is a fresh set of aggregates, which is the point of a
 * current-state surface.
 */
class CommandCenterService
{
    public function __construct(
        private readonly CommandCenterSnapshotQuery $snapshot,
        private readonly CommandCenterQueueQuery $queues,
        private readonly CommandCenterActivityQuery $activity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(User $actor, int $limit): array
    {
        $scope = new CommandCenterScope($actor);

        return [
            'generated_at' => now()->toISOString(),
            'snapshot' => $this->snapshotForScope($scope),
            'queues' => $this->queuesForScope($scope, $limit),
            'recent_activity' => $this->activity->recent($scope, $limit),
        ];
    }

    /**
     * A section is omitted entirely when the caller may not read that domain, so
     * an absent key always means "not visible to you" and never "no data".
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshotForScope(CommandCenterScope $scope): array
    {
        $snapshot = [];

        if ($scope->canViewAssets()) {
            $snapshot['assets'] = $this->snapshot->assets();
        }

        if ($scope->canViewInventory()) {
            $snapshot['inventory'] = $this->snapshot->inventory();
        }

        if ($scope->canViewTickets()) {
            $snapshot['tickets'] = $this->snapshot->tickets($scope);
        }

        if ($scope->canViewMaintenance()) {
            $snapshot['maintenance'] = $this->snapshot->maintenance($scope);
        }

        // The caller's own inbox needs no domain permission and is never scoped
        // away: it is their unread count and nobody else's (DR-017).
        $snapshot['notifications'] = $this->snapshot->notifications($scope->actor());

        return $snapshot;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function queuesForScope(CommandCenterScope $scope, int $limit): array
    {
        $queues = [];

        if ($scope->canViewTickets()) {
            $queues['unassigned_tickets'] = $this->queues->unassignedTickets($scope, $limit);
        }

        if ($scope->canViewMaintenance()) {
            $queues['unassigned_maintenance_requests'] = $this->queues->unassignedMaintenanceRequests($scope, $limit);
        }

        if ($scope->canViewAssignments()) {
            $queues['pending_asset_assignments'] = $this->queues->pendingAssetAssignments($limit);
        }

        return $queues;
    }
}
