<?php

namespace App\Services\CommandCenter;

use App\Models\User;

/**
 * Per-caller visibility scope for the Command Center.
 *
 * `view_dashboard` is deliberately a *weak* permission: it is mapped to
 * `super_admin`, `admin`, `manager`, `staff` and `warehouse_staff`, which do not
 * share one data visibility. The Command Center therefore cannot be a single
 * unfiltered organization-wide aggregate the way `/reports/*` is (DR-018) — a
 * `warehouse_staff` user has no `view_tickets` at all, and a `staff` user may
 * only read their own tickets and their own maintenance requests.
 *
 * This class resolves the two questions the snapshot needs, and it answers them
 * with the *existing* domain rules instead of inventing a new scope:
 *
 *  - **which sections exist at all** — one per domain view permission, so a
 *    section is never rendered for a caller who may not read that domain;
 *  - **whose rows are counted** — organization-wide only for the roles that
 *    already read the domain organization-wide, otherwise restricted to the
 *    caller's own rows, mirroring `TicketService::isAgent()` and
 *    `MaintenanceRequestService::canManage()`.
 *
 * Nothing here queries the database: it is a pure decision object built from the
 * authenticated user, so the authorization contract is testable on its own.
 */
final class CommandCenterScope
{
    public function __construct(private readonly User $actor) {}

    public function actor(): User
    {
        return $this->actor;
    }

    public function actorId(): int
    {
        return (int) $this->actor->id;
    }

    public function canViewAssets(): bool
    {
        return $this->actor->hasPermission('view_assets');
    }

    public function canViewInventory(): bool
    {
        return $this->actor->hasPermission('view_inventory');
    }

    public function canViewTickets(): bool
    {
        return $this->actor->hasPermission('view_tickets');
    }

    public function canViewMaintenance(): bool
    {
        return $this->actor->hasPermission('view_maintenance');
    }

    public function canViewAssignments(): bool
    {
        return $this->actor->hasPermission('view_asset_assignments');
    }

    /**
     * Ticket rows are organization-wide for agents (`manage_tickets` /
     * `assign_tickets`) and restricted to the caller's own raised tickets for
     * everyone else — the exact rule `TicketService` applies on its read paths.
     */
    public function seesAllTickets(): bool
    {
        return $this->actor->hasAnyPermission(['manage_tickets', 'assign_tickets']);
    }

    /**
     * Maintenance requests are organization-wide for `manage_maintenance` and
     * restricted to the caller's own raised requests otherwise — the exact rule
     * `MaintenanceRequestService::assertCanView()` applies.
     */
    public function seesAllMaintenance(): bool
    {
        return $this->actor->hasPermission('manage_maintenance');
    }
}
