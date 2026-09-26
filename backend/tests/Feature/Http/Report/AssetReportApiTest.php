<?php

namespace Tests\Feature\Http\Report;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Location;

class AssetReportApiTest extends ReportTestCase
{
    public function test_asset_report_groups_the_current_state_by_status_category_and_location(): void
    {
        $laptops = AssetCategory::factory()->create(['name' => 'Laptop']);
        $monitors = AssetCategory::factory()->create(['name' => 'Monitor']);
        $jakarta = Location::factory()->create(['name' => 'Jakarta HQ']);
        $bandung = Location::factory()->create(['name' => 'Bandung Office']);

        Asset::factory()->create(['asset_category_id' => $laptops->id, 'status' => 'ACTIVE', 'location_id' => $jakarta->id]);
        Asset::factory()->create(['asset_category_id' => $laptops->id, 'status' => 'ACTIVE', 'location_id' => $jakarta->id]);
        Asset::factory()->create(['asset_category_id' => $laptops->id, 'status' => 'MAINTENANCE', 'location_id' => null]);
        Asset::factory()->create(['asset_category_id' => $monitors->id, 'status' => 'RETIRED', 'location_id' => $bandung->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/assets');

        $response->assertOk()
            ->assertJsonPath('message', 'Asset report')
            ->assertJsonPath('data.current.total', 4)
            ->assertJsonPath('data.current.by_status', [
                ['status' => 'ACTIVE', 'count' => 2],
                ['status' => 'MAINTENANCE', 'count' => 1],
                ['status' => 'RETIRED', 'count' => 1],
            ])
            ->assertJsonPath('data.current.by_category', [
                ['category_id' => $laptops->id, 'label' => 'Laptop', 'count' => 3],
                ['category_id' => $monitors->id, 'label' => 'Monitor', 'count' => 1],
            ])
            ->assertJsonPath('data.current.by_location', [
                ['location_id' => $jakarta->id, 'label' => 'Jakarta HQ', 'count' => 2],
                ['location_id' => $bandung->id, 'label' => 'Bandung Office', 'count' => 1],
            ])
            ->assertJsonPath('data.current.without_location_count', 1)
            ->assertJsonPath('data.period', null);
    }

    public function test_asset_report_counts_held_and_unheld_assets(): void
    {
        $category = AssetCategory::factory()->create();

        Asset::factory()->create([
            'asset_category_id' => $category->id,
            'status' => 'ACTIVE',
            'current_user_id' => $this->staff->id,
        ]);
        Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/assets');

        $response->assertOk()
            ->assertJsonPath('data.current.total', 2)
            ->assertJsonPath('data.current.assigned_count', 1)
            ->assertJsonPath('data.current.unassigned_count', 1);
    }

    public function test_asset_report_groups_assignment_records_by_status(): void
    {
        $category = AssetCategory::factory()->create();
        $active = Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);
        $returned = Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);

        AssetAssignment::factory()->create(['asset_id' => $active->id, 'status' => 'ACTIVE']);
        AssetAssignment::factory()->create([
            'asset_id' => $returned->id,
            'status' => 'RETURNED',
            'returned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/assets');

        $response->assertOk()
            ->assertJsonPath('data.assignments.total', 2)
            ->assertJsonPath('data.assignments.by_status', [
                ['status' => 'ACTIVE', 'count' => 1],
                ['status' => 'RETURNED', 'count' => 1],
            ]);
    }

    public function test_asset_report_separates_period_activity_from_current_state(): void
    {
        $category = AssetCategory::factory()->create();
        $asset = Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);

        Asset::factory()->create([
            'asset_category_id' => $category->id,
            'status' => 'ACTIVE',
            'created_at' => now()->subMonths(6),
        ]);
        AssetAssignment::factory()->create(['asset_id' => $asset->id, 'assigned_at' => now()->subDays(3)]);
        AssetAssignment::factory()->create([
            'asset_id' => $asset->id,
            'status' => 'RETURNED',
            'assigned_at' => now()->subDays(3),
            'returned_at' => now()->subDays(1),
        ]);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();

        $response = $this->actingAs($this->admin)->getJson("/api/v1/reports/assets?from={$from}&to={$to}");

        $response->assertOk()
            ->assertJsonPath('data.current.total', 2)
            ->assertJsonPath('data.period.from', $from)
            ->assertJsonPath('data.period.to', $to)
            ->assertJsonPath('data.period.assets_created', 1)
            ->assertJsonPath('data.period.assignments_assigned', 2)
            ->assertJsonPath('data.period.assignments_returned', 1);
    }

    public function test_asset_report_excludes_soft_deleted_assets(): void
    {
        $category = AssetCategory::factory()->create();
        $asset = Asset::factory()->create(['asset_category_id' => $category->id, 'status' => 'ACTIVE']);
        $asset->delete();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/assets');

        $response->assertOk()
            ->assertJsonPath('data.current.total', 0)
            ->assertJsonPath('data.current.by_status', [])
            ->assertJsonPath('data.current.by_category', []);
    }

    public function test_empty_asset_report_returns_zeroes_instead_of_an_error(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/assets');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.current.total', 0)
            ->assertJsonPath('data.current.by_location', [])
            ->assertJsonPath('data.current.assigned_count', 0)
            ->assertJsonPath('data.current.unassigned_count', 0)
            ->assertJsonPath('data.current.without_location_count', 0)
            ->assertJsonPath('data.assignments.total', 0)
            ->assertJsonPath('data.period', null);
    }

    public function test_asset_report_denies_roles_without_view_reports(): void
    {
        $this->actingAs($this->staff)
            ->getJson('/api/v1/reports/assets')
            ->assertForbidden();
    }

    public function test_asset_report_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/assets')
            ->assertUnauthorized();
    }
}
