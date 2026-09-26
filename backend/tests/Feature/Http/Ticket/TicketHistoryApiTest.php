<?php

namespace Tests\Feature\Http\Ticket;

use App\Models\Ticket;
use App\Models\TicketHistory;

class TicketHistoryApiTest extends TicketTestCase
{
    public function test_agent_can_list_history(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => 'OPEN',
        ]);

        TicketHistory::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->staff->id,
            'action' => 'CREATED',
            'notes' => 'Ticket raised',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/tickets/{$ticket->id}/history");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => ['id', 'action', 'old_status', 'new_status', 'notes', 'user', 'created_at'],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ])
            ->assertJsonPath('data.items.0.action', 'CREATED');
    }

    public function test_history_is_chronological_oldest_first(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        TicketHistory::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->staff->id,
            'action' => 'CREATED',
            'created_at' => now()->subHours(2),
        ]);
        TicketHistory::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->admin->id,
            'action' => 'STATUS_CHANGED',
            'old_status' => 'OPEN',
            'new_status' => 'IN_PROGRESS',
            'created_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/tickets/{$ticket->id}/history");

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.action', 'CREATED')
            ->assertJsonPath('data.items.1.action', 'STATUS_CHANGED')
            ->assertJsonPath('data.items.1.old_status', 'OPEN')
            ->assertJsonPath('data.items.1.new_status', 'IN_PROGRESS');
    }

    public function test_history_exposes_actor_user(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        TicketHistory::factory()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->admin->id,
            'action' => 'UPDATED',
        ]);

        $this->actingAs($this->admin)->getJson("/api/v1/tickets/{$ticket->id}/history")
            ->assertOk()
            ->assertJsonPath('data.items.0.user.id', $this->admin->id);
    }

    public function test_staff_can_only_read_history_of_own_ticket(): void
    {
        $own = Ticket::factory()->create(['requester_id' => $this->staff->id]);
        $foreign = Ticket::factory()->create(['requester_id' => $this->manager->id]);

        $this->actingAs($this->staff)->getJson("/api/v1/tickets/{$own->id}/history")->assertOk();

        $this->actingAs($this->staff)->getJson("/api/v1/tickets/{$foreign->id}/history")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this ticket');
    }

    public function test_history_is_append_only_no_update_or_delete_routes(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}/history")
            ->assertStatus(405);

        $this->actingAs($this->admin)->deleteJson("/api/v1/tickets/{$ticket->id}/history")
            ->assertStatus(405);
    }

    public function test_post_history_is_not_an_endpoint(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->postJson("/api/v1/tickets/{$ticket->id}/history", [])
            ->assertStatus(405);
    }

    public function test_unauthenticated_cannot_read_history(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->getJson("/api/v1/tickets/{$ticket->id}/history")->assertUnauthorized();
    }

    public function test_missing_ticket_history_returns_404(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/tickets/99999/history')->assertNotFound();
    }
}
