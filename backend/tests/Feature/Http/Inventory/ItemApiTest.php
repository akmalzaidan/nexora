<?php

namespace Tests\Feature\Http\Inventory;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ItemCategory $category;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $this->category = ItemCategory::factory()->create(['code' => 'TESTCAT']);
        $this->warehouse = Warehouse::factory()->create(['code' => 'TESTWH']);
    }

    public function test_authenticated_user_can_list_items(): void
    {
        Item::factory()->count(3)->create(['item_category_id' => $this->category->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/items');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => [
                            'id', 'sku', 'name', 'description', 'unit',
                            'minimum_stock', 'maximum_stock', 'is_active', 'category',
                            'stock', 'created_at', 'updated_at',
                        ],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_list_items(): void
    {
        $response = $this->getJson('/api/v1/items');

        $response->assertUnauthorized();
    }

    public function test_list_includes_total_stock_without_n_plus_1(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'STOCK-1']);
        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 12,
        ]);
        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_OUT,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/items');

        $response->assertOk()
            ->assertJsonPath('data.items.0.stock.total', 10);
    }

    public function test_list_does_not_include_warehouse_breakdown_for_pagination_rows(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'NOBRK-1']);
        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 5,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/items');

        $response->assertOk()
            ->assertJsonPath('data.items.0.stock.total', 5)
            ->assertJsonMissingPath('data.items.0.stock.warehouses');
    }

    public function test_soft_deleted_items_are_not_listed(): void
    {
        Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'TRASHED', 'name' => 'Gone'])->delete();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/items?search=Gone');

        $response->assertOk()
            ->assertJsonCount(0, 'data.items');
    }

    public function test_search_by_sku_and_name(): void
    {
        Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'SKU-UNIQUE', 'name' => 'Widget']);
        Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'SKU-OTHER', 'name' => 'Gadget']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/items?search=SKU-UNIQUE');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.sku', 'SKU-UNIQUE');
    }

    public function test_filter_by_item_category(): void
    {
        $cat2 = ItemCategory::factory()->create(['code' => 'CAT2']);
        Item::factory()->create(['item_category_id' => $this->category->id, 'name' => 'A']);
        Item::factory()->create(['item_category_id' => $cat2->id, 'name' => 'B']);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/items?item_category_id={$this->category->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'A');
    }

    public function test_filter_by_warehouse(): void
    {
        $itemA = Item::factory()->create(['item_category_id' => $this->category->id, 'name' => 'In WH']);
        $itemB = Item::factory()->create(['item_category_id' => $this->category->id, 'name' => 'Not In WH']);
        StockMovement::factory()->create([
            'item_id' => $itemA->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/items?warehouse_id={$this->warehouse->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'In WH');
    }

    public function test_sort_rejects_invalid_columns(): void
    {
        Item::factory()->create(['item_category_id' => $this->category->id, 'name' => 'Zulu', 'sku' => 'ZZZ']);
        Item::factory()->create(['item_category_id' => $this->category->id, 'name' => 'Alpha', 'sku' => 'AAA']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/items?sort=invalid');

        $response->assertOk()
            ->assertJsonPath('data.items.0.name', 'Alpha');
    }

    public function test_admin_can_create_item(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/items', [
            'item_category_id' => $this->category->id,
            'sku' => 'NEW-ITEM-001',
            'name' => 'New Item',
            'unit' => 'unit',
            'minimum_stock' => 5,
            'maximum_stock' => 50,
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sku', 'NEW-ITEM-001')
            ->assertJsonPath('data.category.id', $this->category->id)
            ->assertJsonPath('data.stock.total', 0);

        $this->assertDatabaseHas('items', ['sku' => 'NEW-ITEM-001']);
    }

    public function test_create_with_duplicate_sku_fails(): void
    {
        Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'DUP-SKU']);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/items', [
            'item_category_id' => $this->category->id,
            'sku' => 'DUP-SKU',
            'name' => 'Duplicate',
            'unit' => 'unit',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    public function test_create_with_invalid_category_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/items', [
            'item_category_id' => 99999,
            'sku' => 'BAD-CAT',
            'name' => 'Bad',
            'unit' => 'unit',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['item_category_id']);
    }

    public function test_create_missing_required_fields_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/items', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['item_category_id', 'sku', 'name', 'unit']);
    }

    public function test_create_normalizes_boolean_defaults(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/items', [
            'item_category_id' => $this->category->id,
            'sku' => 'BOOLEAN-1',
            'name' => 'Boolean Item',
            'unit' => 'unit',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_can_get_item_detail_with_warehouse_breakdown(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'DETAIL-1']);
        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 7,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/items/{$item->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.stock.total', 7)
            ->assertJsonPath('data.stock.warehouses.0.warehouse.id', $this->warehouse->id)
            ->assertJsonPath('data.stock.warehouses.0.quantity', 7);
    }

    public function test_detail_omits_zero_balance_warehouses(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'NODETAIL-1']);
        $empty = Warehouse::factory()->create(['code' => 'EMPTYWH']);

        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $empty->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 4,
        ]);
        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $empty->id,
            'type' => StockMovement::TYPE_STOCK_OUT,
            'quantity' => 4,
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/items/{$item->id}");

        $response->assertOk()
            ->assertJsonPath('data.stock.total', 0)
            ->assertJsonCount(0, 'data.stock.warehouses');
    }

    public function test_missing_item_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/items/99999');

        $response->assertNotFound();
    }

    public function test_soft_deleted_item_detail_returns_404(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'GONE-1']);
        $item->delete();

        $response = $this->actingAs($this->admin)->getJson("/api/v1/items/{$item->id}");

        $response->assertNotFound();
    }

    public function test_admin_can_update_item(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'name' => 'Old']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/items/{$item->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('items', ['id' => $item->id, 'name' => 'New Name']);
    }

    public function test_update_same_sku_allowed(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'SAME-SKU']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/items/{$item->id}", [
            'sku' => 'SAME-SKU',
            'name' => 'Renamed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.sku', 'SAME-SKU');
    }

    public function test_admin_can_soft_delete_unused_item(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'DELETE-1']);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/items/{$item->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('items', ['id' => $item->id]);
    }

    public function test_cannot_delete_item_with_stock_movements(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'MOVED-1']);
        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/items/{$item->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Item cannot be deleted because it has stock movements');

        $this->assertNotSoftDeleted('items', ['id' => $item->id]);
    }

    public function test_cannot_delete_item_used_in_maintenance(): void
    {
        $item = Item::factory()->create(['item_category_id' => $this->category->id, 'sku' => 'MAINT-1']);

        $asset = Asset::factory()->create(['asset_category_id' => AssetCategory::factory()->create()->id]);
        $maintenanceRequest = MaintenanceRequest::create([
            'asset_id' => $asset->id,
            'requested_by' => $this->admin->id,
            'title' => 'Repair',
            'description' => 'Fix it',
            'requested_at' => now()->toISOString(),
        ]);
        $maintenanceRecord = MaintenanceRecord::create([
            'maintenance_request_id' => $maintenanceRequest->id,
            'asset_id' => $asset->id,
            'description' => 'Repair log',
        ]);
        MaintenancePart::create([
            'maintenance_record_id' => $maintenanceRecord->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/items/{$item->id}");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Item cannot be deleted because it is used in maintenance records');

        $this->assertNotSoftDeleted('items', ['id' => $item->id]);
    }

    public function test_warehouse_staff_can_create_item(): void
    {
        $warehouseStaff = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);

        $response = $this->actingAs($warehouseStaff)->postJson('/api/v1/items', [
            'item_category_id' => $this->category->id,
            'sku' => 'WH-ITEM-1',
            'name' => 'WH Item',
            'unit' => 'unit',
        ]);

        $response->assertCreated();
    }

    public function test_staff_gets_403_on_item_create(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->postJson('/api/v1/items', [
            'item_category_id' => $this->category->id,
            'sku' => 'STAFF-ITEM',
            'name' => 'Nope',
            'unit' => 'unit',
        ]);

        $response->assertForbidden();
    }

    public function test_staff_can_view_items(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->getJson('/api/v1/items');

        $response->assertOk();
    }

    public function test_super_admin_has_full_access(): void
    {
        $super = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);

        $response = $this->actingAs($super)->getJson('/api/v1/items');

        $response->assertOk();
    }
}
