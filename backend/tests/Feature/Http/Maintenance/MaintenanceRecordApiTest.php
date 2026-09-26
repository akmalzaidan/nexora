<?php

namespace Tests\Feature\Http\Maintenance;

use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;

class MaintenanceRecordApiTest extends MaintenanceTestCase
{
    public function test_technician_can_create_a_record_on_an_approved_request(): void
    {
        $request = $this->approvedRequest();

        $response = $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => $request->id,
            'description' => 'Inspected the pump and replaced the seal.',
            'cost' => 45.50,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.asset.id', $request->asset_id)
            ->assertJsonPath('data.request.id', $request->id)
            ->assertJsonPath('data.request.status', MaintenanceRequest::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.technician.id', $this->technician->id)
            ->assertJsonPath('data.started_at', fn ($value) => $value !== null);

        $request->refresh();

        $this->assertSame(MaintenanceRequest::STATUS_IN_PROGRESS, $request->status);
    }

    public function test_record_requires_maintenance_request_id(): void
    {
        $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'description' => 'No request provided.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['maintenance_request_id']);
    }

    public function test_cannot_create_a_record_on_a_requested_request(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => $request->id,
            'description' => 'Starting work too early.',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Maintenance work can only be started on an approved request.');
    }

    public function test_cannot_create_a_record_on_a_completed_request(): void
    {
        $request = $this->inProgressRequest();
        $request->update(['status' => MaintenanceRequest::STATUS_COMPLETED]);

        $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => $request->id,
            'description' => 'Late entry.',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Maintenance work can only be started on an approved request.');
    }

    public function test_cannot_create_a_record_on_a_cancelled_request(): void
    {
        $request = $this->makeRequest();
        $request->update(['status' => MaintenanceRequest::STATUS_CANCELLED]);

        $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => $request->id,
            'description' => 'Late entry.',
        ])->assertStatus(422);
    }

    public function test_missing_request_returns_404(): void
    {
        $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => 99999,
            'description' => 'Nowhere to attach this.',
        ])->assertNotFound();
    }

    public function test_technician_falls_back_to_request_assignee(): void
    {
        $assignee = $this->userWithRole('technician');
        $request = $this->approvedRequest();
        $request->update(['assigned_to' => $assignee->id]);

        $response = $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => $request->id,
            'description' => 'Work performed by the assigned technician.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.technician.id', $assignee->id);
    }

    public function test_explicit_inactive_technician_is_rejected(): void
    {
        $inactive = $this->userWithRole('technician');
        $inactive->update(['is_active' => false]);
        $request = $this->approvedRequest();

        $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => $request->id,
            'description' => 'Wrong technician.',
            'technician_id' => $inactive->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Cannot assign maintenance work to an inactive user');
    }

    public function test_explicit_technician_without_capability_is_rejected(): void
    {
        $request = $this->approvedRequest();

        $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => $request->id,
            'description' => 'Wrong technician.',
            'technician_id' => $this->staff->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Assigned user is not allowed to perform maintenance work');
    }

    public function test_record_asset_is_always_the_request_asset(): void
    {
        $request = $this->approvedRequest();
        $otherAsset = $this->asset();

        $response = $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => $request->id,
            'description' => 'Work on this request.',
            'asset_id' => $otherAsset->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.asset.id', $request->asset_id);

        $this->assertDatabaseHas('maintenance_records', [
            'id' => $response->json('data.id'),
            'asset_id' => $request->asset_id,
        ]);
    }

    public function test_staff_cannot_create_a_record(): void
    {
        $request = $this->approvedRequest();

        $this->actingAs($this->staff)->postJson('/api/v1/maintenance-records', [
            'maintenance_request_id' => $request->id,
            'description' => 'Staff acting as a technician.',
        ])->assertForbidden();
    }

    public function test_record_show_includes_technician_and_parts(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        MaintenancePart::factory()->create([
            'maintenance_record_id' => $record->id,
            'item_id' => $this->item()->id,
        ]);

        $this->actingAs($this->technician)->getJson("/api/v1/maintenance-records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.technician.id', $this->technician->id)
            ->assertJsonCount(1, 'data.parts');
    }

    public function test_record_work_fields_can_be_updated(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-records/{$record->id}", [
            'description' => 'Final inspection performed.',
            'result' => 'All clear.',
            'cost' => 99.99,
            'completed_at' => now(),
        ])->assertOk()
            ->assertJsonPath('data.result', 'All clear.')
            ->assertJsonPath('data.cost', '99.99')
            ->assertJsonPath('data.completed_at', fn ($value) => $value !== null);
    }

    public function test_record_cannot_change_its_request_or_asset(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        $otherRequest = $this->approvedRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-records/{$record->id}", [
            'maintenance_request_id' => $otherRequest->id,
            'asset_id' => $otherRequest->asset_id,
        ])->assertOk();

        $record->refresh();

        $this->assertSame($request->id, $record->maintenance_request_id);
        $this->assertSame($request->asset_id, $record->asset_id);
    }

    public function test_staff_sees_only_records_of_own_requests(): void
    {
        $own = $this->inProgressRequest();
        $otherRequest = MaintenanceRequest::factory()->create(['requested_by' => $this->manager->id, 'asset_id' => $this->asset()->id, 'status' => MaintenanceRequest::STATUS_IN_PROGRESS]);
        MaintenanceRecord::factory()->create(['maintenance_request_id' => $otherRequest->id, 'asset_id' => $otherRequest->asset_id, 'technician_id' => $this->technician->id]);

        $this->actingAs($this->staff)->getJson('/api/v1/maintenance-records')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $own->records()->first()->id);
    }

    public function test_records_list_filters_by_request_and_technician(): void
    {
        $first = $this->inProgressRequest();
        $second = $this->approvedRequest();
        $otherTech = $this->userWithRole('technician');
        MaintenanceRecord::factory()->create(['maintenance_request_id' => $second->id, 'asset_id' => $second->asset_id, 'technician_id' => $this->technician->id]);

        $this->actingAs($this->technician)->getJson('/api/v1/maintenance-records?maintenance_request_id='.$first->id.'&technician_id='.$otherTech->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->actingAs($this->technician)->getJson('/api/v1/maintenance-records?maintenance_request_id='.$first->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $first->records()->first()->id);
    }

    public function test_no_delete_endpoint_exists_for_records(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();

        $this->actingAs($this->technician)->deleteJson("/api/v1/maintenance-records/{$record->id}")
            ->assertMethodNotAllowed();

        $this->assertDatabaseHas('maintenance_records', ['id' => $record->id]);
    }

    public function test_unauthenticated_user_cannot_list_records(): void
    {
        $this->getJson('/api/v1/maintenance-records')->assertUnauthorized();
    }
}
