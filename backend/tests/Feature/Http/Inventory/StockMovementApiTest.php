<?php

namespace Tests\Feature\Http\Inventory;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $warehouseStaff;

    private Warehouse $warehouse;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
        $this->warehouseStaff = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $this->warehouse = Warehouse::factory()->create(['code' => 'MOVE-WH']);
        $this->item = Item::factory()->create(['item_category_id' => ItemCategory::factory()->create()->id, 'sku' => 'MOVE-ITEM']);
    }

    public function test_authenticated_user_can_list_stock_movements(): void
    {
        StockMovement::factory()->count(2)->create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'performed_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/stock-movements');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => [
                            'id', 'type', 'quantity', 'reference_type', 'reference_id',
                            'notes', 'item', 'warehouse', 'performer', 'created_at',
                        ],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_list_stock_movements(): void
    {
        $response = $this->getJson('/api/v1/stock-movements');

        $response->assertUnauthorized();
    }

    public function test_movements_default_to_newest_first(): void
    {
        $older = StockMovement::create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 5,
            'performed_by' => $this->admin->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = StockMovement::create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 6,
            'performed_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/stock-movements');

        $response->assertOk()
            ->assertJsonPath('data.items.0.id', $newer->id)
            ->assertJsonPath('data.items.1.id', $older->id);
    }

    public function test_list_filters_by_item(): void
    {
        $other = Item::factory()->create(['item_category_id' => ItemCategory::factory()->create()->id, 'sku' => 'OTHER-ITEM']);
        StockMovement::factory()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'performed_by' => $this->admin->id]);
        StockMovement::factory()->create(['item_id' => $other->id, 'warehouse_id' => $this->warehouse->id, 'performed_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/stock-movements?item_id={$this->item->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.item.id', $this->item->id);
    }

    public function test_list_filters_by_warehouse(): void
    {
        $otherWh = Warehouse::factory()->create(['code' => 'WH-2']);
        StockMovement::factory()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'performed_by' => $this->admin->id]);
        StockMovement::factory()->create(['item_id' => $this->item->id, 'warehouse_id' => $otherWh->id, 'performed_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/stock-movements?warehouse_id={$this->warehouse->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.warehouse.id', $this->warehouse->id);
    }

    public function test_list_filters_by_type(): void
    {
        StockMovement::factory()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'type' => StockMovement::TYPE_STOCK_IN, 'performed_by' => $this->admin->id]);
        StockMovement::factory()->create(['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'type' => StockMovement::TYPE_STOCK_OUT, 'performed_by' => $this->admin->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/stock-movements?type='.StockMovement::TYPE_STOCK_IN);

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.type', StockMovement::TYPE_STOCK_IN);
    }

    public function test_movement_detail_includes_relations(): void
    {
        $movement = StockMovement::create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 4,
            'performed_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/stock-movements/{$movement->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $movement->id)
            ->assertJsonPath('data.item.id', $this->item->id)
            ->assertJsonPath('data.warehouse.id', $this->warehouse->id)
            ->assertJsonPath('data.performer.id', $this->admin->id);
    }

    public function test_missing_movement_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/stock-movements/99999');

        $response->assertNotFound();
    }

    public function test_admin_can_post_stock_in(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 25,
            'notes' => 'Delivery received',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', StockMovement::TYPE_STOCK_IN)
            ->assertJsonPath('data.quantity', 25)
            ->assertJsonPath('data.item.id', $this->item->id)
            ->assertJsonPath('data.warehouse.id', $this->warehouse->id)
            ->assertJsonPath('data.performer.id', $this->admin->id)
            ->assertJsonPath('data.notes', 'Delivery received');

        $this->assertDatabaseHas('stock_movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 25,
            'performed_by' => $this->admin->id,
        ]);
    }

    public function test_performer_is_never_taken_from_client(): void
    {
        $impostor = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 3,
            'performed_by' => $impostor->id,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('stock_movements', ['performed_by' => $this->admin->id]);
        $this->assertDatabaseMissing('stock_movements', ['performed_by' => $impostor->id]);
    }

    public function test_warehouse_staff_can_post_stock_movement(): void
    {
        $response = $this->actingAs($this->warehouseStaff)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 10,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.performer.id', $this->warehouseStaff->id);
    }

    public function test_staff_cannot_post_stock_movement(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 1,
        ]);

        $response->assertForbidden();
    }

    public function test_transaction_rolls_back_on_insufficient_stock_for_stock_out(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_OUT,
            'quantity' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Insufficient stock for this movement.');

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stock_out_requires_available_stock_balance(): void
    {
        StockMovement::create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 10,
            'performed_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_OUT,
            'quantity' => 11,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient stock for this movement.');
    }

    public function test_stock_out_within_balance_succeeds(): void
    {
        StockMovement::create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 10,
            'performed_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_OUT,
            'quantity' => 3,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', StockMovement::TYPE_STOCK_OUT)
            ->assertJsonPath('data.quantity', 3);
    }

    public function test_balance_is_derived_from_the_journal(): void
    {
        StockMovement::create(['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'type' => StockMovement::TYPE_STOCK_IN, 'quantity' => 50, 'performed_by' => $this->admin->id, 'created_at' => now()]);
        StockMovement::create(['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'type' => StockMovement::TYPE_STOCK_IN, 'quantity' => 30, 'performed_by' => $this->admin->id, 'created_at' => now()]);
        StockMovement::create(['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'type' => StockMovement::TYPE_STOCK_OUT, 'quantity' => 20, 'performed_by' => $this->admin->id, 'created_at' => now()]);

        $this->assertSame(60, app(StockMovementService::class)->balance($this->item->id, $this->warehouse->id));

        $this->actingAs($this->admin)->getJson("/api/v1/items/{$this->item->id}")
            ->assertOk()
            ->assertJsonPath('data.stock.total', 60);
    }

    public function test_balance_is_per_warehouse(): void
    {
        $otherWh = Warehouse::factory()->create(['code' => 'WH-OTHER']);
        StockMovement::create(['item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'type' => StockMovement::TYPE_STOCK_IN, 'quantity' => 9, 'performed_by' => $this->admin->id, 'created_at' => now()]);
        StockMovement::create(['item_id' => $this->item->id, 'warehouse_id' => $otherWh->id, 'type' => StockMovement::TYPE_STOCK_IN, 'quantity' => 2, 'performed_by' => $this->admin->id, 'created_at' => now()]);

        $this->actingAs($this->admin)->getJson("/api/v1/items/{$this->item->id}")
            ->assertOk()
            ->assertJsonPath('data.stock.total', 11)
            ->assertJsonCount(2, 'data.stock.warehouses');
    }

    public function test_soft_deleted_item_cannot_receive_movements(): void
    {
        $this->item->delete();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['item_id']);
    }

    public function test_quantity_must_be_positive_integer(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 0,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_invalid_type_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => 'STOCK_TRANSFER',
            'quantity' => 1,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_missing_required_fields_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['item_id', 'warehouse_id', 'type', 'quantity']);
    }

    public function test_reference_fields_are_optional(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 1,
            'reference_type' => 'purchase_order',
            'reference_id' => 77,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.reference_type', 'purchase_order')
            ->assertJsonPath('data.reference_id', 77);
    }

    public function test_no_update_endpoint_exists_for_movements(): void
    {
        $movement = StockMovement::factory()->create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'performed_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/stock-movements/{$movement->id}", [
            'quantity' => 999,
        ]);

        $response->assertMethodNotAllowed();
    }

    public function test_no_delete_endpoint_exists_for_movements(): void
    {
        $movement = StockMovement::factory()->create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'performed_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/stock-movements/{$movement->id}");

        $response->assertMethodNotAllowed();

        $this->assertDatabaseHas('stock_movements', ['id' => $movement->id]);
    }

    public function test_password_not_exposed_in_movement_response(): void
    {
        $movement = StockMovement::create([
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 1,
            'performed_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/stock-movements/{$movement->id}");

        $response->assertOk()
            ->assertJsonMissing(['password', 'token', 'remember_token']);
    }

    public function test_super_admin_has_full_access(): void
    {
        $super = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);

        $response = $this->actingAs($super)->postJson('/api/v1/stock-movements', [
            'item_id' => $this->item->id,
            'warehouse_id' => $this->warehouse->id,
            'type' => StockMovement::TYPE_STOCK_IN,
            'quantity' => 1,
        ]);

        $response->assertCreated();
    }
}
