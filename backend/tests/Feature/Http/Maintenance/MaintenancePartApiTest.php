<?php

namespace Tests\Feature\Http\Maintenance;

use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\StockMovement;
use App\Models\Warehouse;

class MaintenancePartApiTest extends MaintenanceTestCase
{
    public function test_technician_can_list_parts_for_a_record(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        $part = MaintenancePart::factory()->create(['maintenance_record_id' => $record->id, 'item_id' => $this->item()->id]);

        $this->actingAs($this->technician)->getJson("/api/v1/maintenance-records/{$record->id}/parts")
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [['id', 'quantity', 'item', 'created_at']],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ])
            ->assertJsonPath('data.items.0.id', $part->id)
            ->assertJsonPath('data.items.0.item.unit', fn ($value) => is_string($value));
    }

    public function test_staff_sees_only_parts_of_own_requests(): void
    {
        $own = $this->inProgressRequest();
        $otherRequest = MaintenanceRequest::factory()->create([
            'requested_by' => $this->manager->id,
            'asset_id' => $this->asset()->id,
            'status' => MaintenanceRequest::STATUS_IN_PROGRESS,
        ]);
        $otherRecord = MaintenanceRecord::factory()->create([
            'maintenance_request_id' => $otherRequest->id,
            'asset_id' => $otherRequest->asset_id,
            'technician_id' => $this->technician->id,
        ]);
        MaintenancePart::factory()->create(['maintenance_record_id' => $otherRecord->id, 'item_id' => $this->item()->id]);

        $this->actingAs($this->staff)->getJson("/api/v1/maintenance-records/{$otherRecord->id}/parts")
            ->assertForbidden();

        $ownRecord = $own->records()->first();
        MaintenancePart::factory()->create(['maintenance_record_id' => $ownRecord->id, 'item_id' => $this->item()->id]);

        $this->actingAs($this->staff)->getJson("/api/v1/maintenance-records/{$ownRecord->id}/parts")
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_technician_can_create_a_part_on_in_progress_work(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        $item = $this->item();

        $this->actingAs($this->technician)->postJson("/api/v1/maintenance-records/{$record->id}/parts", [
            'item_id' => $item->id,
            'quantity' => 3,
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.quantity', 3)
            ->assertJsonPath('data.item.id', $item->id);
    }

    public function test_part_creation_does_not_consume_inventory_stock(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        $item = $this->item();
        $warehouse = Warehouse::factory()->create();
        StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 10,
            'performed_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        $this->actingAs($this->technician)->postJson("/api/v1/maintenance-records/{$record->id}/parts", [
            'item_id' => $item->id,
            'quantity' => 2,
        ])->assertCreated();

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 10,
        ]);
    }

    public function test_quantity_must_be_a_positive_integer(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        $item = $this->item();

        foreach ([0, -1] as $quantity) {
            $this->actingAs($this->technician)->postJson("/api/v1/maintenance-records/{$record->id}/parts", [
                'item_id' => $item->id,
                'quantity' => $quantity,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['quantity']);
        }

        $this->actingAs($this->technician)->postJson("/api/v1/maintenance-records/{$record->id}/parts", [
            'item_id' => $item->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_soft_deleted_item_is_rejected(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        $item = $this->item();
        $item->delete();

        $this->actingAs($this->technician)->postJson("/api/v1/maintenance-records/{$record->id}/parts", [
            'item_id' => $item->id,
            'quantity' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['item_id']);
    }

    public function test_inactive_item_is_rejected(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        $item = $this->item(['is_active' => false]);

        $this->actingAs($this->technician)->postJson("/api/v1/maintenance-records/{$record->id}/parts", [
            'item_id' => $item->id,
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Cannot use an inactive inventory item for maintenance parts.');
    }

    public function test_parts_cannot_be_added_after_completion(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        $request->update(['status' => MaintenanceRequest::STATUS_COMPLETED]);
        $item = $this->item();

        $this->actingAs($this->technician)->postJson("/api/v1/maintenance-records/{$record->id}/parts", [
            'item_id' => $item->id,
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Parts cannot be added once the work is complete or cancelled.');
    }

    public function test_missing_record_returns_404(): void
    {
        $this->actingAs($this->technician)->postJson('/api/v1/maintenance-records/99999/parts', [
            'item_id' => $this->item()->id,
            'quantity' => 1,
        ])->assertNotFound();
    }

    public function test_staff_cannot_create_parts(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();

        $this->actingAs($this->staff)->postJson("/api/v1/maintenance-records/{$record->id}/parts", [
            'item_id' => $this->item()->id,
            'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_parts_have_no_update_or_delete_endpoints(): void
    {
        $request = $this->inProgressRequest();
        $record = $request->records()->first();
        $part = MaintenancePart::factory()->create(['maintenance_record_id' => $record->id, 'item_id' => $this->item()->id]);

        $this->actingAs($this->technician)->putJson("/api/v1/maintenance-records/{$record->id}/parts/{$part->id}", [
            'quantity' => 99,
        ])->assertNotFound();

        $this->actingAs($this->technician)->deleteJson("/api/v1/maintenance-records/{$record->id}/parts/{$part->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('maintenance_parts', ['id' => $part->id]);
    }
}
