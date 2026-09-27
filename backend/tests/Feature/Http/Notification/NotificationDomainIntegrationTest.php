<?php

namespace Tests\Feature\Http\Notification;

use App\Models\Asset;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\User;

/**
 * Verifies that the domain services emit server-side notifications for real
 * transitions only, inside the domain transaction, and never duplicate rows on
 * repeated (no-op) updates.
 */
class NotificationDomainIntegrationTest extends NotificationTestCase
{
    private User $technician;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->technician = $this->userWithRole('technician');
        $this->assignee = $this->userWithRole('staff');
    }

    // -----------------------------------------------------------------------
    // Tickets
    // -----------------------------------------------------------------------

    public function test_ticket_assignment_notifies_the_assignee(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tickets/{$ticket->id}", ['assigned_to' => $this->technician->id])
            ->assertOk();

        $notifications = Notification::where('user_id', $this->technician->id)
            ->where('type', Notification::TYPE_TICKET_ASSIGNED)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame('Ticket assigned to you', $notifications[0]->title);
        $this->assertSame(['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number], $notifications[0]->data);
    }

    public function test_reassigning_the_same_assignee_does_not_duplicate_notifications(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tickets/{$ticket->id}", ['assigned_to' => $this->technician->id])
            ->assertOk();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tickets/{$ticket->id}", ['assigned_to' => $this->technician->id])
            ->assertOk();

        $this->assertSame(
            1,
            Notification::where('user_id', $this->technician->id)
                ->where('type', Notification::TYPE_TICKET_ASSIGNED)
                ->count(),
        );
    }

    public function test_ticket_status_change_notifies_the_requester(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => Ticket::STATUS_OPEN,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tickets/{$ticket->id}", ['status' => Ticket::STATUS_IN_PROGRESS])
            ->assertOk();

        $notifications = Notification::where('user_id', $this->staff->id)
            ->where('type', Notification::TYPE_TICKET_STATUS_CHANGED)
            ->get();

        $this->assertCount(1, $notifications);
        // The payload column is jsonb, and PostgreSQL does not preserve JSON
        // object key order, so multi-key payloads are compared order-blind.
        $this->assertEquals(['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_number, 'status' => Ticket::STATUS_IN_PROGRESS], $notifications[0]->data);
    }

    public function test_same_status_update_is_a_no_op_and_does_not_notify(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tickets/{$ticket->id}", ['status' => Ticket::STATUS_IN_PROGRESS])
            ->assertOk();

        $this->assertSame(
            0,
            Notification::where('user_id', $this->staff->id)
                ->where('type', Notification::TYPE_TICKET_STATUS_CHANGED)
                ->count(),
        );
    }

    public function test_assigning_to_self_does_not_notify_the_actor(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tickets/{$ticket->id}", ['assigned_to' => $this->admin->id])
            ->assertOk();

        $this->assertSame(
            0,
            Notification::where('user_id', $this->admin->id)
                ->where('type', Notification::TYPE_TICKET_ASSIGNED)
                ->count(),
        );
    }

    public function test_failed_assignment_creates_no_notification(): void
    {
        $inactive = $this->userWithRole('technician');
        $inactive->update(['is_active' => false]);

        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/tickets/{$ticket->id}", ['assigned_to' => $inactive->id])
            ->assertUnprocessable();

        $this->assertSame(0, Notification::where('user_id', $inactive->id)->where('type', Notification::TYPE_TICKET_ASSIGNED)->count());
    }

    // -----------------------------------------------------------------------
    // Maintenance
    // -----------------------------------------------------------------------

    private function maintenanceRequest(array $attributes = []): MaintenanceRequest
    {
        return MaintenanceRequest::factory()->create(array_merge([
            'requested_by' => $this->staff->id,
            'asset_id' => Asset::factory()->create(['status' => 'ACTIVE'])->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ], $attributes));
    }

    public function test_maintenance_assignment_notifies_the_assignee(): void
    {
        $request = $this->maintenanceRequest();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/maintenance-requests/{$request->id}", ['assigned_to' => $this->technician->id])
            ->assertOk();

        $notifications = Notification::where('user_id', $this->technician->id)
            ->where('type', Notification::TYPE_MAINTENANCE_ASSIGNED)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame(['maintenance_request_id' => $request->id], $notifications[0]->data);
    }

    public function test_maintenance_approval_notifies_the_requester(): void
    {
        $request = $this->maintenanceRequest();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/maintenance-requests/{$request->id}", ['status' => MaintenanceRequest::STATUS_APPROVED])
            ->assertOk();

        $notifications = Notification::where('user_id', $this->staff->id)
            ->where('type', Notification::TYPE_MAINTENANCE_APPROVED)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame('Maintenance request approved', $notifications[0]->title);
    }

    public function test_maintenance_completion_notifies_the_requester(): void
    {
        $request = $this->maintenanceRequest();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/maintenance-requests/{$request->id}", ['status' => MaintenanceRequest::STATUS_APPROVED])
            ->assertOk();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/maintenance-requests/{$request->id}", ['status' => MaintenanceRequest::STATUS_IN_PROGRESS])
            ->assertOk();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/maintenance-requests/{$request->id}", ['status' => MaintenanceRequest::STATUS_COMPLETED])
            ->assertOk();

        $notifications = Notification::where('user_id', $this->staff->id)
            ->where('type', Notification::TYPE_MAINTENANCE_COMPLETED)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame('Maintenance request completed', $notifications[0]->title);
    }

    // -----------------------------------------------------------------------
    // Asset assignment
    // -----------------------------------------------------------------------

    public function test_asset_assignment_notifies_the_assignee(): void
    {
        $asset = Asset::factory()->create(['status' => 'ACTIVE']);

        $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $asset->id,
            'user_id' => $this->assignee->id,
        ])->assertCreated();

        $notifications = Notification::where('user_id', $this->assignee->id)
            ->where('type', Notification::TYPE_ASSET_ASSIGNED)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame('Asset assigned to you', $notifications[0]->title);
        $this->assertSame($asset->id, $notifications[0]->data['asset_id']);
    }

    public function test_asset_return_notifies_the_holder(): void
    {
        $asset = Asset::factory()->create(['status' => 'ACTIVE']);

        $assignment = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $asset->id,
            'user_id' => $this->assignee->id,
        ])->assertCreated()->json('data');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/asset-assignments/{$assignment['id']}/return")
            ->assertOk();

        $notifications = Notification::where('user_id', $this->assignee->id)
            ->where('type', Notification::TYPE_ASSET_RETURNED)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame('Asset returned', $notifications[0]->title);
        $this->assertSame($asset->id, $notifications[0]->data['asset_id']);
    }

    public function test_asset_self_assignment_does_not_notify_the_actor(): void
    {
        $asset = Asset::factory()->create(['status' => 'ACTIVE']);

        $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $asset->id,
            'user_id' => $this->admin->id,
        ])->assertCreated();

        $this->assertSame(
            0,
            Notification::where('user_id', $this->admin->id)
                ->where('type', Notification::TYPE_ASSET_ASSIGNED)
                ->count(),
        );
    }
}
