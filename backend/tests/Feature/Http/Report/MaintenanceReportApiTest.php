<?php

namespace Tests\Feature\Http\Report;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Item;
use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;

class MaintenanceReportApiTest extends ReportTestCase
{
    public function test_maintenance_report_groups_requests_by_status_and_priority(): void
    {
        $asset = $this->asset();

        $this->request($asset, ['status' => 'REQUESTED', 'priority' => 'LOW']);
        $this->request($asset, ['status' => 'IN_PROGRESS', 'priority' => 'HIGH']);
        $this->request($asset, ['status' => 'IN_PROGRESS', 'priority' => 'HIGH']);
        $this->request($asset, ['status' => 'COMPLETED', 'priority' => 'MEDIUM']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/maintenance');

        $response->assertOk()
            ->assertJsonPath('message', 'Maintenance report')
            ->assertJsonPath('data.requests.total', 4)
            ->assertJsonPath('data.requests.by_status', [
                ['status' => 'COMPLETED', 'count' => 1],
                ['status' => 'IN_PROGRESS', 'count' => 2],
                ['status' => 'REQUESTED', 'count' => 1],
            ])
            ->assertJsonPath('data.requests.by_priority', [
                ['priority' => 'HIGH', 'count' => 2],
                ['priority' => 'LOW', 'count' => 1],
                ['priority' => 'MEDIUM', 'count' => 1],
            ])
            ->assertJsonPath('data.period', null);
    }

    public function test_maintenance_report_averages_costs_over_records_that_have_one(): void
    {
        $request = $this->request($this->asset());

        $this->record($request, ['cost' => '100.00']);
        $this->record($request, ['cost' => '250.50']);
        $this->record($request, ['cost' => null]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/maintenance');

        $response->assertOk()
            ->assertJsonPath('data.records.count', 3)
            ->assertJsonPath('data.records.costed_count', 2)
            ->assertJsonPath('data.records.total_cost', '350.50')
            ->assertJsonPath('data.records.average_cost', '175.25');
    }

    public function test_maintenance_report_leaves_the_average_cost_null_without_costs(): void
    {
        $request = $this->request($this->asset());
        $this->record($request, ['cost' => null]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/maintenance');

        $response->assertOk()
            ->assertJsonPath('data.records.count', 1)
            ->assertJsonPath('data.records.costed_count', 0)
            ->assertJsonPath('data.records.total_cost', '0.00')
            ->assertJsonPath('data.records.average_cost', null);
    }

    public function test_maintenance_report_ranks_parts_by_quantity_and_honours_the_limit(): void
    {
        $request = $this->request($this->asset());
        $record = $this->record($request);

        $frequent = Item::factory()->create(['sku' => 'PART-A', 'name' => 'Bearing']);
        $occasional = Item::factory()->create(['sku' => 'PART-B', 'name' => 'Fuse']);

        MaintenancePart::factory()->create(['maintenance_record_id' => $record->id, 'item_id' => $frequent->id, 'quantity' => 4]);
        MaintenancePart::factory()->create(['maintenance_record_id' => $record->id, 'item_id' => $frequent->id, 'quantity' => 6]);
        MaintenancePart::factory()->create(['maintenance_record_id' => $record->id, 'item_id' => $occasional->id, 'quantity' => 1]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/maintenance?limit=1');

        $response->assertOk()
            ->assertJsonPath('data.parts.usage_count', 3)
            ->assertJsonPath('data.parts.top_items.limit', 1)
            ->assertJsonPath('data.parts.top_items.items', [
                [
                    'item_id' => $frequent->id,
                    'sku' => 'PART-A',
                    'label' => 'Bearing',
                    'quantity' => 10,
                    'usage_count' => 2,
                ],
            ]);
    }

    public function test_maintenance_report_ranks_assets_by_request_count_and_honours_the_limit(): void
    {
        $frequent = $this->asset(['asset_code' => 'AST-002']);
        $occasional = $this->asset(['asset_code' => 'AST-001']);

        $this->request($frequent);
        $this->request($frequent);
        $this->request($occasional);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/maintenance?limit=1');

        $response->assertOk()
            ->assertJsonPath('data.by_asset.limit', 1)
            ->assertJsonPath('data.by_asset.items', [
                [
                    'asset_id' => $frequent->id,
                    'asset_code' => 'AST-002',
                    'asset_name' => $frequent->name,
                    'count' => 2,
                ],
            ]);
    }

    public function test_maintenance_report_separates_period_lifecycle_counts_from_current_state(): void
    {
        $asset = $this->asset();

        $this->request($asset, [
            'requested_at' => now()->subDays(4),
            'approved_at' => now()->subDays(3),
            'status' => 'IN_PROGRESS',
        ]);
        $this->request($asset, [
            'requested_at' => now()->subDays(2),
            'approved_at' => now()->subDays(1),
            'completed_at' => now()->subDay(),
            'status' => 'COMPLETED',
        ]);
        $this->request($asset, ['requested_at' => now()->subMonths(3)]);

        $from = now()->subDays(5)->toDateString();
        $to = now()->toDateString();

        $response = $this->actingAs($this->admin)->getJson("/api/v1/reports/maintenance?from={$from}&to={$to}");

        $response->assertOk()
            ->assertJsonPath('data.requests.total', 3)
            ->assertJsonPath('data.period.from', $from)
            ->assertJsonPath('data.period.to', $to)
            ->assertJsonPath('data.period.requested', 2)
            ->assertJsonPath('data.period.approved', 2)
            ->assertJsonPath('data.period.completed', 1);
    }

    public function test_empty_maintenance_report_returns_zeroes_instead_of_an_error(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/maintenance');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.requests.total', 0)
            ->assertJsonPath('data.requests.by_status', [])
            ->assertJsonPath('data.requests.by_priority', [])
            ->assertJsonPath('data.records.count', 0)
            ->assertJsonPath('data.records.total_cost', '0.00')
            ->assertJsonPath('data.records.average_cost', null)
            ->assertJsonPath('data.parts.usage_count', 0)
            ->assertJsonPath('data.parts.top_items.items', [])
            ->assertJsonPath('data.by_asset.items', [])
            ->assertJsonPath('data.period', null);
    }

    public function test_maintenance_report_denies_roles_without_view_reports(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/v1/reports/maintenance')
            ->assertForbidden();
    }

    public function test_maintenance_report_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/maintenance')
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function asset(array $attributes = []): Asset
    {
        return Asset::factory()->create($attributes + [
            'asset_category_id' => AssetCategory::factory()->create()->id,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function request(Asset $asset, array $attributes = []): MaintenanceRequest
    {
        return MaintenanceRequest::factory()->create($attributes + [
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(MaintenanceRequest $request, array $attributes = []): MaintenanceRecord
    {
        return MaintenanceRecord::factory()->create($attributes + [
            'maintenance_request_id' => $request->id,
            'asset_id' => $request->asset_id,
            'technician_id' => $this->technician->id,
        ]);
    }
}
