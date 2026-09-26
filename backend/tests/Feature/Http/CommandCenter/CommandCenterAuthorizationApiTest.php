<?php

namespace Tests\Feature\Http\CommandCenter;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetHistory;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\TicketHistory;
use App\Models\User;

/**
 * Authorization and scope.
 *
 * `view_dashboard` is granted to five roles that do not share one data
 * visibility, so these tests pin the two decisions the endpoint makes: which
 * sections exist, and whose rows are counted. They are the security contract —
 * a caller must never receive a global count for a domain they cannot read, nor
 * another person's tickets or maintenance requests.
 */
class CommandCenterAuthorizationApiTest extends CommandCenterTestCase
{
    public function test_a_guest_is_rejected(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized();
    }

    public function test_a_role_without_view_dashboard_is_denied(): void
    {
        // technician holds every operational permission but not view_dashboard.
        $this->assertFalse($this->technician->hasPermission('view_dashboard'));

        $this->snapshotFor($this->technician)
            ->assertForbidden()
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_a_viewer_without_view_reports_still_reaches_the_command_center(): void
    {
        // staff cannot read organization-wide reports, and does not need to: the
        // Command Center is the operational surface.
        $this->assertFalse($this->staff->hasPermission('view_reports'));
        $this->assertTrue($this->staff->hasPermission('view_dashboard'));

        $this->snapshotFor($this->staff)->assertOk();
    }

    public function test_a_caller_never_receives_a_section_for_a_domain_they_cannot_read(): void
    {
        // warehouse_staff reads assets and inventory, and nothing else.
        $this->assertFalse($this->warehouseStaff->hasPermission('view_tickets'));
        $this->assertFalse($this->warehouseStaff->hasPermission('view_maintenance'));

        $response = $this->snapshotFor($this->warehouseStaff)->assertOk();

        $sections = array_keys($response->json('data.snapshot'));

        $this->assertSame(['assets', 'inventory', 'notifications'], $sections);
        $this->assertArrayNotHasKey('tickets', $response->json('data.snapshot'));
        $this->assertArrayNotHasKey('maintenance', $response->json('data.snapshot'));

        $this->assertSame(
            ['pending_asset_assignments'],
            array_keys($response->json('data.queues'))
        );
    }

    public function test_ticket_counts_are_restricted_to_the_callers_own_tickets_without_an_agent_permission(): void
    {
        $mine = Ticket::factory()->create(['requester_id' => $this->staff->id, 'status' => Ticket::STATUS_OPEN]);
        Ticket::factory()->count(4)->create(['requester_id' => $this->manager->id, 'status' => Ticket::STATUS_OPEN]);

        $this->assertFalse($this->staff->hasPermission('manage_tickets'));

        $response = $this->snapshotFor($this->staff)->assertOk();

        $response->assertJsonPath('data.snapshot.tickets.total', 1)
            ->assertJsonPath('data.queues.unassigned_tickets.count', 1);

        $this->assertSame($mine->id, $response->json('data.queues.unassigned_tickets.items.0.id'));
    }

    public function test_an_agent_sees_organization_wide_ticket_counts(): void
    {
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'status' => Ticket::STATUS_OPEN]);
        Ticket::factory()->count(4)->create(['requester_id' => $this->manager->id, 'status' => Ticket::STATUS_OPEN]);

        $this->assertTrue($this->manager->hasPermission('assign_tickets'));

        $this->snapshotFor($this->manager)
            ->assertOk()
            ->assertJsonPath('data.snapshot.tickets.total', 5)
            ->assertJsonPath('data.queues.unassigned_tickets.count', 5);
    }

    public function test_maintenance_counts_are_restricted_to_the_callers_own_requests_without_manage_maintenance(): void
    {
        $asset = Asset::factory()->create();

        MaintenanceRequest::factory()->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ]);
        MaintenanceRequest::factory()->count(3)->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->manager->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ]);

        $this->assertFalse($this->staff->hasPermission('manage_maintenance'));

        $this->snapshotFor($this->staff)
            ->assertOk()
            ->assertJsonPath('data.snapshot.maintenance.total', 1)
            ->assertJsonPath('data.queues.unassigned_maintenance_requests.count', 1);
    }

    public function test_a_maintenance_manager_sees_organization_wide_request_counts(): void
    {
        $asset = Asset::factory()->create();

        MaintenanceRequest::factory()->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ]);
        MaintenanceRequest::factory()->count(3)->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->manager->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ]);

        $this->assertTrue($this->admin->hasPermission('manage_maintenance'));

        $this->snapshotFor($this->admin)
            ->assertOk()
            ->assertJsonPath('data.snapshot.maintenance.total', 4);
    }

    public function test_activity_never_carries_another_persons_ticket_or_request_events(): void
    {
        $other = User::factory()->create();

        $foreignTicket = Ticket::factory()->create([
            'requester_id' => $other->id,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);
        TicketHistory::factory()->create([
            'ticket_id' => $foreignTicket->id,
            'action' => TicketHistory::ACTION_STATUS_CHANGED,
            'created_at' => now(),
        ]);

        $ownTicket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);
        TicketHistory::factory()->create([
            'ticket_id' => $ownTicket->id,
            'action' => TicketHistory::ACTION_STATUS_CHANGED,
            'created_at' => now(),
        ]);

        $items = $this->snapshotFor($this->staff)->assertOk()->json('data.recent_activity.items');

        $this->assertCount(1, $items);
        $this->assertSame($ownTicket->ticket_number, $items[0]['reference']);
    }

    public function test_activity_sources_follow_the_same_scope_as_the_snapshot(): void
    {
        $asset = Asset::factory()->create();
        $foreignRequest = MaintenanceRequest::factory()->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->manager->id,
            'status' => MaintenanceRequest::STATUS_IN_PROGRESS,
        ]);
        MaintenanceRecord::factory()->create([
            'maintenance_request_id' => $foreignRequest->id,
            'asset_id' => $foreignRequest->asset_id,
            'created_at' => now(),
        ]);

        $types = collect($this->snapshotFor($this->staff)->assertOk()->json('data.recent_activity.items'))
            ->pluck('type')
            ->all();

        $this->assertNotContains('maintenance', $types);

        // The same record is visible to a maintenance manager.
        $this->assertContains(
            'maintenance',
            collect($this->snapshotFor($this->admin)->assertOk()->json('data.recent_activity.items'))->pluck('type')->all()
        );
    }

    public function test_the_pending_assignment_queue_needs_the_assignment_permission(): void
    {
        AssetAssignment::factory()->create(['asset_id' => Asset::factory()->create()->id, 'status' => 'PENDING']);

        // manager holds view_asset_assignments, staff does not.
        $this->assertTrue($this->manager->hasPermission('view_asset_assignments'));
        $this->assertFalse($this->staff->hasPermission('view_asset_assignments'));

        $this->snapshotFor($this->manager)
            ->assertOk()
            ->assertJsonPath('data.queues.pending_asset_assignments.count', 1);

        $this->assertArrayNotHasKey(
            'pending_asset_assignments',
            $this->snapshotFor($this->staff)->assertOk()->json('data.queues')
        );
    }

    public function test_the_inbox_count_never_leaks_another_users_unread_rows(): void
    {
        Notification::factory()->count(2)->create(['user_id' => $this->staff->id, 'read_at' => null]);
        Notification::factory()->count(7)->create(['user_id' => $this->admin->id, 'read_at' => null]);

        $this->snapshotFor($this->staff)
            ->assertOk()
            ->assertJsonPath('data.snapshot.notifications.unread_count', 2);
    }

    public function test_a_super_admin_bypasses_permission_checks_and_sees_every_section(): void
    {
        $response = $this->snapshotFor($this->superAdmin)->assertOk();

        $this->assertSame(
            ['assets', 'inventory', 'tickets', 'maintenance', 'notifications'],
            array_keys($response->json('data.snapshot'))
        );
        $this->assertSame(
            [
                'unassigned_tickets',
                'unassigned_maintenance_requests',
                'pending_asset_assignments',
            ],
            array_keys($response->json('data.queues'))
        );
    }

    public function test_asset_and_inventory_activity_is_gated_by_its_own_domain_permission(): void
    {
        AssetHistory::factory()->create([
            'asset_id' => Asset::factory()->create()->id,
            'action' => 'ASSIGNED',
            'created_at' => now(),
        ]);

        $types = collect($this->snapshotFor($this->manager)->assertOk()->json('data.recent_activity.items'))
            ->pluck('type')
            ->all();

        $this->assertContains('asset', $types);
    }
}
