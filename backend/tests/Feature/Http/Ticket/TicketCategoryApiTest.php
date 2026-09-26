<?php

namespace Tests\Feature\Http\Ticket;

use App\Models\Ticket;

class TicketCategoryApiTest extends TicketTestCase
{
    public function test_authenticated_viewer_can_list_ticket_categories(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/v1/ticket-categories');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => ['id', 'name', 'code', 'description', 'tickets_count', 'created_at', 'updated_at'],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ])
            ->assertJsonCount(2, 'data.items');
    }

    public function test_unauthenticated_cannot_list_ticket_categories(): void
    {
        $this->getJson('/api/v1/ticket-categories')->assertUnauthorized();
    }

    public function test_role_without_ticket_permissions_is_forbidden(): void
    {
        $warehouse = $this->userWithRole('warehouse_staff');

        $this->actingAs($warehouse)->getJson('/api/v1/ticket-categories')->assertForbidden();
    }

    public function test_search_filters_ticket_categories(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/ticket-categories?search=Hardware');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'HARDWARE');
    }

    public function test_manage_ticket_holder_can_create_ticket_category(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/ticket-categories', [
            'name' => 'Printer',
            'code' => 'PRINTER',
            'description' => 'Printer issues',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'PRINTER');

        $this->assertDatabaseHas('ticket_categories', ['code' => 'PRINTER']);
    }

    public function test_staff_cannot_create_ticket_category(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v1/ticket-categories', [
            'name' => 'Printer',
            'code' => 'PRINTER',
        ])->assertForbidden();
    }

    public function test_create_requires_name_and_code(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/ticket-categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code']);
    }

    public function test_create_with_duplicate_code_fails(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/ticket-categories', [
            'name' => 'Hardware 2',
            'code' => 'HARDWARE',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_can_show_ticket_category(): void
    {
        $response = $this->actingAs($this->staff)->getJson("/api/v1/ticket-categories/{$this->hardware->id}");

        $response->assertOk()
            ->assertJsonPath('data.code', 'HARDWARE');
    }

    public function test_can_update_ticket_category(): void
    {
        $response = $this->actingAs($this->admin)->putJson("/api/v1/ticket-categories/{$this->hardware->id}", [
            'name' => 'Hardware Support',
            'code' => 'HARDWARE',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Hardware Support');
    }

    public function test_update_same_code_is_allowed(): void
    {
        $this->actingAs($this->admin)->putJson("/api/v1/ticket-categories/{$this->hardware->id}", [
            'name' => 'Renamed',
            'code' => 'HARDWARE',
        ])->assertOk();
    }

    public function test_can_delete_unused_ticket_category(): void
    {
        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/ticket-categories/{$this->network->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('ticket_categories', ['id' => $this->network->id]);
    }

    public function test_cannot_delete_ticket_category_still_in_use(): void
    {
        Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'category_id' => $this->hardware->id,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/ticket-categories/{$this->hardware->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Ticket category cannot be deleted because it is still in use');

        $this->assertDatabaseHas('ticket_categories', ['id' => $this->hardware->id]);
    }

    public function test_sort_rejects_invalid_columns(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/ticket-categories?sort=invalid');

        $response->assertOk()
            ->assertJsonPath('data.items.0.code', 'HARDWARE');
    }
}
