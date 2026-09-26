<?php

namespace Tests\Feature\Http\Maintenance;

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;

class MaintenanceSecurityTest extends MaintenanceTestCase
{
    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/v1/maintenance-requests')->assertUnauthorized();
        $this->postJson('/api/v1/maintenance-requests', [])->assertUnauthorized();
        $this->getJson('/api/v1/maintenance-records')->assertUnauthorized();
        $this->putJson('/api/v1/maintenance-requests/1', [])->assertUnauthorized();
        $this->postJson('/api/v1/maintenance-records', [])->assertUnauthorized();
        $this->postJson('/api/v1/maintenance-records/1/parts', [])->assertUnauthorized();
    }

    public function test_user_without_maintenance_permissions_cannot_write(): void
    {
        $request = $this->approvedRequest();
        $record = MaintenanceRecord::factory()->create([
            'maintenance_request_id' => $request->id,
            'asset_id' => $request->asset_id,
            'technician_id' => $this->technician->id,
        ]);

        $this->actingAs($this->warehouse)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => MaintenanceRequest::STATUS_COMPLETED,
        ])->assertForbidden();

        $this->actingAs($this->warehouse)->postJson("/api/v1/maintenance-records/{$record->id}/parts", [
            'item_id' => $this->item()->id,
            'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_create_ignores_spoofed_actor_fields(): void
    {
        $spoof = $this->userWithRole('admin');
        $asset = $this->asset();

        $response = $this->actingAs($this->staff)->postJson('/api/v1/maintenance-requests', [
            'asset_id' => $asset->id,
            'title' => 'Spoofed identity attempt',
            'description' => 'Attempting to impersonate another actor.',
            'requested_by' => $spoof->id,
            'requested_at' => now()->subYears(2)->toDateTimeString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.requester.id', $this->staff->id);

        $model = MaintenanceRequest::findOrFail($response->json('data.id'));

        $this->assertSame($this->staff->id, $model->requested_by);
        $this->assertNotSame($spoof->id, $model->requested_by);
    }

    public function test_nonexistent_asset_is_rejected_as_invalid_foreign_key(): void
    {
        $this->actingAs($this->staff)->postJson('/api/v1/maintenance-requests', [
            'asset_id' => 99999,
            'title' => 'Ghost asset',
            'description' => 'No such asset exists.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_id']);
    }

    public function test_no_password_or_token_is_leaked_in_responses(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();

        $this->actingAs($this->technician)->getJson("/api/v1/maintenance-requests/{$request->id}")
            ->assertOk()
            ->assertJsonMissing(['password', 'token', 'remember_token']);

        $this->actingAs($this->technician)->getJson("/api/v1/maintenance-records/{$record->id}")
            ->assertOk()
            ->assertJsonMissing(['password', 'token', 'remember_token']);
    }

    public function test_detail_exposes_no_raw_model_fields(): void
    {
        $request = $this->approvedRequest();

        $this->actingAs($this->technician)->getJson("/api/v1/maintenance-requests/{$request->id}")
            ->assertOk()
            ->assertJsonMissing(['requested_by', 'assigned_to']);
    }
}
