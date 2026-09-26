<?php

namespace App\Services\CommandCenter;

use App\Models\Asset;
use App\Models\Item;
use App\Models\MaintenanceRequest;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\NotificationService;
use App\Services\StockMovementService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Current-state aggregates for the Command Center snapshot.
 *
 * Every metric is a single server-side aggregate over an existing domain table
 * and carries no date filter: this is *what is true now*, which is the whole
 * difference from `/reports/*` (DR-018). There is no `by_status` breakdown here
 * on purpose — status distributions are analytical and already served by the
 * reports; the Command Center reports the numbers an operator acts on.
 *
 * No metric interprets the domain: nothing is scored, ranked, predicted, or
 * labelled healthy/critical. "Active" and "in maintenance" are stored `assets`
 * status values, "active" is a stored ticket/maintenance status set, and
 * "unassigned" is a null `assigned_to` / `current_user_id` column — never a
 * derived judgement.
 *
 * Stock is never recomputed here: `stock_quantity` comes from
 * `StockMovementService` (DR-014), and the unread count from
 * `NotificationService`, so the dashboard cannot drift from either module.
 */
class CommandCenterSnapshotQuery
{
    /**
     * Asset status values, mirroring the allowed list in `StoreAssetRequest` /
     * `UpdateAssetRequest`. The `assets` table stores the raw string, so the
     * two counters quote the same vocabulary the write path accepts.
     */
    private const ASSET_STATUS_ACTIVE = 'ACTIVE';

    private const ASSET_STATUS_MAINTENANCE = 'MAINTENANCE';

    public function __construct(
        private readonly StockMovementService $movements,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return array{total: int, active: int, in_maintenance: int, unassigned: int}
     */
    public function assets(): array
    {
        $row = Asset::query()
            ->withoutTrashed()
            ->selectRaw(
                'COUNT(*) as total, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as active, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as in_maintenance, '
                .'COALESCE(SUM(CASE WHEN current_user_id IS NULL THEN 1 ELSE 0 END), 0) as unassigned',
                [self::ASSET_STATUS_ACTIVE, self::ASSET_STATUS_MAINTENANCE]
            )
            ->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'active' => (int) ($row?->active ?? 0),
            'in_maintenance' => (int) ($row?->in_maintenance ?? 0),
            'unassigned' => (int) ($row?->unassigned ?? 0),
        ];
    }

    /**
     * Current stock is the full journal balance, not a dated total: the
     * Command Center reports state, and dated movement activity belongs to
     * `recent_activity` (and to the inventory report).
     *
     * @return array{item_count: int, warehouse_count: int, stock_quantity: int}
     */
    public function inventory(): array
    {
        return [
            'item_count' => Item::query()->withoutTrashed()->count(),
            'warehouse_count' => Warehouse::query()->count(),
            'stock_quantity' => $this->movements->totalBalance(),
        ];
    }

    /**
     * `active` is the stored open-work status set (OPEN + IN_PROGRESS); RESOLVED
     * and CLOSED are finished work and are deliberately excluded. There is no
     * due-date or SLA column on `tickets`, so no overdue metric exists to report.
     *
     * @return array{total: int, active: int, unassigned: int}
     */
    public function tickets(CommandCenterScope $scope): array
    {
        $row = $this->ticketsQuery($scope)
            ->selectRaw(
                'COUNT(*) as total, '
                .'COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) as active, '
                .'COALESCE(SUM(CASE WHEN status IN (?, ?) AND assigned_to IS NULL THEN 1 ELSE 0 END), 0) as unassigned',
                [
                    Ticket::STATUS_OPEN,
                    Ticket::STATUS_IN_PROGRESS,
                    Ticket::STATUS_OPEN,
                    Ticket::STATUS_IN_PROGRESS,
                ]
            )
            ->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'active' => (int) ($row?->active ?? 0),
            'unassigned' => (int) ($row?->unassigned ?? 0),
        ];
    }

    /**
     * `active` is the stored unfinished-work status set (REQUESTED + APPROVED +
     * IN_PROGRESS). `awaiting_approval` is the REQUESTED subset, which is a
     * workflow fact, not a severity: no request is ever called overdue or risky.
     *
     * @return array{total: int, active: int, unassigned: int, awaiting_approval: int}
     */
    public function maintenance(CommandCenterScope $scope): array
    {
        $open = [
            MaintenanceRequest::STATUS_REQUESTED,
            MaintenanceRequest::STATUS_APPROVED,
            MaintenanceRequest::STATUS_IN_PROGRESS,
        ];

        $row = $this->maintenanceQuery($scope)
            ->selectRaw(
                'COUNT(*) as total, '
                .'COALESCE(SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END), 0) as active, '
                .'COALESCE(SUM(CASE WHEN status IN (?, ?, ?) AND assigned_to IS NULL THEN 1 ELSE 0 END), 0) as unassigned, '
                .'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as awaiting_approval',
                [...$open, ...$open, MaintenanceRequest::STATUS_REQUESTED]
            )
            ->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'active' => (int) ($row?->active ?? 0),
            'unassigned' => (int) ($row?->unassigned ?? 0),
            'awaiting_approval' => (int) ($row?->awaiting_approval ?? 0),
        ];
    }

    /**
     * The caller's own inbox only. The unread count already exists as
     * `GET /notifications/unread-count`; it is read through the same service so
     * the dashboard badge can never disagree with the notification bell, and no
     * notification is ever created or marked read here.
     *
     * @return array{unread_count: int}
     */
    public function notifications(User $actor): array
    {
        return [
            'unread_count' => $this->notifications->unreadCount($actor),
        ];
    }

    private function ticketsQuery(CommandCenterScope $scope): Builder
    {
        $query = Ticket::query();

        if (! $scope->seesAllTickets()) {
            $query->where('requester_id', $scope->actorId());
        }

        return $query;
    }

    private function maintenanceQuery(CommandCenterScope $scope): Builder
    {
        $query = MaintenanceRequest::query();

        if (! $scope->seesAllMaintenance()) {
            $query->where('requested_by', $scope->actorId());
        }

        return $query;
    }
}
