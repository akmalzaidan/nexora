<?php

namespace Tests\Feature\Http\Ticket;

use App\Models\Ticket;
use Illuminate\Support\Carbon;

class TicketApiTest extends TicketTestCase
{
    public function test_agent_can_list_tickets_with_nested_relations(): void
    {
        Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'category_id' => $this->hardware->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/tickets');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => [
                            'id', 'ticket_number', 'title', 'description',
                            'category', 'requester', 'assignee', 'department', 'location',
                            'priority', 'status', 'closed_at', 'created_at', 'updated_at',
                        ],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ])
            ->assertJsonPath('data.items.0.requester.id', $this->staff->id)
            ->assertJsonPath('data.items.0.category.id', $this->hardware->id);
    }

    public function test_unauthenticated_cannot_list_tickets(): void
    {
        $this->getJson('/api/v1/tickets')->assertUnauthorized();
    }

    public function test_role_without_ticket_permissions_is_forbidden(): void
    {
        $warehouse = $this->userWithRole('warehouse_staff');

        $this->actingAs($warehouse)->getJson('/api/v1/tickets')->assertForbidden();
    }

    public function test_staff_only_sees_their_own_tickets(): void
    {
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'title' => 'Mine']);
        Ticket::factory()->create(['requester_id' => $this->manager->id, 'title' => 'Not mine']);

        $response = $this->actingAs($this->staff)->getJson('/api/v1/tickets');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Mine');
    }

    public function test_staff_cannot_scope_their_list_to_other_requester(): void
    {
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'title' => 'Mine']);
        Ticket::factory()->create(['requester_id' => $this->manager->id, 'title' => 'Others']);

        $other = Ticket::where('requester_id', $this->manager->id)->first();

        $response = $this->actingAs($this->staff)->getJson("/api/v1/tickets?requester_id={$other->id}");

        $response->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_search_by_ticket_number(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'ticket_number' => 'TCK-SEARCH-0001',
            'title' => 'Search me',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/tickets?search=TCK-SEARCH');

        $response->assertOk()
            ->assertJsonPath('data.items.0.id', $ticket->id)
            ->assertJsonPath('data.items.0.ticket_number', 'TCK-SEARCH-0001');
    }

    public function test_filter_by_status_and_priority(): void
    {
        Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => Ticket::STATUS_OPEN,
            'priority' => Ticket::PRIORITY_HIGH,
        ]);
        Ticket::factory()->create([
            'requester_id' => $this->admin->id,
            'status' => Ticket::STATUS_IN_PROGRESS,
            'priority' => Ticket::PRIORITY_LOW,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/tickets?status=OPEN&priority=HIGH');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status', 'OPEN');
    }

    public function test_filter_by_category(): void
    {
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'category_id' => $this->hardware->id]);
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'category_id' => $this->network->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/tickets?category_id={$this->network->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.category.id', $this->network->id);
    }

    public function test_default_sort_is_newest_first(): void
    {
        Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'title' => 'Older',
            'created_at' => Carbon::now()->subDay(),
        ]);
        Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'title' => 'Newer',
            'created_at' => Carbon::now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/tickets');

        $response->assertOk()
            ->assertJsonPath('data.items.0.title', 'Newer');
    }

    public function test_sort_rejects_invalid_columns(): void
    {
        Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'title' => 'Zulu',
            'created_at' => Carbon::now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/tickets?sort=INVALID_COLUMN&direction=asc');

        $response->assertOk()
            ->assertJsonPath('data.items.0.title', 'Zulu');
    }

    public function test_staff_can_create_ticket(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/v1/tickets', [
            'title' => 'Keyboard broken',
            'description' => 'The F1 key came off.',
            'category_id' => $this->hardware->id,
            'priority' => Ticket::PRIORITY_HIGH,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Ticket::STATUS_OPEN)
            ->assertJsonPath('data.requester.id', $this->staff->id)
            ->assertJsonPath('data.priority', 'HIGH');

        $this->assertStringStartsWith('TCK-', $response->json('data.ticket_number'));

        $this->assertDatabaseHas('tickets', [
            'ticket_number' => $response->json('data.ticket_number'),
            'requester_id' => $this->staff->id,
            'status' => Ticket::STATUS_OPEN,
        ]);
    }

    public function test_create_defaults_priority_to_medium(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/v1/tickets', [
            'title' => 'Defaults',
            'description' => 'No priority supplied.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.priority', 'MEDIUM');
    }

    public function test_requester_identity_always_comes_from_authenticated_user(): void
    {
        $other = $this->userWithRole('staff');

        $response = $this->actingAs($this->staff)->postJson('/api/v1/tickets', [
            'title' => 'No spoofing',
            'description' => 'Client-supplied requester_id must be ignored.',
            'requester_id' => $other->id,
            'assigned_to' => $this->technician->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.requester.id', $this->staff->id)
            ->assertJsonPath('data.assignee', null);
    }

    public function test_create_requires_title_and_description(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v1/tickets', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description']);
    }

    public function test_create_rejects_unknown_category(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v1/tickets', [
            'title' => 'Bad cat',
            'description' => 'x',
            'category_id' => 99999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_create_rejects_invalid_priority(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v1/tickets', [
            'title' => 'Bad prio',
            'description' => 'x',
            'priority' => 'CRITICAL',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);
    }

    public function test_agent_can_show_any_ticket(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
        $this->actingAs($this->manager)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
        $this->actingAs($this->technician)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
    }

    public function test_staff_can_show_own_ticket_but_not_another(): void
    {
        $own = Ticket::factory()->create(['requester_id' => $this->staff->id]);
        $foreign = Ticket::factory()->create(['requester_id' => $this->manager->id]);

        $this->actingAs($this->staff)->getJson("/api/v1/tickets/{$own->id}")->assertOk();

        $this->actingAs($this->staff)->getJson("/api/v1/tickets/{$foreign->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this ticket');
    }

    public function test_missing_ticket_returns_404(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/tickets/99999')->assertNotFound();
    }

    public function test_ticket_response_exposes_no_secrets(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/tickets/{$ticket->id}");

        $response->assertOk();
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.requester.password');
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_manage_ticket_holder_can_update_ticket(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'title' => 'Old title',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'title' => 'New title',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'New title');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'title' => 'New title']);
    }

    public function test_staff_cannot_update_ticket(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->staff)->putJson("/api/v1/tickets/{$ticket->id}", [
            'title' => 'Sneaky edit',
        ])->assertForbidden();
    }

    public function test_update_invalid_field_values_are_rejected(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$ticket->id}", [
            'priority' => 'NOPE',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);
    }

    public function test_update_records_uptime_for_field_changes(): void
    {
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'category_id' => $this->hardware->id,
        ]);

        $id = $ticket->id;

        $this->actingAs($this->admin)->putJson("/api/v1/tickets/{$id}", [
            'title' => 'Renamed title',
            'category_id' => $this->network->id,
        ])->assertOk();

        $this->assertDatabaseHas('ticket_histories', [
            'ticket_id' => $id,
            'action' => 'UPDATED',
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_no_delete_endpoint_for_tickets(): void
    {
        $ticket = Ticket::factory()->create(['requester_id' => $this->staff->id]);

        $this->actingAs($this->admin)->deleteJson("/api/v1/tickets/{$ticket->id}")->assertStatus(405);
    }

    public function test_per_page_is_capped_at_100(): void
    {
        Ticket::factory()->count(2)->create(['requester_id' => $this->staff->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/tickets?per_page=500');

        $response->assertOk()
            ->assertJsonPath('data.pagination.per_page', 100);
    }
}
