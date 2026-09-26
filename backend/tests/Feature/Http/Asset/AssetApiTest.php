<?php

namespace Tests\Feature\Http\Asset;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetHistory;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AssetCategory $category;

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
        $this->category = AssetCategory::factory()->create(['code' => 'TESTCAT']);
        $this->location = Location::factory()->create(['code' => 'TESTLOC']);
    }

    // -----------------------------------------------------------------------
    // List
    // -----------------------------------------------------------------------

    public function test_authenticated_user_can_list_assets(): void
    {
        Asset::factory()->count(3)->create([
            'asset_category_id' => $this->category->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => [
                            'id', 'asset_code', 'name', 'description', 'serial_number',
                            'status', 'condition', 'purchase_date', 'purchase_price',
                            'warranty_expiry', 'category', 'location',
                            'created_at', 'updated_at',
                        ],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_unauthenticated_user_cannot_list_assets(): void
    {
        $response = $this->getJson('/api/v1/assets');

        $response->assertUnauthorized();
    }

    public function test_pagination_defaults_to_15(): void
    {
        Asset::factory()->count(20)->create(['asset_category_id' => $this->category->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets');

        $response->assertOk()
            ->assertJsonPath('data.pagination.per_page', 15)
            ->assertJsonPath('data.pagination.total', 20)
            ->assertJsonPath('data.pagination.last_page', 2);
    }

    public function test_pagination_respects_per_page(): void
    {
        Asset::factory()->count(5)->create(['asset_category_id' => $this->category->id]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?per_page=2');

        $response->assertOk()
            ->assertJsonPath('data.pagination.per_page', 2);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_search_by_asset_code(): void
    {
        Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'AST-0001',
            'name' => 'Laptop A',
        ]);
        Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'AST-0002',
            'name' => 'Laptop B',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?search=AST-0001');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'AST-0001');
    }

    public function test_search_by_name(): void
    {
        Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'AST-0010',
            'name' => 'Special Laptop',
        ]);
        Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'AST-0011',
            'name' => 'Normal Laptop',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?search=Special');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Special Laptop');
    }

    public function test_search_by_serial_number(): void
    {
        Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'AST-0100',
            'name' => 'Some Asset',
            'serial_number' => 'SN-998877',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?search=SN-998877');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.serial_number', 'SN-998877');
    }

    public function test_filter_by_category(): void
    {
        $cat2 = AssetCategory::factory()->create(['code' => 'CAT2']);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'asset_code' => 'AST-A', 'name' => 'A']);
        Asset::factory()->create(['asset_category_id' => $cat2->id, 'asset_code' => 'AST-B', 'name' => 'B']);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/assets?asset_category_id={$this->category->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'AST-A');
    }

    public function test_filter_by_location(): void
    {
        $loc2 = Location::factory()->create(['code' => 'LOC2']);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'location_id' => $this->location->id, 'asset_code' => 'AST-LOC1', 'name' => 'Loc1']);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'location_id' => $loc2->id, 'asset_code' => 'AST-LOC2', 'name' => 'Loc2']);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/assets?location_id={$this->location->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'AST-LOC1');
    }

    public function test_filter_by_status(): void
    {
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'status' => 'DRAFT', 'asset_code' => 'AST-DRAFT', 'name' => 'Draft']);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'status' => 'ACTIVE', 'asset_code' => 'AST-ACTIVE', 'name' => 'Active']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?status=ACTIVE');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'AST-ACTIVE');
    }

    public function test_filter_by_status_active(): void
    {
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'status' => 'ACTIVE', 'asset_code' => 'AST-ON', 'name' => 'On']);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'status' => 'INACTIVE', 'asset_code' => 'AST-OFF', 'name' => 'Off']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?status=ACTIVE');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'AST-ON');
    }

    public function test_filter_by_status_inactive(): void
    {
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'status' => 'ACTIVE', 'asset_code' => 'AST-ON', 'name' => 'On']);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'status' => 'INACTIVE', 'asset_code' => 'AST-OFF', 'name' => 'Off']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?status=INACTIVE');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.asset_code', 'AST-OFF');
    }

    public function test_sort_by_name(): void
    {
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'name' => 'Zebra Asset', 'asset_code' => 'AST-Z']);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'name' => 'Alpha Asset', 'asset_code' => 'AST-A']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?sort=name&direction=asc');

        $response->assertOk()
            ->assertJsonPath('data.items.0.name', 'Alpha Asset')
            ->assertJsonPath('data.items.1.name', 'Zebra Asset');
    }

    public function test_sort_by_asset_code(): void
    {
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'name' => 'B', 'asset_code' => 'ZZZ']);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'name' => 'A', 'asset_code' => 'AAA']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?sort=asset_code&direction=asc');

        $response->assertOk()
            ->assertJsonPath('data.items.0.asset_code', 'AAA')
            ->assertJsonPath('data.items.1.asset_code', 'ZZZ');
    }

    public function test_sort_by_created_at(): void
    {
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'name' => 'First', 'asset_code' => 'AST-FIRST']);
        sleep(1);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'name' => 'Second', 'asset_code' => 'AST-SECOND']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?sort=created_at&direction=asc');

        $response->assertOk()
            ->assertJsonPath('data.items.0.asset_code', 'AST-FIRST');
    }

    public function test_sort_rejects_invalid_columns(): void
    {
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'name' => 'Test', 'asset_code' => 'TEST']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets?sort=invalid_column');

        $response->assertOk()
            ->assertJsonPath('data.items.0.asset_code', 'TEST');
    }

    public function test_eager_loading_category_and_location(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'asset_code' => 'AST-EAGER',
            'name' => 'Eager',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets');

        $response->assertOk()
            ->assertJsonPath('data.items.0.category.id', $this->category->id)
            ->assertJsonPath('data.items.0.category.name', $this->category->name)
            ->assertJsonPath('data.items.0.location.id', $this->location->id)
            ->assertJsonPath('data.items.0.location.name', $this->location->name);
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    public function test_admin_can_create_asset(): void
    {
        $payload = [
            'asset_category_id' => $this->category->id,
            'asset_code' => 'NEW-AST-001',
            'name' => 'New Asset',
            'description' => 'A newly created asset',
            'serial_number' => 'SN-NEW001',
            'status' => 'DRAFT',
            'location_id' => $this->location->id,
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/v1/assets', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.asset_code', 'NEW-AST-001')
            ->assertJsonPath('data.name', 'New Asset')
            ->assertJsonPath('data.serial_number', 'SN-NEW001')
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.category.id', $this->category->id)
            ->assertJsonPath('data.location.id', $this->location->id);

        $this->assertDatabaseHas('assets', ['asset_code' => 'NEW-AST-001']);
    }

    public function test_create_asset_with_duplicate_code_fails(): void
    {
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'asset_code' => 'DUP-AST', 'name' => 'Dup']);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/assets', [
            'asset_category_id' => $this->category->id,
            'asset_code' => 'DUP-AST',
            'name' => 'Duplicate Asset',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_code']);
    }

    public function test_create_asset_with_invalid_category_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/assets', [
            'asset_category_id' => 99999,
            'asset_code' => 'INVALID-CAT',
            'name' => 'Bad Asset',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_category_id']);
    }

    public function test_create_asset_with_invalid_location_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/assets', [
            'asset_category_id' => $this->category->id,
            'asset_code' => 'INVALID-LOC',
            'name' => 'Bad Asset',
            'location_id' => 99999,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id']);
    }

    public function test_create_asset_with_invalid_status_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/assets', [
            'asset_category_id' => $this->category->id,
            'asset_code' => 'BAD-STATUS',
            'name' => 'Bad Asset',
            'status' => 'NONEXISTENT_STATUS',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_create_asset_missing_required_fields_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/assets', [
            'asset_code' => 'PARTIAL',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_category_id', 'name']);
    }

    // -----------------------------------------------------------------------
    // Detail
    // -----------------------------------------------------------------------

    public function test_can_get_asset_detail(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'asset_code' => 'DETAIL-AST',
            'name' => 'Detail',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/assets/{$asset->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $asset->id)
            ->assertJsonPath('data.asset_code', 'DETAIL-AST')
            ->assertJsonPath('data.category.id', $this->category->id)
            ->assertJsonPath('data.location.id', $this->location->id);
    }

    public function test_missing_asset_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/assets/99999');

        $response->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function test_admin_can_update_asset_name(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'UPDATE-AST',
            'name' => 'Original Name',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/assets/{$asset->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'name' => 'Updated Name']);
    }

    public function test_admin_can_update_asset_category(): void
    {
        $cat2 = AssetCategory::factory()->create(['code' => 'CAT2']);
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'CAT-CHANGE',
            'name' => 'Cat Change',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/assets/{$asset->id}", [
            'asset_category_id' => $cat2->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.category.id', $cat2->id);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'asset_category_id' => $cat2->id]);
    }

    public function test_admin_can_update_asset_location(): void
    {
        $loc2 = Location::factory()->create(['code' => 'LOC2']);
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'asset_code' => 'LOC-CHANGE',
            'name' => 'Loc Change',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/assets/{$asset->id}", [
            'location_id' => $loc2->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.location.id', $loc2->id);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'location_id' => $loc2->id]);
    }

    public function test_admin_can_update_asset_status(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'status' => 'DRAFT',
            'asset_code' => 'STATUS-CHANGE',
            'name' => 'Status Change',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/assets/{$asset->id}", [
            'status' => 'ACTIVE',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'status' => 'ACTIVE']);
    }

    public function test_update_with_duplicate_asset_code_fails(): void
    {
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'asset_code' => 'ORIGINAL-CODE', 'name' => 'Orig']);
        $other = Asset::factory()->create(['asset_category_id' => $this->category->id, 'asset_code' => 'OTHER-CODE', 'name' => 'Other']);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/assets/{$other->id}", [
            'asset_code' => 'ORIGINAL-CODE',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_code']);
    }

    public function test_update_same_asset_code_allowed(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'SAME-CODE',
            'name' => 'Some Asset',
        ]);

        $response = $this->actingAs($this->admin)->putJson("/api/v1/assets/{$asset->id}", [
            'asset_code' => 'SAME-CODE',
            'name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.asset_code', 'SAME-CODE');
    }

    public function test_missing_asset_on_update_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->putJson('/api/v1/assets/99999', [
            'name' => 'Nope',
        ]);

        $response->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    public function test_admin_can_delete_unused_asset(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'DELETABLE-AST',
            'name' => 'Deletable',
        ]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/assets/{$asset->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('assets', ['id' => $asset->id]);
    }

    public function test_cannot_delete_asset_with_assignments(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'ASSIGNED-AST',
            'name' => 'Assigned',
        ]);
        AssetAssignment::factory()->create(['asset_id' => $asset->id]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/assets/{$asset->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Asset cannot be deleted because it is still in use');

        $this->assertNotSoftDeleted('assets', ['id' => $asset->id]);
    }

    public function test_cannot_delete_asset_with_history(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'HISTORY-AST',
            'name' => 'History',
        ]);
        AssetHistory::factory()->create(['asset_id' => $asset->id]);

        $response = $this->actingAs($this->admin)->deleteJson("/api/v1/assets/{$asset->id}");

        $response->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertNotSoftDeleted('assets', ['id' => $asset->id]);
    }

    public function test_missing_asset_on_delete_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->deleteJson('/api/v1/assets/99999');

        $response->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------------

    public function test_staff_gets_403_on_asset_create(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->postJson('/api/v1/assets', [
            'asset_category_id' => $this->category->id,
            'asset_code' => 'STAFF-AST',
            'name' => 'Staff Asset',
        ]);

        $response->assertForbidden();
    }

    public function test_technician_gets_403_on_asset_create(): void
    {
        $tech = User::factory()->create(['role_id' => Role::where('slug', 'technician')->value('id')]);

        $response = $this->actingAs($tech)->postJson('/api/v1/assets', [
            'asset_category_id' => $this->category->id,
            'asset_code' => 'TECH-AST',
            'name' => 'Tech Asset',
        ]);

        $response->assertForbidden();
    }

    public function test_warehouse_staff_gets_403_on_asset_create(): void
    {
        $wh = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);

        $response = $this->actingAs($wh)->postJson('/api/v1/assets', [
            'asset_category_id' => $this->category->id,
            'asset_code' => 'WH-AST',
            'name' => 'WH Asset',
        ]);

        $response->assertForbidden();
    }

    public function test_manager_gets_403_on_asset_create(): void
    {
        $manager = User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]);

        $response = $this->actingAs($manager)->postJson('/api/v1/assets', [
            'asset_category_id' => $this->category->id,
            'asset_code' => 'MGR-AST',
            'name' => 'Manager Asset',
        ]);

        $response->assertForbidden();
    }

    public function test_super_admin_has_full_access(): void
    {
        $super = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);
        Asset::factory()->create(['asset_category_id' => $this->category->id, 'asset_code' => 'SUPER-AST', 'name' => 'Super']);

        $response = $this->actingAs($super)->getJson('/api/v1/assets');

        $response->assertOk();
    }

    public function test_password_not_exposed_in_asset_response(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'NOPASS-AST',
            'name' => 'NoPass',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/assets/{$asset->id}");

        $response->assertOk()
            ->assertJsonMissing(['password', 'token', 'remember_token'])
            ->assertJsonStructure([
                'data' => [
                    'id', 'asset_code', 'name', 'description', 'serial_number',
                    'status', 'condition', 'purchase_date', 'purchase_price',
                    'warranty_expiry', 'category', 'location', 'current_user',
                    'created_at', 'updated_at',
                ],
            ]);
    }

    public function test_update_does_not_create_assignment_record(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'asset_code' => 'NO-ASSIGN-AST',
            'name' => 'NoAssign',
        ]);

        $this->actingAs($this->admin)->putJson("/api/v1/assets/{$asset->id}", [
            'location_id' => null,
        ]);

        $this->assertDatabaseCount('asset_assignments', 0);
    }

    public function test_update_does_not_create_history_record(): void
    {
        $asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'NO-HIST-AST',
            'name' => 'NoHist',
        ]);

        $this->actingAs($this->admin)->putJson("/api/v1/assets/{$asset->id}", [
            'status' => 'MAINTENANCE',
        ]);

        $this->assertDatabaseCount('asset_histories', 0);
    }
}
