<?php

namespace Tests\Feature\Http\Asset;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetCategoryApiTest extends TestCase
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

    // -----------------------------------------------------------------------
    // List
    // -----------------------------------------------------------------------

    public function test_authenticated_user_can_list_asset_categories(): void
    {
        AssetCategory::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => ['id', 'name', 'code', 'description', 'assets_count', 'created_at', 'updated_at'],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_list_asset_categories(): void
    {
        $response = $this->getJson('/api/v1/asset-categories');

        $response->assertUnauthorized();
    }

    public function test_pagination_defaults_to_15(): void
    {
        AssetCategory::factory()->count(20)->create();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories');

        $response->assertOk()
            ->assertJsonPath('data.pagination.per_page', 15)
            ->assertJsonPath('data.pagination.total', 20)
            ->assertJsonPath('data.pagination.last_page', 2);
    }

    public function test_pagination_respects_per_page(): void
    {
        AssetCategory::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?per_page=2');

        $response->assertOk()
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.pagination.last_page', 3);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_per_page_clamped_to_max_100(): void
    {
        AssetCategory::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?per_page=999');

        $response->assertOk()->assertJsonPath('data.pagination.per_page', 100);
    }

    public function test_per_page_clamped_to_min_1(): void
    {
        AssetCategory::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?per_page=0');

        $response->assertOk()->assertJsonPath('data.pagination.per_page', 1);
    }

    public function test_search_by_name(): void
    {
        AssetCategory::factory()->create(['name' => 'Laptop', 'code' => 'LAPTOP']);
        AssetCategory::factory()->create(['name' => 'Desktop', 'code' => 'DESKTOP']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?search=Laptop');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Laptop');
    }

    public function test_search_by_code(): void
    {
        AssetCategory::factory()->create(['name' => 'Laptop', 'code' => 'LAPTOP']);
        AssetCategory::factory()->create(['name' => 'Desktop', 'code' => 'DESKTOP']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?search=LAPTOP');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'LAPTOP');
    }

    public function test_sort_by_name(): void
    {
        AssetCategory::factory()->create(['name' => 'Zebra', 'code' => 'ZEBRA']);
        AssetCategory::factory()->create(['name' => 'Alpha', 'code' => 'ALPHA']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?sort=name&direction=asc');

        $response->assertOk()
            ->assertJsonPath('data.items.0.name', 'Alpha')
            ->assertJsonPath('data.items.1.name', 'Zebra');
    }

    public function test_sort_by_code(): void
    {
        AssetCategory::factory()->create(['name' => 'X', 'code' => 'ZZZ']);
        AssetCategory::factory()->create(['name' => 'Y', 'code' => 'AAA']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?sort=code&direction=asc');

        $response->assertOk()
            ->assertJsonPath('data.items.0.code', 'AAA')
            ->assertJsonPath('data.items.1.code', 'ZZZ');
    }

    public function test_sort_by_created_at(): void
    {
        $first = AssetCategory::factory()->create(['name' => 'First', 'code' => 'FIRST']);
        sleep(1);
        AssetCategory::factory()->create(['name' => 'Second', 'code' => 'SECOND']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?sort=created_at&direction=asc');

        $response->assertOk()
            ->assertJsonPath('data.items.0.name', 'First');
    }

    public function test_sort_rejects_invalid_columns(): void
    {
        AssetCategory::factory()->create(['name' => 'Test', 'code' => 'TEST']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories?sort=invalid_column');

        $response->assertOk()
            ->assertJsonPath('data.items.0.name', 'Test');
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    public function test_admin_can_create_asset_category(): void
    {
        $payload = [
            'name' => 'Test Category',
            'code' => 'TESTCAT',
            'description' => 'A test category',
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-categories', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Test Category')
            ->assertJsonPath('data.code', 'TESTCAT')
            ->assertJsonPath('data.description', 'A test category')
            ->assertJsonPath('data.assets_count', 0);

        $this->assertDatabaseHas('asset_categories', ['code' => 'TESTCAT', 'name' => 'Test Category']);
    }

    public function test_create_asset_category_without_name_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-categories', [
            'code' => 'NOCAT',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_asset_category_without_code_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-categories', [
            'name' => 'No Code',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_create_asset_category_with_duplicate_code_fails(): void
    {
        AssetCategory::factory()->create(['code' => 'DUPCODE']);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-categories', [
            'name' => 'Duplicate',
            'code' => 'DUPCODE',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    // -----------------------------------------------------------------------
    // Detail
    // -----------------------------------------------------------------------

    public function test_can_get_asset_category_detail(): void
    {
        $category = AssetCategory::factory()->create(['name' => 'Detail Cat', 'code' => 'DETAIL']);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/asset-categories/{$category->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', 'Detail Cat')
            ->assertJsonPath('data.code', 'DETAIL');
    }

    public function test_missing_category_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-categories/99999');

        $response->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function test_admin_can_update_asset_category(): void
    {
        $category = AssetCategory::factory()->create(['name' => 'Old Name', 'code' => 'OLDCODE']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/asset-categories/{$category->id}", [
            'name' => 'New Name',
            'code' => 'NEWCODE',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.code', 'NEWCODE');

        $this->assertDatabaseHas('asset_categories', ['id' => $category->id, 'name' => 'New Name', 'code' => 'NEWCODE']);
    }

    public function test_update_with_duplicate_code_fails(): void
    {
        $category = AssetCategory::factory()->create(['code' => 'ORIGINAL']);
        AssetCategory::factory()->create(['code' => 'DUPLICATE']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/asset-categories/{$category->id}", [
            'name' => 'Updated',
            'code' => 'DUPLICATE',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_update_same_code_allowed(): void
    {
        $category = AssetCategory::factory()->create(['name' => 'My Category', 'code' => 'MYCODE']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/asset-categories/{$category->id}", [
            'name' => 'My Category Updated',
            'code' => 'MYCODE',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.code', 'MYCODE');
    }

    public function test_missing_category_on_update_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->putJson('/api/v1/asset-categories/99999', [
            'name' => 'Nope',
            'code' => 'NOPE',
        ]);

        $response->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    public function test_admin_can_delete_unused_category(): void
    {
        $category = AssetCategory::factory()->create(['code' => 'DELETABLE']);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/asset-categories/{$category->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('asset_categories', ['id' => $category->id]);
    }

    public function test_cannot_delete_category_in_use(): void
    {
        $category = AssetCategory::factory()->create(['code' => 'INUSE']);
        Asset::factory()->create(['asset_category_id' => $category->id, 'asset_code' => 'AST-INUSE', 'name' => 'Test Asset']);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/asset-categories/{$category->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Asset category cannot be deleted because it is still in use');

        $this->assertDatabaseHas('asset_categories', ['id' => $category->id]);
    }

    public function test_missing_category_on_delete_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->deleteJson('/api/v1/asset-categories/99999');

        $response->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------------

    public function test_staff_without_permission_gets_403(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->getJson('/api/v1/asset-categories');

        $response->assertForbidden();
    }

    public function test_technician_without_permission_gets_403(): void
    {
        $tech = User::factory()->create(['role_id' => Role::where('slug', 'technician')->value('id')]);

        $response = $this->actingAs($tech)->getJson('/api/v1/asset-categories');

        $response->assertForbidden();
    }

    public function test_warehouse_staff_without_permission_gets_403(): void
    {
        $wh = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);

        $response = $this->actingAs($wh)->getJson('/api/v1/asset-categories');

        $response->assertForbidden();
    }

    public function test_manager_without_permission_gets_403(): void
    {
        $manager = User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]);

        $response = $this->actingAs($manager)->getJson('/api/v1/asset-categories');

        $response->assertForbidden();
    }

    public function test_super_admin_has_full_access(): void
    {
        $super = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);

        AssetCategory::factory()->create(['code' => 'SUPERACCESS']);

        $response = $this->actingAs($super)->getJson('/api/v1/asset-categories');

        $response->assertOk();
    }

    public function test_password_not_exposed_in_response(): void
    {
        $category = AssetCategory::factory()->create(['code' => 'NOPASS']);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/asset-categories/{$category->id}");

        $response->assertOk()
            ->assertJsonMissing(['password', 'token', 'remember_token'])
            ->assertJsonStructure([
                'data' => ['id', 'name', 'code', 'description', 'assets_count', 'created_at', 'updated_at'],
            ]);
    }
}
