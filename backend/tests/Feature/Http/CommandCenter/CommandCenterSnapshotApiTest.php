<?php

namespace Tests\Feature\Http\CommandCenter;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\Warehouse;

/**
 * The snapshot contract: current state only, one aggregate per domain, no
 * invented metric.
 */
class CommandCenterSnapshotApiTest extends CommandCenterTestCase
{
    public function test_snapshot_returns_the_standard_envelope_with_the_three_sections(): void
    {
        $response = $this->snapshotFor($this->admin);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Command Center snapshot')
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'snapshot' => [
                        'assets' => ['total', 'active', 'in_maintenance', 'unassigned'],
                        'inventory' => ['item_count', 'warehouse_count', 'stock_quantity'],
                        'tickets' => ['total', 'active', 'unassigned'],
                        'maintenance' => ['total', 'active', 'unassigned', 'awaiting_approval'],
                        'notifications' => ['unread_count'],
                    ],
                    'queues' => [
                        'unassigned_tickets' => ['count', 'limit', 'items'],
                        'unassigned_maintenance_requests' => ['count', 'limit', 'items'],
                        'pending_asset_assignments' => ['count', 'limit', 'items'],
                    ],
                    'recent_activity' => ['limit', 'items'],
                ],
            ]);
    }

    public function test_an_empty_operation_is_a_valid_snapshot_of_zeroes_not_an_error(): void
    {
        $response = $this->snapshotFor($this->admin);

        $response->assertOk()
            ->assertJsonPath('data.snapshot.assets.total', 0)
            ->assertJsonPath('data.snapshot.inventory.stock_quantity', 0)
            ->assertJsonPath('data.snapshot.tickets.total', 0)
            ->assertJsonPath('data.snapshot.maintenance.total', 0)
            ->assertJsonPath('data.snapshot.notifications.unread_count', 0)
            ->assertJsonPath('data.queues.unassigned_tickets.count', 0)
            ->assertJsonPath('data.queues.unassigned_tickets.items', [])
            ->assertJsonPath('data.recent_activity.items', []);
    }

    public function test_asset_counters_come_from_the_assets_table(): void
    {
        $category = AssetCategory::factory()->create();

        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);
        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);
        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'MAINTENANCE']);
        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'DRAFT']);

        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.snapshot.assets', [
                'total' => 4,
                'active' => 2,
                'in_maintenance' => 1,
                'unassigned' => 4,
            ]);
    }

    public function test_held_assets_are_not_counted_as_unassigned(): void
    {
        $category = AssetCategory::factory()->create();

        Asset::factory()->create([
            'asset_category_id' => $category->id,
            'status' => 'ACTIVE',
            'current_user_id' => $this->staff->id,
        ]);
        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);

        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.snapshot.assets.total', 2)
            ->assertJsonPath('data.snapshot.assets.active', 2)
            ->assertJsonPath('data.snapshot.assets.unassigned', 1);
    }

    public function test_soft_deleted_assets_are_excluded_from_every_asset_counter(): void
    {
        $category = AssetCategory::factory()->create();

        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);
        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE'])
            ->delete();

        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.snapshot.assets.total', 1)
            ->assertJsonPath('data.snapshot.assets.active', 1);
    }

    public function test_stock_quantity_is_the_signed_journal_balance_not_a_row_count(): void
    {
        $item = Item::factory()->create(['item_category_id' => ItemCategory::factory()->create()->id]);
        $warehouse = Warehouse::factory()->create();

        StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 40,
            'performed_by' => $this->warehouseStaff->id,
        ]);
        StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_OUT,
            'quantity' => 15,
            'performed_by' => $this->warehouseStaff->id,
        ]);

        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.snapshot.inventory', [
                'item_count' => 1,
                'warehouse_count' => 1,
                'stock_quantity' => 25,
            ]);
    }

    public function test_active_ticket_workload_excludes_resolved_and_closed_tickets(): void
    {
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'status' => Ticket::STATUS_OPEN]);
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'status' => Ticket::STATUS_IN_PROGRESS, 'assigned_to' => $this->manager->id]);
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'status' => Ticket::STATUS_RESOLVED]);
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'status' => Ticket::STATUS_CLOSED]);

        $this->snapshotFor($this->admin)
            ->assertOk()
            // The open ticket has no assignee; the in-progress one does.
            ->assertJsonPath('data.snapshot.tickets', [
                'total' => 4,
                'active' => 2,
                'unassigned' => 1,
            ]);
    }

    public function test_maintenance_counters_split_active_work_from_approval_wait(): void
    {
        $asset = Asset::factory()->create();

        MaintenanceRequest::factory()->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ]);
        MaintenanceRequest::factory()->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_APPROVED,
            'assigned_to' => $this->manager->id,
        ]);
        MaintenanceRequest::factory()->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_COMPLETED,
        ]);

        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.snapshot.maintenance', [
                'total' => 3,
                'active' => 2,
                'unassigned' => 1,
                'awaiting_approval' => 1,
            ]);
    }

    public function test_the_unread_count_is_the_callers_own_inbox_only(): void
    {
        Notification::factory()->count(3)->create(['user_id' => $this->admin->id, 'read_at' => null]);
        Notification::factory()->read()->create(['user_id' => $this->admin->id]);

        // A colleague's unread rows must never be counted for this caller.
        Notification::factory()->count(5)->create(['user_id' => $this->manager->id, 'read_at' => null]);

        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.snapshot.notifications.unread_count', 3);

        $this->snapshotFor($this->manager)
            ->assertOk()
            ->assertJsonPath('data.snapshot.notifications.unread_count', 5);
    }

    public function test_the_snapshot_carries_no_period_or_date_window(): void
    {
        $response = $this->snapshotFor($this->admin)->assertOk();

        $keys = array_keys($response->json('data'));

        $this->assertSame(['generated_at', 'snapshot', 'queues', 'recent_activity'], $keys);

        foreach (['period', 'from', 'to', 'by_status', 'trend'] as $forbidden) {
            $this->assertStringNotContainsString(
                '"'.$forbidden.'"',
                json_encode($response->json('data.snapshot'), JSON_THROW_ON_ERROR),
                "The current-state snapshot must not carry a {$forbidden} section."
            );
        }
    }

    public function test_a_date_window_is_rejected_rather_than_silently_ignored(): void
    {
        foreach (['from', 'to'] as $parameter) {
            $this->snapshotFor($this->admin, "?{$parameter}=2026-01-01")
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonValidationErrors($parameter);
        }
    }

    public function test_a_period_window_is_rejected_even_when_it_is_complete_and_valid(): void
    {
        // A range that Reports would happily accept is still refused here: this
        // endpoint has no period, so accepting it would imply a filtered
        // current-state answer that does not exist.
        $this->snapshotFor($this->admin, '?from=2026-01-01&to=2026-01-31')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_limit_must_be_a_positive_integer_within_the_bounded_range(): void
    {
        foreach (['0', '-5', '51', 'abc', '2.5'] as $invalid) {
            $this->snapshotFor($this->admin, "?limit={$invalid}")
                ->assertStatus(422)
                ->assertJsonValidationErrors('limit');
        }

        foreach (['1', '50'] as $valid) {
            $this->snapshotFor($this->admin, "?limit={$valid}")
                ->assertOk()
                ->assertJsonPath('data.recent_activity.limit', (int) $valid);
        }
    }

    public function test_the_default_limit_is_ten_when_the_parameter_is_absent(): void
    {
        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.recent_activity.limit', 10);
    }
}
