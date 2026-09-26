<?php

namespace App\Services\CommandCenter;

use App\Models\AssetHistory;
use App\Models\MaintenanceRecord;
use App\Models\StockMovement;
use App\Models\TicketHistory;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Recent operational activity for the Command Center.
 *
 * This is the one place the four domain event sources meet, and DR-018 deferred
 * merging them for a good reason: `asset_histories`, `ticket_histories`,
 * `stock_movements` and `maintenance_records` do not share an event vocabulary.
 * The compromise keeps both halves of that decision:
 *
 *  - **the sources are not merged semantically.** Every item keeps its source
 *    discriminator (`type`) and the `action` value exactly as that source stores
 *    it — `ASSIGNED`, `STATUS_CHANGED`, `STOCK_IN`, `WORK_COMPLETED` are never
 *    renamed into a shared "event" vocabulary, and no severity or ordering
 *    meaning is added;
 *  - **the timeline is still one ordered list**, because a list of the newest
 *    events from several sources is a presentation, not a claim about what those
 *    events mean.
 *
 * Ordering is `occurred_at` descending with a `(type, record_id)` tiebreak, and
 * each source is read with the same `limit` before the merge. That is exact, not
 * approximate: any row that would make the global newest `limit` must be among
 * its own source's newest `limit`, so nothing can be missed while the total
 * number of rows read stays bounded at `sources × limit`.
 *
 * Activity is deliberately kept apart from the snapshot counts: these are dated
 * event records, the snapshot is current state, and the two are never summed
 * together. Notifications are not an activity source — they are a per-user inbox,
 * not an operational event stream (see DR-017) — and the unread count they own
 * is the only notification fact reported.
 */
class CommandCenterActivityQuery
{
    public const TYPE_ASSET = 'asset';

    public const TYPE_STOCK_MOVEMENT = 'stock_movement';

    public const TYPE_TICKET = 'ticket';

    public const TYPE_MAINTENANCE = 'maintenance';

    /**
     * @return array{limit: int, items: list<array<string, mixed>>}
     */
    public function recent(CommandCenterScope $scope, int $limit): array
    {
        $stamped = [];

        if ($scope->canViewAssets()) {
            $stamped = [...$stamped, ...$this->assetEvents($limit)];
        }

        if ($scope->canViewInventory()) {
            $stamped = [...$stamped, ...$this->stockEvents($limit)];
        }

        if ($scope->canViewTickets()) {
            $stamped = [...$stamped, ...$this->ticketEvents($scope, $limit)];
        }

        if ($scope->canViewMaintenance()) {
            $stamped = [...$stamped, ...$this->maintenanceEvents($scope, $limit)];
        }

        usort($stamped, static function (array $left, array $right): int {
            // Newest first; the (type, record_id) tiebreak keeps two events that
            // share a timestamp in a fixed order across drivers.
            return $right['at'] <=> $left['at']
                ?: $left['type'] <=> $right['type']
                ?: $right['record_id'] <=> $left['record_id'];
        });

        return [
            'limit' => $limit,
            'items' => array_map(
                fn (array $entry): array => array_diff_key($entry, ['at' => null]),
                array_slice($stamped, 0, $limit)
            ),
        ];
    }

    /**
     * `asset_histories.action` is written by the asset domain
     * (`ASSIGNED` / `RETURNED`) and is returned verbatim; the factory and older
     * rows may carry other values, which is why it is never interpreted here.
     *
     * @return list<array<string, mixed>>
     */
    private function assetEvents(int $limit): array
    {
        return AssetHistory::query()
            ->join('assets', 'assets.id', '=', 'asset_histories.asset_id')
            ->orderByDesc('asset_histories.created_at')
            ->orderByDesc('asset_histories.id')
            ->limit($limit)
            ->get([
                'asset_histories.id as id',
                'asset_histories.action as action',
                'asset_histories.old_status as old_status',
                'asset_histories.new_status as new_status',
                'asset_histories.created_at as occurred_at',
                'assets.asset_code as asset_code',
                'assets.name as asset_name',
                'assets.status as asset_status',
            ])
            ->map(fn (AssetHistory $history): ?array => $this->item(
                type: self::TYPE_ASSET,
                recordId: (int) $history->id,
                action: (string) $history->action,
                occurredAt: $history->occurred_at,
                reference: (string) $history->asset_code,
                label: (string) $history->asset_name,
                status: (string) $history->asset_status,
                oldStatus: $history->old_status,
                newStatus: $history->new_status,
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A stock movement's own `type` is its action vocabulary (STOCK_IN /
     * STOCK_OUT, DR-014). The quantity is carried so a reader can see the
     * magnitude of the movement; it is not netted against anything here.
     *
     * @return list<array<string, mixed>>
     */
    private function stockEvents(int $limit): array
    {
        return StockMovement::query()
            ->join('items', 'items.id', '=', 'stock_movements.item_id')
            ->orderByDesc('stock_movements.created_at')
            ->orderByDesc('stock_movements.id')
            ->limit($limit)
            ->get([
                'stock_movements.id as id',
                'stock_movements.type as action',
                'stock_movements.quantity as quantity',
                'stock_movements.created_at as occurred_at',
                'items.sku as sku',
                'items.name as item_name',
            ])
            ->map(fn (StockMovement $movement): ?array => $this->item(
                type: self::TYPE_STOCK_MOVEMENT,
                recordId: (int) $movement->id,
                action: (string) $movement->action,
                occurredAt: $movement->occurred_at,
                reference: (string) $movement->sku,
                label: (string) $movement->item_name,
                status: null,
                quantity: (int) $movement->quantity,
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ticketEvents(CommandCenterScope $scope, int $limit): array
    {
        $query = TicketHistory::query()
            ->join('tickets', 'tickets.id', '=', 'ticket_histories.ticket_id')
            ->orderByDesc('ticket_histories.created_at')
            ->orderByDesc('ticket_histories.id');

        if (! $scope->seesAllTickets()) {
            $query->where('tickets.requester_id', $scope->actorId());
        }

        return $query
            ->limit($limit)
            ->get([
                'ticket_histories.id as id',
                'ticket_histories.action as action',
                'ticket_histories.old_status as old_status',
                'ticket_histories.new_status as new_status',
                'ticket_histories.created_at as occurred_at',
                'tickets.ticket_number as ticket_number',
                'tickets.title as ticket_title',
                'tickets.status as ticket_status',
            ])
            ->map(fn (TicketHistory $history): ?array => $this->item(
                type: self::TYPE_TICKET,
                recordId: (int) $history->id,
                action: (string) $history->action,
                occurredAt: $history->occurred_at,
                reference: (string) $history->ticket_number,
                label: (string) $history->ticket_title,
                status: (string) $history->ticket_status,
                oldStatus: $history->old_status,
                newStatus: $history->new_status,
            ))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * A work record is the maintenance domain's only event table and it stores no
     * `action` column, so the action is read off the two real lifecycle
     * timestamps: `completed_at` set means the recorded work is finished,
     * otherwise the work is started but not completed. That is a statement about
     * the columns, not a judgement about the request.
     *
     * @return list<array<string, mixed>>
     */
    private function maintenanceEvents(CommandCenterScope $scope, int $limit): array
    {
        $query = MaintenanceRecord::query()
            ->join('maintenance_requests', 'maintenance_requests.id', '=', 'maintenance_records.maintenance_request_id')
            ->join('assets', 'assets.id', '=', 'maintenance_records.asset_id')
            ->orderByDesc('maintenance_records.created_at')
            ->orderByDesc('maintenance_records.id');

        if (! $scope->seesAllMaintenance()) {
            $query->where('maintenance_requests.requested_by', $scope->actorId());
        }

        return $query
            ->limit($limit)
            ->get([
                'maintenance_records.id as id',
                'maintenance_records.started_at as started_at',
                'maintenance_records.completed_at as completed_at',
                'maintenance_records.created_at as occurred_at',
                'maintenance_requests.title as request_title',
                'maintenance_requests.status as request_status',
                'assets.asset_code as asset_code',
            ])
            ->map(function (MaintenanceRecord $record): ?array {
                $completed = $record->completed_at !== null;
                $occurredAt = $completed ? $record->completed_at : ($record->started_at ?? $record->occurred_at);

                return $this->item(
                    type: self::TYPE_MAINTENANCE,
                    recordId: (int) $record->id,
                    action: $completed ? 'WORK_COMPLETED' : 'WORK_STARTED',
                    occurredAt: $occurredAt,
                    reference: (string) $record->asset_code,
                    label: (string) $record->request_title,
                    status: (string) $record->request_status,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * One activity item. Every key is always present so a client can read the
     * shape without probing; `null` means "this source does not record that
     * fact", never "unknown". A row with no usable timestamp is dropped, because
     * it cannot be placed on a recent-activity timeline at all.
     *
     * @return array<string, mixed>|null
     */
    private function item(
        string $type,
        int $recordId,
        string $action,
        mixed $occurredAt,
        ?string $reference = null,
        ?string $label = null,
        ?string $status = null,
        ?int $quantity = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
    ): ?array {
        $moment = $this->moment($occurredAt);

        if ($moment === null) {
            return null;
        }

        return [
            'type' => $type,
            'action' => $action,
            'occurred_at' => $moment->toISOString(),
            'record_id' => $recordId,
            'reference' => $reference,
            'label' => $label,
            'status' => $status,
            'quantity' => $quantity,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            // Internal sort key, stripped before the item is returned.
            'at' => $moment->getTimestamp(),
        ];
    }

    /**
     * Normalises a driver-specific timestamp (SQLite returns a bare string,
     * PostgreSQL an offset string) into a single comparable instant. No
     * timezone conversion is done in SQL and no day boundary is invented.
     */
    private function moment(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        $moment = rescue(fn (): Carbon => Carbon::parse((string) $value), null, false);

        return $moment instanceof CarbonInterface ? $moment : null;
    }
}
