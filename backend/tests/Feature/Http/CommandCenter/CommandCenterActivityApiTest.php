<?php

namespace Tests\Feature\Http\CommandCenter;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetHistory;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\Warehouse;

/**
 * The activity contract: one newest-first timeline that keeps each source's own
 * event vocabulary instead of merging incompatible history semantics.
 */
class CommandCenterActivityApiTest extends CommandCenterTestCase
{
    public function test_activity_reports_each_source_with_its_own_action_vocabulary(): void
    {
        $this->assetEvent('ASSIGNED');
        $this->stockEvent(StockMovement::TYPE_STOCK_IN);
        $this->ticketEvent(TicketHistory::ACTION_STATUS_CHANGED);
        $this->maintenanceEvent(completed: true);

        $response = $this->snapshotFor($this->admin)->assertOk();

        $response->assertJsonPath('data.recent_activity.limit', 10)
            ->assertJsonCount(4, 'data.recent_activity.items');

        $byType = collect($response->json('data.recent_activity.items'))->keyBy('type');

        $this->assertSame('ASSIGNED', $byType['asset']['action']);
        $this->assertSame(StockMovement::TYPE_STOCK_IN, $byType['stock_movement']['action']);
        $this->assertSame(TicketHistory::ACTION_STATUS_CHANGED, $byType['ticket']['action']);
        $this->assertSame('WORK_COMPLETED', $byType['maintenance']['action']);
    }

    public function test_an_activity_item_exposes_a_stable_shape_with_explicit_nulls(): void
    {
        $this->stockEvent(StockMovement::TYPE_STOCK_OUT);

        $item = $this->snapshotFor($this->admin)
            ->assertOk()
            ->json('data.recent_activity.items.0');

        $this->assertSame(
            [
                'type',
                'action',
                'occurred_at',
                'record_id',
                'reference',
                'label',
                'status',
                'quantity',
                'old_status',
                'new_status',
            ],
            array_keys($item)
        );

        // A stock movement has no subject status and no status transition; the
        // keys are present and null rather than missing, and the quantity is
        // reported as recorded.
        $this->assertSame('stock_movement', $item['type']);
        $this->assertNull($item['status']);
        $this->assertNull($item['old_status']);
        $this->assertNull($item['new_status']);
        $this->assertIsInt($item['quantity']);
        $this->assertNotNull($item['occurred_at']);
    }

    public function test_activity_is_ordered_newest_first_across_every_source(): void
    {
        $oldest = $this->ticketEvent(TicketHistory::ACTION_CREATED, at: now()->subHours(3));
        $middle = $this->stockEvent(StockMovement::TYPE_STOCK_IN, at: now()->subHours(2));
        $newest = $this->assetEvent('RETURNED', at: now()->subHour());

        $items = $this->snapshotFor($this->admin)
            ->assertOk()
            ->json('data.recent_activity.items');

        $this->assertSame(
            [
                $newest->id,
                $middle->id,
                $oldest->id,
            ],
            array_column($items, 'record_id')
        );
        $this->assertSame(
            ['asset', 'stock_movement', 'ticket'],
            array_column($items, 'type')
        );
    }

    public function test_limit_bounds_the_timeline(): void
    {
        $this->ticketEvent(TicketHistory::ACTION_CREATED, at: now()->subMinutes(30));
        $this->ticketEvent(TicketHistory::ACTION_UPDATED, at: now()->subMinutes(20));
        $this->ticketEvent(TicketHistory::ACTION_STATUS_CHANGED, at: now()->subMinutes(10));
        $this->stockEvent(StockMovement::TYPE_STOCK_IN, at: now()->subMinute());

        $response = $this->snapshotFor($this->admin, '?limit=2')->assertOk();

        $response->assertJsonPath('data.recent_activity.limit', 2)
            ->assertJsonCount(2, 'data.recent_activity.items');
    }

    public function test_two_events_sharing_a_timestamp_keep_a_deterministic_order(): void
    {
        $moment = now();

        $this->ticketEvent(TicketHistory::ACTION_CREATED, at: $moment);
        $this->assetEvent('ASSIGNED', at: $moment);

        $first = $this->snapshotFor($this->admin)->assertOk()->json('data.recent_activity.items');

        $second = $this->snapshotFor($this->admin)->assertOk()->json('data.recent_activity.items');

        $this->assertSame($first, $second);
        // Same instant: the type tiebreak orders asset before stock/ticket
        // alphabetically, so the sequence is fixed rather than incidental.
        $this->assertSame('asset', $first[0]['type']);
    }

    public function test_a_status_transition_reports_both_ends_as_stored(): void
    {
        $this->ticketEvent(TicketHistory::ACTION_STATUS_CHANGED, old: Ticket::STATUS_OPEN, new: Ticket::STATUS_IN_PROGRESS);

        $item = $this->snapshotFor($this->admin)
            ->assertOk()
            ->json('data.recent_activity.items.0');

        $this->assertSame(Ticket::STATUS_OPEN, $item['old_status']);
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $item['new_status']);
        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $item['status']);
    }

    public function test_an_unfinished_work_record_is_reported_as_started_not_completed(): void
    {
        $this->maintenanceEvent(completed: false);

        $item = $this->snapshotFor($this->admin)
            ->assertOk()
            ->json('data.recent_activity.items.0');

        $this->assertSame('maintenance', $item['type']);
        $this->assertSame('WORK_STARTED', $item['action']);
    }

    public function test_a_system_with_no_events_returns_an_empty_timeline(): void
    {
        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.recent_activity.items', []);
    }

    private function assetEvent(string $action, mixed $at = null): AssetHistory
    {
        $category = AssetCategory::factory()->create();
        $asset = Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);

        return AssetHistory::factory()->create([
            'asset_id' => $asset->id,
            'action' => $action,
            'old_status' => 'DRAFT',
            'new_status' => 'ACTIVE',
            'created_at' => $at ?? now(),
        ]);
    }

    private function stockEvent(string $type, mixed $at = null): StockMovement
    {
        $item = Item::factory()->create(['item_category_id' => ItemCategory::factory()->create()->id]);

        return StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => Warehouse::factory()->create()->id,
            'type' => $type,
            'quantity' => 7,
            'created_at' => $at ?? now(),
        ]);
    }

    private function ticketEvent(string $action, ?string $old = null, ?string $new = null, mixed $at = null): TicketHistory
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        return TicketHistory::factory()->create([
            'ticket_id' => $ticket->id,
            'action' => $action,
            'old_status' => $old,
            'new_status' => $new,
            'created_at' => $at ?? now(),
        ]);
    }

    private function maintenanceEvent(bool $completed): MaintenanceRecord
    {
        $request = MaintenanceRequest::factory()->create([
            'asset_id' => Asset::factory()->create(),
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_IN_PROGRESS,
        ]);

        return MaintenanceRecord::factory()->create([
            'maintenance_request_id' => $request->id,
            'asset_id' => $request->asset_id,
            'started_at' => now()->subHour(),
            'completed_at' => $completed ? now() : null,
            'created_at' => now(),
        ]);
    }
}
