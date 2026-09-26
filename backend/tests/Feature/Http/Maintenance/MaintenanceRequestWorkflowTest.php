<?php

namespace Tests\Feature\Http\Maintenance;

use App\Models\MaintenanceRequest;

class MaintenanceRequestWorkflowTest extends MaintenanceTestCase
{
    public function test_requested_can_be_approved_and_sets_approved_at(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => MaintenanceRequest::STATUS_APPROVED,
        ])->assertOk()
            ->assertJsonPath('data.status', MaintenanceRequest::STATUS_APPROVED)
            ->assertJsonPath('data.approved_at', fn ($value) => $value !== null);
    }

    public function test_approved_moves_to_in_progress(): void
    {
        $request = $this->approvedRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => MaintenanceRequest::STATUS_IN_PROGRESS,
        ])->assertOk()
            ->assertJsonPath('data.status', MaintenanceRequest::STATUS_IN_PROGRESS);
    }

    public function test_in_progress_moves_to_completed_and_sets_completed_at(): void
    {
        $request = $this->inProgressRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => MaintenanceRequest::STATUS_COMPLETED,
        ])->assertOk()
            ->assertJsonPath('data.status', MaintenanceRequest::STATUS_COMPLETED)
            ->assertJsonPath('data.completed_at', fn ($value) => $value !== null)
            ->assertJsonPath('data.approved_at', fn ($value) => $value !== null);
    }

    public function test_requested_can_be_cancelled(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => MaintenanceRequest::STATUS_CANCELLED,
        ])->assertOk()
            ->assertJsonPath('data.status', MaintenanceRequest::STATUS_CANCELLED);
    }

    public function test_approved_can_be_cancelled(): void
    {
        $request = $this->approvedRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => MaintenanceRequest::STATUS_CANCELLED,
        ])->assertOk()
            ->assertJsonPath('data.status', MaintenanceRequest::STATUS_CANCELLED)
            ->assertJsonPath('data.approved_at', fn ($value) => $value !== null);
    }

    public function test_skipping_approval_is_rejected(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => MaintenanceRequest::STATUS_IN_PROGRESS,
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid maintenance status transition from REQUESTED to IN_PROGRESS');
    }

    public function test_completed_and_cancelled_are_terminal(): void
    {
        $completed = $this->inProgressRequest();
        $completed->update(['status' => MaintenanceRequest::STATUS_COMPLETED]);
        $cancelled = $this->makeRequest();
        $cancelled->update(['status' => MaintenanceRequest::STATUS_CANCELLED]);

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$completed->id}", [
            'status' => MaintenanceRequest::STATUS_APPROVED,
        ])->assertStatus(422);

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$cancelled->id}", [
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ])->assertStatus(422);
    }

    public function test_same_status_is_a_noop(): void
    {
        $request = $this->approvedRequest();

        $response = $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => MaintenanceRequest::STATUS_APPROVED,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', MaintenanceRequest::STATUS_APPROVED);

        $request->refresh();

        $this->assertSame(MaintenanceRequest::STATUS_APPROVED, $request->status);
    }

    public function test_failed_transition_rolls_back_all_changes(): void
    {
        $request = $this->makeRequest();
        $originalTitle = $request->title;

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => MaintenanceRequest::STATUS_COMPLETED,
            'title' => 'Should not persist',
        ])->assertStatus(422);

        $request->refresh();

        $this->assertSame(MaintenanceRequest::STATUS_REQUESTED, $request->status);
        $this->assertNull($request->completed_at);
        $this->assertSame($originalTitle, $request->title);
    }

    public function test_invalid_status_value_is_rejected_by_validation(): void
    {
        $request = $this->makeRequest();

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-requests/{$request->id}", [
            'status' => 'FIXED',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }
}
