<?php

namespace Tests\Feature\Http\Ticket;

use App\Models\Ticket;

class TicketStatusWorkflowTest extends TicketTestCase
{
    public function test_new_ticket_starts_open_without_closed_at(): void
    {
        $id = $this->actingAs($this->staff)
            ->postJson('/api/v1/tickets', ['title' => 'New', 'description' => 'x'])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('tickets', ['id' => $id, 'status' => 'OPEN', 'closed_at' => null]);

        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $id,
            'action' => 'CREATED',
            'user_id' => $this->staff->id,
        ]);
    }

    public function test_can_advance_through_full_lifecycle(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $inProgress = $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'status' => 'IN_PROGRESS',
        ]);
        $inProgress->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS');

        $resolved = $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'status' => 'RESOLVED',
        ]);
        $resolved->assertOk()
            ->assertJsonPath('data.status', 'RESOLVED')
            ->assertJsonPath('data.closed_at', null);

        $closed = $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'status' => 'CLOSED',
        ]);
        $closed->assertOk()
            ->assertJsonPath('data.status', 'CLOSED')
            ->assertJsonPath('data.closed_at', $this->freshTicket($ticket->id)->closed_at?->toISOString());
    }

    public function test_closing_sets_closed_at_and_reopening_clears_it(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => 'RESOLVED',
        ]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'CLOSED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CLOSED');

        $this->assertNotNull($this->freshTicket($ticket->id)->closed_at);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'OPEN'])
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN');

        $this->assertNull($this->freshTicket($ticket->id)->closed_at);
    }

    public function test_every_transition_appends_history_in_same_transaction(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'RESOLVED'])->assertOk();

        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $ticket->id,
            'action' => 'STATUS_CHANGED',
            'old_status' => 'OPEN',
            'new_status' => 'IN_PROGRESS',
            'user_id' => $this->admin->id,
        ]);

        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $ticket->id,
            'action' => 'STATUS_CHANGED',
            'old_status' => 'IN_PROGRESS',
            'new_status' => 'RESOLVED',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_skipping_a_state_is_rejected(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => 'OPEN',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'status' => 'RESOLVED',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid status transition from OPEN to RESOLVED');
    }

    public function test_direct_close_is_rejected(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'CLOSED'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid status transition from OPEN to CLOSED');
    }

    public function test_invalid_transition_leaves_state_and_history_untouched(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => 'OPEN',
        ]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'RESOLVED'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'OPEN']);
        $this->assertDatabaseMissing('ticket_histories', [
            'ticket_id' => $ticket->id,
            'action' => 'STATUS_CHANGED',
        ]);
    }

    public function test_same_status_is_a_noop_without_new_history(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => 'OPEN',
        ]);
        $count = $ticket->histories()->count();

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'OPEN'])
            ->assertOk()
            ->assertJsonPath('data.status', 'OPEN');

        $this->assertSame($count, $this->freshTicket($ticket->id)->histories()->count());
    }

    public function test_invalid_status_value_is_rejected_by_validation(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'WONTWORK'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_staff_cannot_change_status(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->staff)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'IN_PROGRESS'])
            ->assertForbidden();
    }

    public function test_reopen_appends_closed_to_open_history(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => 'CLOSED',
            'closed_at' => now(),
        ]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", ['status' => 'OPEN'])
            ->assertOk();

        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $ticket->id,
            'action' => 'STATUS_CHANGED',
            'old_status' => 'CLOSED',
            'new_status' => 'OPEN',
        ]);
    }

    private function freshTicket(int $id): Ticket
    {
        return Ticket::query()->findOrFail($id);
    }
}
