<?php

namespace Tests\Feature\Http\Ticket;

use App\Models\Ticket;

class TicketAssignmentTest extends TicketTestCase
{
    public function test_agent_can_assign_ticket(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'assigned_to' => $this->technician->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.assignee.id', $this->technician->id);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => $this->technician->id]);

        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $ticket->id,
            'action' => 'ASSIGNMENT_CHANGED',
            'user_id' => $this->admin->id,
            'notes' => "Assigned to {$this->technician->name}",
        ]);
    }

    public function test_technician_and_manager_can_assign(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->technician)
            ->putJson("/api/v1/tickets/{$ticket->id}", ['assigned_to' => $this->manager->id])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $this->manager->id);

        $other = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->manager)
            ->putJson("/api/v1/tickets/{$other->id}", ['assigned_to' => $this->technician->id])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $this->technician->id);
    }

    public function test_assignee_can_be_reassigned(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'assigned_to' => $this->technician->id,
        ]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'assigned_to' => $this->manager->id,
        ])->assertOk()
            ->assertJsonPath('data.assignee.id', $this->manager->id);
    }

    public function test_ticket_can_be_unassigned(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'assigned_to' => $this->technician->id,
        ]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'assigned_to' => null,
        ])->assertOk()
            ->assertJsonPath('data.assignee', null);

        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $ticket->id,
            'action' => 'ASSIGNMENT_CHANGED',
            'notes' => 'Unassigned',
        ]);
    }

    public function test_cannot_assign_to_inactive_user(): void
    {
        $inactive = $this->technician;
        $inactive->update(['is_active' => false]);

        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'assigned_to' => $inactive->id,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Cannot assign a ticket to an inactive user');
    }

    public function test_cannot_assign_to_user_without_assign_permission(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'assigned_to' => $this->staff->id,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Assigned user is not allowed to handle tickets');
    }

    public function test_cannot_assign_to_nonexistent_user(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'assigned_to' => 99999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_to']);
    }

    public function test_assignment_failure_leaves_state_unchanged(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'assigned_to' => $this->staff->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => null]);
        $this->assertDatabaseMissing('ticket_histories', [
            'ticket_id' => $ticket->id,
            'action' => 'ASSIGNMENT_CHANGED',
        ]);
    }

    public function test_staff_cannot_assign_a_ticket(): void
    {
        $own = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->staff)->putJson("/api/v1/tickets/{$own->id}", [
            'assigned_to' => $this->technician->id,
        ])->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_assign(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->putJson("/api/v1/tickets/{$ticket->id}", ['assigned_to' => $this->technician->id])
            ->assertUnauthorized();
    }
}
