<?php

namespace Tests\Feature\Http\Maintenance;

use App\Models\MaintenanceRequest;

class MaintenanceAssignmentTest extends MaintenanceTestCase
{
    public function test_technician_can_assign_a_request(): void
    {
        $request = $this->makeRequest();
        $assignee = $this->userWithRole('technician');

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'assigned_to' => $assignee->id,
        ])->assertOk()
            ->assertJsonPath('data.assignee.id', $assignee->id);
    }

    public function test_request_can_be_reassigned(): void
    {
        $assignee = $this->userWithRole('technician');
        $request = $this->makeRequest(['assigned_to' => $this->technician->id]);

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'assigned_to' => $assignee->id,
        ])->assertOk()
            ->assertJsonPath('data.assignee.id', $assignee->id);
    }

    public function test_request_can_be_unassigned(): void
    {
        $request = $this->makeRequest(['assigned_to' => $this->technician->id]);

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'assigned_to' => null,
        ])->assertOk()
            ->assertJsonPath('data.assignee', null);
    }

    public function test_cannot_assign_to_an_inactive_user(): void
    {
        $inactive = $this->userWithRole('technician');
        $inactive->update(['is_active' => false]);
        $request = $this->makeRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'assigned_to' => $inactive->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Cannot assign maintenance to an inactive user');
    }

    public function test_cannot_assign_to_user_without_maintenance_capability(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'assigned_to' => $this->staff->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Assigned user is not allowed to perform maintenance work');
    }

    public function test_cannot_assign_to_a_nonexistent_user(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'assigned_to' => 99999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_to']);
    }

    public function test_staff_cannot_assign_maintenance(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->staff)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'assigned_to' => $this->technician->id,
        ])->assertForbidden();
    }

    public function test_assigning_does_not_touch_asset_assignment_or_status(): void
    {
        $asset = $this->asset(['status' => 'ACTIVE', 'current_user_id' => $this->staff->id]);
        $request = $this->makeRequest(['asset_id' => $asset->id, 'status' => MaintenanceRequest::STATUS_APPROVED]);

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'assigned_to' => $this->technician->id,
        ])->assertOk();

        $asset->refresh();

        $this->assertSame('ACTIVE', $asset->status);
        $this->assertSame($this->staff->id, $asset->current_user_id);
        $this->assertDatabaseCount('asset_assignments', 0);
    }
}
