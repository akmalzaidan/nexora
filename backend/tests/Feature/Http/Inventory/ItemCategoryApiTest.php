<?php

namespace Tests\Feature\Http\Inventory;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $this->admin = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
    }

    public function test_authenticated_user_can_list_item_categories(): void
    {
        ItemCategory::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/item-categories');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => ['id', 'name', 'code', 'description', 'items_count', 'created_at', 'updated_at'],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_list_item_categories(): void
    {
        $response = $this->getJson('/api/v1/item-categories');

        $response->assertUnauthorized();
    }

    public function test_list_includes_items_count(): void
    {
        $category = ItemCategory::factory()->create(['code' => 'CNT']);
        Item::factory()->count(3)->create(['item_category_id' => $category->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/item-categories');

        $response->assertOk()
            ->assertJsonPath('data.items.0.items_count', 3);
    }

    public function test_search_by_name_or_code(): void
    {
        ItemCategory::factory()->create(['code' => 'CABLE', 'name' => 'Network Cables']);
        ItemCategory::factory()->create(['code' => 'OFFICE', 'name' => 'Office Supplies']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/item-categories?search=CABLE');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'CABLE');
    }

    public function test_sort_rejects_invalid_columns(): void
    {
        ItemCategory::factory()->create(['code' => 'A', 'name' => 'Alpha']);
        ItemCategory::factory()->create(['code' => 'B', 'name' => 'Beta']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/item-categories?sort=invalid');

        $response->assertOk()
            ->assertJsonPath('data.items.0.name', 'Alpha');
    }

    public function test_admin_can_create_item_category(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/item-categories', [
            'name' => 'Printer Supplies',
            'code' => 'PRINTER',
            'description' => 'Toner and drums',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'PRINTER');

        $this->assertDatabaseHas('item_categories', ['code' => 'PRINTER']);
    }

    public function test_create_with_duplicate_code_fails(): void
    {
        ItemCategory::factory()->create(['code' => 'DUP']);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/item-categories', [
            'name' => 'Duplicate',
            'code' => 'DUP',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_create_missing_required_fields_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/item-categories', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code']);
    }

    public function test_can_get_item_category_detail(): void
    {
        $category = ItemCategory::factory()->create(['code' => 'DETAIL']);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/item-categories/{$category->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.code', 'DETAIL');
    }

    public function test_missing_item_category_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/item-categories/99999');

        $response->assertNotFound();
    }

    public function test_admin_can_update_item_category(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/item-categories/{$category->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('item_categories', ['id' => $category->id, 'name' => 'New Name']);
    }

    public function test_update_same_code_allowed(): void
    {
        $category = ItemCategory::factory()->create(['code' => 'SAME']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/item-categories/{$category->id}", [
            'code' => 'SAME',
            'name' => 'Renamed',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.code', 'SAME');
    }

    public function test_admin_can_delete_unused_item_category(): void
    {
        $category = ItemCategory::factory()->create(['code' => 'DELETABLE']);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/item-categories/{$category->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('item_categories', ['id' => $category->id]);
    }

    public function test_cannot_delete_item_category_in_use(): void
    {
        $category = ItemCategory::factory()->create(['code' => 'INUSE']);
        Item::factory()->create(['item_category_id' => $category->id]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/item-categories/{$category->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Item category cannot be deleted because it is still in use');

        $this->assertDatabaseHas('item_categories', ['id' => $category->id]);
    }

    public function test_staff_gets_403_on_item_category_create(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->postJson('/api/v1/item-categories', [
            'name' => 'Nope',
            'code' => 'NOPE',
        ]);

        $response->assertForbidden();
    }

    public function test_warehouse_staff_can_create_item_category(): void
    {
        $warehouseStaff = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);

        $response = $this->actingAs($warehouseStaff)->postJson('/api/v1/item-categories', [
            'name' => 'Storage Bins',
            'code' => 'BINS',
        ]);

        $response->assertCreated();
    }

    public function test_super_admin_has_full_access(): void
    {
        $super = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);

        $response = $this->actingAs($super)->getJson('/api/v1/item-categories');

        $response->assertOk();
    }
}
