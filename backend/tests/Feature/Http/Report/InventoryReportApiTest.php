<?php

namespace Tests\Feature\Http\Report;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockMovement;
use App\Models\Warehouse;

class InventoryReportApiTest extends ReportTestCase
{
    public function test_inventory_report_counts_the_catalog_and_the_stored_quantity(): void
    {
        $peripherals = ItemCategory::factory()->create();
        $spare = ItemCategory::factory()->create();
        $keyboard = Item::factory()->create(['item_category_id' => $peripherals->id]);
        Item::factory()->create(['item_category_id' => $peripherals->id]);
        Item::factory()->create(['item_category_id' => $spare->id]);
        $main = Warehouse::factory()->create();
        $annex = Warehouse::factory()->create();

        $this->stockIn($keyboard, $main, 40);
        $this->stockIn($keyboard, $annex, 5);
        $this->stockOut($keyboard, $main, 12);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/inventory');

        $response->assertOk()
            ->assertJsonPath('message', 'Inventory report')
            ->assertJsonPath('data.items.count', 3)
            ->assertJsonPath('data.items.category_count', 2)
            ->assertJsonPath('data.warehouses.count', 2)
            ->assertJsonPath('data.current_stock.total_quantity', 33)
            ->assertJsonPath('data.period', null);
    }

    public function test_inventory_report_breaks_the_current_balance_down_by_warehouse(): void
    {
        $item = Item::factory()->create();
        $main = Warehouse::factory()->create(['name' => 'Main Warehouse']);
        $annex = Warehouse::factory()->create(['name' => 'Annex Warehouse']);
        $empty = Warehouse::factory()->create(['name' => 'Empty Warehouse']);

        $this->stockIn($item, $main, 20);
        $this->stockIn($item, $annex, 7);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/inventory');

        $response->assertOk()
            ->assertJsonPath('data.current_stock.by_warehouse', [
                ['warehouse_id' => $main->id, 'label' => 'Main Warehouse', 'quantity' => 20],
                ['warehouse_id' => $annex->id, 'label' => 'Annex Warehouse', 'quantity' => 7],
            ])
            ->assertJsonPath('data.warehouses.count', 3);

        $this->assertNotContains($empty->id, array_column($response->json('data.current_stock.by_warehouse'), 'warehouse_id'));
    }

    public function test_inventory_report_ranks_items_and_honours_the_limit(): void
    {
        $category = ItemCategory::factory()->create();
        $heavy = Item::factory()->create(['item_category_id' => $category->id, 'sku' => 'SKU-HEAVY']);
        $medium = Item::factory()->create(['item_category_id' => $category->id, 'sku' => 'SKU-MEDIUM']);
        $light = Item::factory()->create(['item_category_id' => $category->id, 'sku' => 'SKU-LIGHT']);
        $warehouse = Warehouse::factory()->create();

        $this->stockIn($heavy, $warehouse, 100);
        $this->stockIn($medium, $warehouse, 50);
        $this->stockIn($light, $warehouse, 5);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/inventory?limit=2');

        $response->assertOk()
            ->assertJsonPath('data.current_stock.by_item.limit', 2)
            ->assertJsonPath('data.current_stock.by_item.items', [
                ['item_id' => $heavy->id, 'quantity' => 100],
                ['item_id' => $medium->id, 'quantity' => 50],
            ]);
    }

    public function test_inventory_report_reports_period_movement_activity_separately_from_current_stock(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();

        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 60,
            'created_at' => now()->subDays(3),
        ]);
        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_OUT,
            'quantity' => 15,
            'created_at' => now()->subDays(1),
        ]);
        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_OUT,
            'quantity' => 999,
            'created_at' => now()->subMonths(2),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/reports/inventory?from={$from}&to={$to}");

        $response->assertOk()
            // Current stock is the whole journal: the out-of-period movement is
            // excluded from the period activity but still part of the balance.
            ->assertJsonPath('data.current_stock.total_quantity', -954)
            ->assertJsonPath('data.period.from', $from)
            ->assertJsonPath('data.period.to', $to)
            ->assertJsonPath('data.period.movement_count', 2)
            ->assertJsonPath('data.period.stock_in_total', 60)
            ->assertJsonPath('data.period.stock_out_total', 15);
    }

    public function test_inventory_report_matches_the_single_stock_balance_rule(): void
    {
        $item = Item::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->stockIn($item, $warehouse, 10);
        $this->stockIn($item, $warehouse, 4);
        $this->stockOut($item, $warehouse, 14);

        $report = $this->actingAs($this->admin)->getJson('/api/v1/reports/inventory');

        $report->assertOk()
            ->assertJsonPath('data.current_stock.total_quantity', 0)
            ->assertJsonPath('data.current_stock.by_warehouse', [])
            ->assertJsonPath('data.current_stock.by_item.items', []);
    }

    public function test_inventory_report_excludes_soft_deleted_items_from_catalog_counts(): void
    {
        $item = Item::factory()->create();
        $item->delete();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/inventory');

        $response->assertOk()
            ->assertJsonPath('data.items.count', 0);
    }

    public function test_empty_inventory_report_returns_zeroes_instead_of_an_error(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/inventory');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.count', 0)
            ->assertJsonPath('data.warehouses.count', 0)
            ->assertJsonPath('data.current_stock.total_quantity', 0)
            ->assertJsonPath('data.current_stock.by_warehouse', [])
            ->assertJsonPath('data.current_stock.by_item.items', [])
            ->assertJsonPath('data.period', null);
    }

    public function test_inventory_report_denies_roles_without_view_reports(): void
    {
        $this->actingAs($this->technician)
            ->getJson('/api/v1/reports/inventory')
            ->assertForbidden();
    }

    public function test_inventory_report_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/inventory')
            ->assertUnauthorized();
    }

    private function stockIn(Item $item, Warehouse $warehouse, int $quantity): StockMovement
    {
        return StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => $quantity,
        ]);
    }

    private function stockOut(Item $item, Warehouse $warehouse, int $quantity): StockMovement
    {
        return StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_OUT,
            'quantity' => $quantity,
        ]);
    }
}
