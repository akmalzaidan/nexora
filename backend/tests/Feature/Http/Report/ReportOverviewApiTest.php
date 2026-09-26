<?php

namespace Tests\Feature\Http\Report;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MaintenanceRequest;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\Warehouse;

class ReportOverviewApiTest extends ReportTestCase
{
    public function test_overview_returns_a_section_per_operational_module(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/overview');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Operational overview')
            ->assertJsonStructure([
                'data' => [
                    'generated_at',
                    'assets' => ['total', 'by_status' => ['*' => ['status', 'count']]],
                    'inventory' => ['item_count', 'warehouse_count', 'stock_quantity'],
                    'tickets' => ['total', 'by_status' => ['*' => ['status', 'count']]],
                    'maintenance' => ['total', 'by_status' => ['*' => ['status', 'count']]],
                ],
            ]);
    }

    public function test_overview_is_a_valid_empty_report_without_business_data(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/overview');

        $response->assertOk()
            ->assertJsonPath('data.assets.total', 0)
            ->assertJsonPath('data.assets.by_status', [])
            ->assertJsonPath('data.inventory.item_count', 0)
            ->assertJsonPath('data.inventory.warehouse_count', 0)
            ->assertJsonPath('data.inventory.stock_quantity', 0)
            ->assertJsonPath('data.tickets.total', 0)
            ->assertJsonPath('data.maintenance.total', 0);
    }

    public function test_overview_summarizes_each_module_from_its_own_source_table(): void
    {
        $category = AssetCategory::factory()->create();
        $asset = Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);
        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);
        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'DRAFT']);

        $item = Item::factory()->create(['item_category_id' => ItemCategory::factory()->create()->id]);
        $warehouse = Warehouse::factory()->create();
        StockMovement::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 30,
            'performed_by' => $this->admin->id,
        ]);

        Ticket::factory()->count(3)->create(['requester_id' => $this->staff->id, 'status' => Ticket::STATUS_OPEN]);
        Ticket::factory()->create(['requester_id' => $this->staff->id, 'status' => Ticket::STATUS_CLOSED]);

        // The maintenance factory would create an asset per request, so the
        // asset under maintenance is pinned to keep the asset total exact.
        MaintenanceRequest::factory()->count(4)->create([
            'asset_id' => $asset,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/overview');

        $response->assertOk()
            ->assertJsonPath('data.assets.total', 3)
            ->assertJsonPath('data.assets.by_status', [
                ['status' => 'ACTIVE', 'count' => 2],
                ['status' => 'DRAFT', 'count' => 1],
            ])
            ->assertJsonPath('data.inventory.item_count', 1)
            ->assertJsonPath('data.inventory.warehouse_count', 1)
            ->assertJsonPath('data.inventory.stock_quantity', 30)
            ->assertJsonPath('data.tickets.total', 4)
            ->assertJsonPath('data.tickets.by_status', [
                ['status' => 'CLOSED', 'count' => 1],
                ['status' => 'OPEN', 'count' => 3],
            ])
            ->assertJsonPath('data.maintenance.total', 4)
            ->assertJsonPath('data.maintenance.by_status', [
                ['status' => 'REQUESTED', 'count' => 4],
            ]);
    }

    public function test_overview_is_a_snapshot_and_ignores_period_parameters(): void
    {
        $category = AssetCategory::factory()->create();
        Asset::factory()->create([
            'asset_category_id' => $category->id,
            'status' => 'ACTIVE',
            'created_at' => now()->subYear(),
        ]);

        $without = $this->actingAs($this->admin)->getJson('/api/v1/reports/overview');
        $with = $this->actingAs($this->admin)->getJson('/api/v1/reports/overview?from=2020-01-01&to=2020-01-31');

        $with->assertOk();

        $this->assertSame(
            $without->json('data.assets'),
            $with->json('data.assets'),
            'The overview must stay a current-state snapshot when a period is supplied.'
        );
    }

    public function test_overview_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/overview')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    /**
     * `view_reports` is seeded to super_admin, admin and manager only, so no
     * report may leak aggregate data to the scoped operational roles.
     */
    public function test_overview_denies_roles_without_view_reports(): void
    {
        foreach ([$this->staff, $this->technician, $this->warehouseStaff] as $user) {
            $this->actingAs($user)
                ->getJson('/api/v1/reports/overview')
                ->assertForbidden()
                ->assertJsonPath('success', false);
        }
    }

    public function test_overview_allows_every_role_seeded_with_view_reports(): void
    {
        foreach ([$this->superAdmin, $this->admin, $this->manager] as $user) {
            $this->actingAs($user)
                ->getJson('/api/v1/reports/overview')
                ->assertOk();
        }
    }
}
