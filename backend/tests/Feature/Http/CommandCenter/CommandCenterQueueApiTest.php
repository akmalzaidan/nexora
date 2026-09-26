<?php

namespace Tests\Feature\Http\CommandCenter;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\MaintenanceRequest;
use App\Models\Ticket;

/**
 * The work-queue contract: a count plus a bounded list of records that are
 * waiting, defined only by stored columns.
 */
class CommandCenterQueueApiTest extends CommandCenterTestCase
{
    public function test_the_unassigned_ticket_queue_counts_every_waiting_ticket(): void
    {
        $this->unassignedTicket();
        $this->unassignedTicket();
        $this->unassignedTicket(status: Ticket::STATUS_IN_PROGRESS);

        // Finished or owned work is not waiting, so it never enters the queue.
        $this->unassignedTicket(status: Ticket::STATUS_RESOLVED);
        $this->unassignedTicket(status: Ticket::STATUS_CLOSED);
        Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => Ticket::STATUS_OPEN,
            'assigned_to' => $this->manager->id,
        ]);

        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.queues.unassigned_tickets.count', 3)
            ->assertJsonCount(3, 'data.queues.unassigned_tickets.items')
            ->assertJsonPath('data.queues.unassigned_tickets.limit', 10);
    }

    public function test_a_queue_item_exposes_only_the_summary_an_operator_needs(): void
    {
        $this->unassignedTicket();

        $item = $this->snapshotFor($this->admin)
            ->assertOk()
            ->json('data.queues.unassigned_tickets.items.0');

        $this->assertSame(
            ['id', 'type', 'reference', 'title', 'status', 'created_at'],
            array_keys($item)
        );
        $this->assertSame('ticket', $item['type']);
        $this->assertNotEmpty($item['reference']);
        $this->assertNotNull($item['created_at']);

        // The full record body (description, requester, department) is not part of
        // the queue contract: the workspace fetches it when a row is opened.
        $this->assertArrayNotHasKey('description', $item);
        $this->assertArrayNotHasKey('requester', $item);
    }

    public function test_limit_bounds_the_queue_items_but_never_the_queue_count(): void
    {
        $this->unassignedTicket();
        $this->unassignedTicket();
        $this->unassignedTicket();
        $this->unassignedTicket();

        $response = $this->snapshotFor($this->admin, '?limit=2')->assertOk();

        $response->assertJsonPath('data.queues.unassigned_tickets.count', 4)
            ->assertJsonCount(2, 'data.queues.unassigned_tickets.items')
            ->assertJsonPath('data.queues.unassigned_tickets.limit', 2);
    }

    public function test_the_unassigned_maintenance_queue_covers_the_unfinished_statuses(): void
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
            'status' => MaintenanceRequest::STATUS_IN_PROGRESS,
        ]);
        MaintenanceRequest::factory()->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_COMPLETED,
        ]);
        MaintenanceRequest::factory()->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_APPROVED,
            'assigned_to' => $this->manager->id,
        ]);

        $response = $this->snapshotFor($this->admin)->assertOk();

        $response->assertJsonPath('data.queues.unassigned_maintenance_requests.count', 2)
            ->assertJsonCount(2, 'data.queues.unassigned_maintenance_requests.items');

        // A maintenance request has no server-side reference number, so the queue
        // reports null rather than inventing one.
        $this->assertNull($response->json('data.queues.unassigned_maintenance_requests.items.0.reference'));
        $this->assertSame('maintenance_request', $response->json('data.queues.unassigned_maintenance_requests.items.0.type'));
    }

    public function test_the_pending_assignment_queue_reports_pending_handovers_by_asset(): void
    {
        $asset = Asset::factory()->create();

        AssetAssignment::factory()->create(['asset_id' => $asset->id, 'status' => 'PENDING']);
        AssetAssignment::factory()->create(['asset_id' => Asset::factory()->create()->id, 'status' => 'PENDING']);
        AssetAssignment::factory()->create(['asset_id' => Asset::factory()->create()->id, 'status' => 'ACTIVE']);
        AssetAssignment::factory()->create(['asset_id' => Asset::factory()->create()->id, 'status' => 'RETURNED']);

        $response = $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.queues.pending_asset_assignments.count', 2)
            ->assertJsonCount(2, 'data.queues.pending_asset_assignments.items');

        $item = $response->json('data.queues.pending_asset_assignments.items.0');

        $this->assertSame('asset_assignment', $item['type']);
        $this->assertSame('PENDING', $item['status']);
        $this->assertNotEmpty($item['reference']);
    }

    public function test_queue_rows_are_ordered_newest_first_deterministically(): void
    {
        $older = $this->unassignedTicket();
        $newer = $this->unassignedTicket();

        $older->forceFill(['created_at' => now()->subHour()])->save();

        $references = $this->snapshotFor($this->admin)
            ->assertOk()
            ->json('data.queues.unassigned_tickets.items.*.id');

        $this->assertSame([$newer->id, $older->id], $references);
    }

    public function test_an_empty_queue_is_an_empty_list_not_an_error(): void
    {
        $response = $this->snapshotFor($this->admin)->assertOk();

        foreach (['unassigned_tickets', 'unassigned_maintenance_requests', 'pending_asset_assignments'] as $queue) {
            $response->assertJsonPath("data.queues.{$queue}.count", 0)
                ->assertJsonPath("data.queues.{$queue}.items", []);
        }
    }

    private function unassignedTicket(string $status = Ticket::STATUS_OPEN): Ticket
    {
        return Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => $status,
            'assigned_to' => null,
        ]);
    }
}
