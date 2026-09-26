<?php

namespace Tests\Feature\Http\Maintenance;

use App\Models\Location;
use App\Models\MaintenanceRequest;

class MaintenanceRequestApiTest extends MaintenanceTestCase
{
    public function test_authenticated_user_can_list_maintenance_requests(): void
    {
        MaintenanceRequest::factory()->count(2)->create([
            'requested_by' => $this->staff->id,
            'asset_id' => $this->asset()->id,
        ]);

        $response = $this->actingAs($this->technician)->getJson('/api/v1/maintenance-requests');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => [
                            'id', 'title', 'description', 'priority', 'status',
                            'requested_at', 'approved_at', 'completed_at', 'asset',
                            'requester', 'assignee', 'created_at', 'updated_at',
                        ],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_list_maintenance_requests(): void
    {
        $this->getJson('/api/v1/maintenance-requests')->assertUnauthorized();
    }

    public function test_user_without_maintenance_permission_is_forbidden(): void
    {
        $response = $this->actingAs($this->warehouse)->getJson('/api/v1/maintenance-requests');

        $response->assertForbidden();
    }

    public function test_non_agent_sees_only_their_own_requests(): void
    {
        $mine = MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id]);
        MaintenanceRequest::factory()->create(['requested_by' => $this->manager->id, 'asset_id' => $this->asset()->id]);

        $this->actingAs($this->staff)->getJson('/api/v1/maintenance-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $mine->id);

        $this->actingAs($this->technician)->getJson('/api/v1/maintenance-requests')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    public function test_list_filters_by_status_and_priority(): void
    {
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id, 'status' => MaintenanceRequest::STATUS_APPROVED, 'priority' => MaintenanceRequest::PRIORITY_HIGH]);
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id, 'status' => MaintenanceRequest::STATUS_REQUESTED, 'priority' => MaintenanceRequest::PRIORITY_LOW]);

        $this->actingAs($this->technician)->getJson('/api/v1/maintenance-requests?status='.MaintenanceRequest::STATUS_APPROVED.'&priority='.MaintenanceRequest::PRIORITY_HIGH)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status', MaintenanceRequest::STATUS_APPROVED);
    }

    public function test_list_filters_by_asset_and_requester(): void
    {
        $targetAsset = $this->asset();
        $otherAsset = $this->asset();
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $targetAsset->id]);
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $otherAsset->id]);

        $this->actingAs($this->technician)->getJson("/api/v1/maintenance-requests?asset_id={$targetAsset->id}&requester_id={$this->staff->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset.id', $targetAsset->id);
    }

    public function test_list_filters_by_assigned_to(): void
    {
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id, 'assigned_to' => $this->technician->id]);
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id]);

        $this->actingAs($this->technician)->getJson("/api/v1/maintenance-requests?assigned_to={$this->technician->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.assignee.id', $this->technician->id);
    }

    public function test_list_filters_by_asset_location(): void
    {
        $location = Location::factory()->create();
        $targetAsset = $this->asset(['location_id' => $location->id]);
        $otherAsset = $this->asset();
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $targetAsset->id]);
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $otherAsset->id]);

        $this->actingAs($this->technician)->getJson("/api/v1/maintenance-requests?location_id={$location->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset.id', $targetAsset->id);
    }

    public function test_list_filters_by_request_date_range(): void
    {
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id, 'requested_at' => now()->subDays(10)]);
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id, 'requested_at' => now()]);

        $this->actingAs($this->technician)->getJson('/api/v1/maintenance-requests?requested_from='.now()->subDays(5)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_list_searches_by_title_and_description(): void
    {
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id, 'title' => 'Spindle bearing failure']);
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id, 'title' => 'Loose belt', 'description' => 'Pump vibrates loudly under load']);

        $this->actingAs($this->technician)->getJson('/api/v1/maintenance-requests?search=vibrates')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Loose belt');
    }

    public function test_list_rejects_unknown_sort_safely(): void
    {
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id]);

        $this->actingAs($this->technician)->getJson('/api/v1/maintenance-requests?sort=title&direction=asc')
            ->assertOk();
    }

    public function test_staff_can_create_a_maintenance_request(): void
    {
        $asset = $this->asset();

        $response = $this->actingAs($this->staff)->postJson('/api/v1/maintenance-requests', [
            'asset_id' => $asset->id,
            'title' => 'Fan making noise',
            'description' => 'The fan vibrates under load.',
            'priority' => MaintenanceRequest::PRIORITY_HIGH,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', MaintenanceRequest::STATUS_REQUESTED)
            ->assertJsonPath('data.priority', MaintenanceRequest::PRIORITY_HIGH)
            ->assertJsonPath('data.asset.id', $asset->id)
            ->assertJsonPath('data.requester.id', $this->staff->id);

        $this->assertDatabaseHas('maintenance_requests', [
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ]);
    }

    public function test_create_ignores_client_supplied_requester_status_and_assignment(): void
    {
        $asset = $this->asset();

        $response = $this->actingAs($this->staff)->postJson('/api/v1/maintenance-requests', [
            'asset_id' => $asset->id,
            'title' => 'Fan making noise',
            'description' => 'The fan vibrates under load.',
            'requested_by' => 999,
            'assigned_to' => $this->technician->id,
            'status' => MaintenanceRequest::STATUS_COMPLETED,
            'requested_at' => '2020-01-01 00:00:00',
            'approved_at' => now(),
            'completed_at' => now(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.requester.id', $this->staff->id)
            ->assertJsonPath('data.status', MaintenanceRequest::STATUS_REQUESTED)
            ->assertJsonPath('data.assignee', null)
            ->assertJsonPath('data.approved_at', null)
            ->assertJsonPath('data.completed_at', null);

        $request = MaintenanceRequest::findOrFail($response->json('data.id'));

        $this->assertSame($this->staff->id, $request->requested_by);
        $this->assertSame(MaintenanceRequest::STATUS_REQUESTED, $request->status);
        $this->assertNull($request->assigned_to);
        $this->assertNotSame('2020-01-01 00:00:00', $request->requested_at->toDateTimeString());
    }

    public function test_create_rejects_soft_deleted_asset(): void
    {
        $asset = $this->asset();
        $asset->delete();

        $this->actingAs($this->staff)->postJson('/api/v1/maintenance-requests', [
            'asset_id' => $asset->id,
            'title' => 'Fan making noise',
            'description' => 'The fan vibrates under load.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_id']);
    }

    public function test_create_rejects_ineligible_asset(): void
    {
        $asset = $this->asset(['status' => 'DISPOSED']);

        $this->actingAs($this->staff)->postJson('/api/v1/maintenance-requests', [
            'asset_id' => $asset->id,
            'title' => 'Fan making noise',
            'description' => 'The fan vibrates under load.',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This asset cannot be scheduled for maintenance in its current state.');
    }

    public function test_create_requires_title_description_and_asset(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v1/maintenance-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_id', 'title', 'description']);
    }

    public function test_show_includes_asset_requester_and_records(): void
    {
        $request = $this->inProgressRequest();

        $response = $this->actingAs($this->technician)->getJson("/api/v1/maintenance-requests/{$request->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonPath('data.asset.id', $request->asset_id)
            ->assertJsonPath('data.requester.id', $this->staff->id)
            ->assertJsonStructure(['data' => ['records']]);
    }

    public function test_staff_cannot_view_someone_elses_request(): void
    {
        $request = MaintenanceRequest::factory()->create(['requested_by' => $this->manager->id, 'asset_id' => $this->asset()->id]);

        $this->actingAs($this->staff)->getJson("/api/v1/maintenance-requests/{$request->id}")
            ->assertForbidden();
    }

    public function test_manager_is_scoped_to_own_requests(): void
    {
        MaintenanceRequest::factory()->create(['requested_by' => $this->manager->id, 'asset_id' => $this->asset()->id]);
        MaintenanceRequest::factory()->create(['requested_by' => $this->staff->id, 'asset_id' => $this->asset()->id]);

        $this->actingAs($this->manager)->getJson('/api/v1/maintenance-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.requester.id', $this->manager->id);
    }

    public function test_technician_can_update_editable_fields(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'title' => 'Updated title',
            'description' => 'Updated description',
            'priority' => MaintenanceRequest::PRIORITY_URGENT,
        ])->assertOk()
            ->assertJsonPath('data.title', 'Updated title')
            ->assertJsonPath('data.priority', MaintenanceRequest::PRIORITY_URGENT);
    }

    public function test_staff_cannot_update_a_request(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->staff)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'title' => 'Hijacked',
        ])->assertForbidden();
    }

    public function test_missing_request_returns_404(): void
    {
        $this->actingAs($this->technician)->getJson('/api/v1/maintenance-requests/99999')->assertNotFound();
    }
}
