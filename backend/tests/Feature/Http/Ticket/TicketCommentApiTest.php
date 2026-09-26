<?php

namespace Tests\Feature\Http\Ticket;

use App\Models\Ticket;
use App\Models\TicketComment;

class TicketCommentApiTest extends TicketTestCase
{
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'assigned_to' => $this->technician->id,
        ]);
    }

    public function test_agent_can_list_comments(): void
    {
        TicketComment::factory()->create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->staff->id,
            'comment' => 'First follow up',
        ]);

        $response = $this->actingAs($this->technician)->getJson("/api/v1/tickets/{$this->ticket->id}/comments");

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => ['id', 'comment', 'is_internal', 'user', 'created_at', 'updated_at'],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ])
            ->assertJsonPath('data.items.0.user.id', $this->staff->id)
            ->assertJsonPath('data.items.0.is_internal', false);
    }

    public function test_staff_sees_only_public_comments_on_their_own_ticket(): void
    {
        TicketComment::factory()->create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->technician->id,
            'comment' => 'Internal note',
            'is_internal' => true,
        ]);
        TicketComment::factory()->create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->technician->id,
            'comment' => 'Public update',
            'is_internal' => false,
        ]);

        $response = $this->actingAs($this->staff)->getJson("/api/v1/tickets/{$this->ticket->id}/comments");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.comment', 'Public update');
    }

    public function test_staff_cannot_list_comments_on_another_ticket(): void
    {
        $foreign = Ticket::factory()->create(['requester_id' => $this->manager->id]);

        $this->actingAs($this->staff)->getJson("/api/v1/tickets/{$foreign->id}/comments")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this ticket');
    }

    public function test_staff_can_create_comment(): void
    {
        $response = $this->actingAs($this->staff)->postJson("/api/v1/tickets/{$this->ticket->id}/comments", [
            'comment' => 'Any update on the printer?',
            'is_internal' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.comment', 'Any update on the printer?')
            ->assertJsonPath('data.user.id', $this->staff->id)
            ->assertJsonPath('data.is_internal', false);

        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->staff->id,
            'is_internal' => false,
        ]);
    }

    public function test_staff_cannot_hide_a_comment_via_is_internal(): void
    {
        $this->actingAs($this->staff)->postJson("/api/v1/tickets/{$this->ticket->id}/comments", [
            'comment' => 'Pretending to be internal',
            'is_internal' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_internal', false);
    }

    public function test_agent_can_create_internal_comment(): void
    {
        $response = $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$this->ticket->id}/comments", [
            'comment' => 'Engineering note',
            'is_internal' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_internal', true);
    }

    public function test_comment_author_is_always_the_authenticated_user(): void
    {
        $this->actingAs($this->admin)->postJson("/api/v1/tickets/{$this->ticket->id}/comments", [
            'comment' => 'Commented by an agent',
        ])->assertCreated()
            ->assertJsonPath('data.user.id', $this->admin->id);
    }

    public function test_comment_requires_text_and_caps_length(): void
    {
        $this->actingAs($this->staff)->postJson("/api/v1/tickets/{$this->ticket->id}/comments", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->actingAs($this->staff)->postJson("/api/v1/tickets/{$this->ticket->id}/comments", [
            'comment' => str_repeat('a', 5001),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);
    }

    public function test_staff_cannot_comment_on_another_ticket(): void
    {
        $foreign = Ticket::factory()->create(['requester_id' => $this->manager->id]);

        $this->actingAs($this->staff)->postJson("/api/v1/tickets/{$foreign->id}/comments", [
            'comment' => 'Intrusion attempt',
        ])->assertForbidden();
    }

    public function test_agent_can_comment_on_any_ticket(): void
    {
        $foreign = Ticket::factory()->create(['requester_id' => $this->manager->id]);

        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$foreign->id}/comments", [
            'comment' => 'Taking a look',
        ])->assertCreated();
    }

    public function test_unauthenticated_cannot_create_comment(): void
    {
        $this->postJson("/api/v1/tickets/{$this->ticket->id}/comments", ['comment' => 'x'])
            ->assertUnauthorized();
    }

    public function test_missing_ticket_comment_target_returns_404(): void
    {
        $this->actingAs($this->staff)->getJson('/api/v1/tickets/99999/comments')->assertNotFound();
    }

    public function test_comments_do_not_create_history_rows(): void
    {
        $this->actingAs($this->technician)->postJson("/api/v1/tickets/{$this->ticket->id}/comments", [
            'comment' => 'No history for comments',
        ])->assertCreated();

        $this->assertDatabaseMissing('ticket_histories', [
            'ticket_id' => $this->ticket->id,
            'action' => 'COMMENTED',
        ]);
    }
}
