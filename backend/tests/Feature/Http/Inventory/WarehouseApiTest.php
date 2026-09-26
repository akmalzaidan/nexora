<?php

namespace Tests\Feature\Http\Inventory;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $this->location = Location::factory()->create(['code' => 'TESTLOC']);
    }

    public function test_authenticated_user_can_list_warehouses(): void
    {
        Warehouse::factory()->count(3)->create(['location_id' => $this->location->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/warehouses');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => ['id', 'name', 'code', 'description', 'is_active', 'location', 'created_at', 'updated_at'],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_list_warehouses(): void
    {
        $response = $this->getJson('/api/v1/warehouses');

        $response->assertUnauthorized();
    }

    public function test_eager_loads_location_in_list(): void
    {
        Warehouse::factory()->create([
            'code' => 'WH-LOC',
            'location_id' => $this->location->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/warehouses');

        $response->assertOk()
            ->assertJsonPath('data.items.0.location.id', $this->location->id)
            ->assertJsonPath('data.items.0.location.code', 'TESTLOC');
    }

    public function test_search_by_name_or_code(): void
    {
        Warehouse::factory()->create(['code' => 'MAIN', 'name' => 'Main Depot']);
        Warehouse::factory()->create(['code' => 'SUPP', 'name' => 'Supply Store']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/warehouses?search=MAIN');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'MAIN');
    }

    public function test_filter_by_location(): void
    {
        $loc2 = Location::factory()->create(['code' => 'LOC2']);
        Warehouse::factory()->create(['code' => 'WH-A', 'location_id' => $this->location->id]);
        Warehouse::factory()->create(['code' => 'WH-B', 'location_id' => $loc2->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/warehouses?location_id={$this->location->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'WH-A');
    }

    public function test_filter_by_is_active(): void
    {
        Warehouse::factory()->create(['code' => 'WH-ON', 'is_active' => true]);
        Warehouse::factory()->create(['code' => 'WH-OFF', 'is_active' => false]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/warehouses?is_active=false');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'WH-OFF');
    }

    public function test_sort_rejects_invalid_columns(): void
    {
        Warehouse::factory()->create(['code' => 'WH-Z', 'name' => 'Zulu']);
        Warehouse::factory()->create(['code' => 'WH-A', 'name' => 'Alpha']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/warehouses?sort=invalid');

        $response->assertOk()
            ->assertJsonPath('data.items.0.name', 'Alpha');
    }

    public function test_admin_can_create_warehouse(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/warehouses', [
            'name' => 'New Warehouse',
            'code' => 'NEWWH',
            'location_id' => $this->location->id,
            'description' => 'Fresh storage',
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'NEWWH')
            ->assertJsonPath('data.location.id', $this->location->id);

        $this->assertDatabaseHas('warehouses', ['code' => 'NEWWH']);
    }

    public function test_create_with_duplicate_code_fails(): void
    {
        Warehouse::factory()->create(['code' => 'DUP']);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/warehouses', [
            'name' => 'Duplicate',
            'code' => 'DUP',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_create_with_invalid_location_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/warehouses', [
            'name' => 'Bad Loc',
            'code' => 'BADLOC',
            'location_id' => 99999,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id']);
    }

    public function test_create_missing_required_fields_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/warehouses', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code']);
    }

    public function test_can_get_warehouse_detail(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => 'DETAILWH', 'location_id' => $this->location->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $warehouse->id)
            ->assertJsonPath('data.code', 'DETAILWH')
            ->assertJsonPath('data.location.id', $this->location->id);
    }

    public function test_missing_warehouse_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/warehouses/99999');

        $response->assertNotFound();
    }

    public function test_admin_can_update_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/warehouses/{$warehouse->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'name' => 'New Name']);
    }

    public function test_admin_can_delete_unused_warehouse(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => 'DELETABLE']);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('warehouses', ['id' => $warehouse->id]);
    }

    public function test_cannot_delete_warehouse_with_stock_movements(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => 'BUSY']);
        $item = Item::factory()->create(['item_category_id' => ItemCategory::factory()->create()->id]);
        StockMovement::factory()->create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Warehouse cannot be deleted because it has stock movements');

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
    }

    public function test_warehouse_staff_can_create_warehouse(): void
    {
        $warehouseStaff = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);

        $response = $this->actingAs($warehouseStaff)->postJson('/api/v1/warehouses', [
            'name' => 'Staff WH',
            'code' => 'STAFFWH',
        ]);

        $response->assertCreated();
    }

    public function test_staff_gets_403_on_warehouse_create(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->postJson('/api/v1/warehouses', [
            'name' => 'Nope',
            'code' => 'NOPE',
        ]);

        $response->assertForbidden();
    }

    public function test_staff_can_view_warehouses(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->getJson('/api/v1/warehouses');

        $response->assertOk();
    }

    public function test_super_admin_has_full_access(): void
    {
        $super = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);

        $response = $this->actingAs($super)->getJson('/api/v1/warehouses');

        $response->assertOk();
    }
}
