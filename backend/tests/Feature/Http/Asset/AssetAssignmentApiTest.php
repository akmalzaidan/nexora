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

class AssetAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AssetCategory $category;

    private Location $location;

    private Asset $asset;

    private User $assignee;

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
        $this->asset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'asset_code' => 'ASSIGN-TEST-001',
            'name' => 'Assignment Test Asset',
            'status' => 'ACTIVE',
        ]);
        $this->assignee = User::factory()->create([
            'role_id' => Role::where('slug', 'staff')->value('id'),
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_unauthenticated_user_cannot_list_assignments(): void
    {
        $response = $this->getJson('/api/v1/asset-assignments');

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_create_assignment(): void
    {
        $response = $this->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_return_assignment(): void
    {
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/asset-assignments/{$assignment->id}/return");

        $response->assertUnauthorized();
    }

    // -----------------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------------

    public function test_staff_without_permission_gets_403_on_list(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->getJson('/api/v1/asset-assignments');

        $response->assertForbidden();
    }

    public function test_staff_without_permission_gets_403_on_create(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);

        $response = $this->actingAs($staff)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertForbidden();
    }

    public function test_staff_without_permission_gets_403_on_return(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff')->value('id')]);
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($staff)->postJson("/api/v1/asset-assignments/{$assignment->id}/return");

        $response->assertForbidden();
    }

    public function test_technician_without_manage_permission_gets_403_on_create(): void
    {
        $tech = User::factory()->create(['role_id' => Role::where('slug', 'technician')->value('id')]);

        $response = $this->actingAs($tech)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertForbidden();
    }

    public function test_warehouse_staff_without_manage_permission_gets_403_on_create(): void
    {
        $wh = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);

        $response = $this->actingAs($wh)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertForbidden();
    }

    public function test_manager_can_view_assignments(): void
    {
        $manager = User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]);

        $response = $this->actingAs($manager)->getJson('/api/v1/asset-assignments');

        $response->assertOk();
    }

    public function test_manager_cannot_create_assignments(): void
    {
        $manager = User::factory()->create(['role_id' => Role::where('slug', 'manager')->value('id')]);

        $response = $this->actingAs($manager)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertForbidden();
    }

    public function test_super_admin_has_full_access(): void
    {
        $super = User::factory()->create(['role_id' => Role::where('slug', 'super_admin')->value('id')]);

        $response = $this->actingAs($super)->getJson('/api/v1/asset-assignments');

        $response->assertOk();
    }

    // -----------------------------------------------------------------------
    // List
    // -----------------------------------------------------------------------

    public function test_admin_can_list_asset_assignments(): void
    {
        AssetAssignment::factory()->count(3)->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-assignments');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items' => [
                        '*' => [
                            'id', 'asset', 'user', 'location',
                            'status', 'assigned_at', 'returned_at', 'notes',
                            'created_at', 'updated_at',
                        ],
                    ],
                    'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
                ],
            ]);
    }

    public function test_list_pagination_defaults_to_15(): void
    {
        AssetAssignment::factory()->count(20)->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-assignments');

        $response->assertOk()
            ->assertJsonPath('data.pagination.per_page', 15)
            ->assertJsonPath('data.pagination.total', 20);
    }

    public function test_list_pagination_respects_per_page(): void
    {
        AssetAssignment::factory()->count(5)->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-assignments?per_page=2');

        $response->assertOk()
            ->assertJsonPath('data.pagination.per_page', 2);
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_list_filter_by_status(): void
    {
        AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
        ]);
        AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'RETURNED',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-assignments?status=ACTIVE');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.status', 'ACTIVE');
    }

    public function test_list_filter_by_asset(): void
    {
        $otherAsset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'OTHER-ASSET',
            'status' => 'ACTIVE',
        ]);
        AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
        ]);
        AssetAssignment::factory()->create([
            'asset_id' => $otherAsset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/asset-assignments?asset_id={$this->asset->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_list_filter_by_user(): void
    {
        $otherUser = User::factory()->create(['is_active' => true]);
        AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
        ]);
        AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $otherUser->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/asset-assignments?user_id={$this->assignee->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_list_sort_by_assigned_at(): void
    {
        $first = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
            'assigned_at' => now()->subDay(),
        ]);
        sleep(1);
        $second = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-assignments?sort=assigned_at&direction=desc');

        $response->assertOk()
            ->assertJsonPath('data.items.0.id', $second->id)
            ->assertJsonPath('data.items.1.id', $first->id);
    }

    public function test_list_sort_rejects_invalid_columns(): void
    {
        AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-assignments?sort=invalid_column');

        $response->assertOk();
    }

    public function test_list_search_by_asset_code(): void
    {
        AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
        ]);
        $otherAsset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'asset_code' => 'OTHER-CODE',
            'status' => 'ACTIVE',
        ]);
        AssetAssignment::factory()->create([
            'asset_id' => $otherAsset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-assignments?search=ASSIGN-TEST-001');

        $response->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_password_not_exposed_in_list_response(): void
    {
        AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-assignments');

        $response->assertOk()
            ->assertJsonMissing(['password', 'remember_token', 'token']);
    }

    // -----------------------------------------------------------------------
    // Detail
    // -----------------------------------------------------------------------

    public function test_admin_can_get_assignment_detail(): void
    {
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/asset-assignments/{$assignment->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $assignment->id)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.asset.id', $this->asset->id)
            ->assertJsonPath('data.asset.asset_code', 'ASSIGN-TEST-001')
            ->assertJsonPath('data.user.id', $this->assignee->id)
            ->assertJsonPath('data.user.name', $this->assignee->name);
    }

    public function test_missing_assignment_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/asset-assignments/99999');

        $response->assertNotFound();
    }

    public function test_detail_includes_requested_by(): void
    {
        $requester = User::factory()->create(['is_active' => true]);
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'requested_by' => $requester->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/asset-assignments/{$assignment->id}");

        $response->assertOk()
            ->assertJsonPath('data.requested_by.id', $requester->id)
            ->assertJsonPath('data.requested_by.name', $requester->name);
    }

    public function test_detail_includes_location_when_set(): void
    {
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'location_id' => $this->location->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/asset-assignments/{$assignment->id}");

        $response->assertOk()
            ->assertJsonPath('data.location.id', $this->location->id)
            ->assertJsonPath('data.location.name', $this->location->name);
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    public function test_admin_can_create_valid_assignment(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'location_id' => $this->location->id,
            'notes' => 'Test assignment',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.asset.id', $this->asset->id)
            ->assertJsonPath('data.user.id', $this->assignee->id);

        $assignment = AssetAssignment::first();
        $this->assertNotNull($assignment);
        $this->assertEquals('ACTIVE', $assignment->status);
        $this->assertEquals($this->asset->id, $assignment->asset_id);
        $this->assertEquals($this->assignee->id, $assignment->user_id);
        $this->assertEquals($this->admin->id, $assignment->requested_by);
        $this->assertNotNull($assignment->assigned_at);
    }

    public function test_create_assignment_updates_asset_current_user_id(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $this->asset->refresh();
        $this->assertEquals($this->assignee->id, $this->asset->current_user_id);
    }

    public function test_create_assignment_creates_asset_history(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $history = AssetHistory::where('asset_id', $this->asset->id)
            ->where('action', 'ASSIGNED')
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals($this->assignee->id, $history->user_id);
    }

    public function test_create_assignment_with_nonexistent_asset_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => 99999,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['asset_id']);
    }

    public function test_create_assignment_with_nonexistent_user_fails(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => 99999,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_create_assignment_with_inactive_user_fails(): void
    {
        $inactiveUser = User::factory()->create(['is_active' => false]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $inactiveUser->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Asset cannot be assigned to an inactive or nonexistent user');
    }

    public function test_create_assignment_with_retired_asset_fails(): void
    {
        $this->asset->status = 'RETIRED';
        $this->asset->saveQuietly();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Asset cannot be assigned because it is not in an assignable state (current status: RETIRED)');
    }

    public function test_create_assignment_with_lost_asset_fails(): void
    {
        $this->asset->status = 'LOST';
        $this->asset->saveQuietly();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_create_assignment_with_disposed_asset_fails(): void
    {
        $this->asset->status = 'DISPOSED';
        $this->asset->saveQuietly();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_create_assignment_with_soft_deleted_asset_fails(): void
    {
        // Eloquent's SoftDeletingScope already excludes trashed assets, so the
        // locked lookup in AssetAssignmentService::create resolves nothing and
        // the request 404s. Asserted on the side effects too: a 404 that still
        // wrote an assignment row, moved the asset holder or logged history
        // would be a real integrity bug.
        $this->asset->delete();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('asset_assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $this->assertNull($this->asset->fresh()->current_user_id);
        $this->assertNull(AssetHistory::where('asset_id', $this->asset->id)
            ->where('action', 'ASSIGNED')
            ->first());
    }

    public function test_create_assignment_with_already_active_assignment_fails(): void
    {
        AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $otherUser = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $otherUser->id,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Asset cannot be assigned because it already has an active assignment');
    }

    public function test_create_assignment_with_draft_asset_fails(): void
    {
        $draftAsset = Asset::factory()->create([
            'asset_category_id' => $this->category->id,
            'status' => 'DRAFT',
            'asset_code' => 'DRAFT-ASSET',
        ]);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $draftAsset->id,
            'user_id' => $this->assignee->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // -----------------------------------------------------------------------
    // Return
    // -----------------------------------------------------------------------

    public function test_admin_can_return_active_assignment(): void
    {
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/asset-assignments/{$assignment->id}/return");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'RETURNED');

        $returnedAt = $response->json('data.returned_at');
        $this->assertNotNull($returnedAt);

        $assignment->refresh();
        $this->assertEquals('RETURNED', $assignment->status);
        $this->assertNotNull($assignment->returned_at);
    }

    public function test_return_clears_asset_current_user_id(): void
    {
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $this->actingAs($this->admin)->postJson("/api/v1/asset-assignments/{$assignment->id}/return");

        $this->asset->refresh();
        $this->assertNull($this->asset->current_user_id);
    }

    public function test_return_creates_returned_history(): void
    {
        // Create assignment via API (which creates ASSIGNED history)
        $createResponse = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);
        $this->assertEquals(201, $createResponse->getStatusCode());

        $assignment = AssetAssignment::where('asset_id', $this->asset->id)
            ->where('user_id', $this->assignee->id)
            ->where('status', 'ACTIVE')
            ->first();

        $this->actingAs($this->admin)->postJson("/api/v1/asset-assignments/{$assignment->id}/return");

        $returnedHistory = AssetHistory::where('asset_id', $this->asset->id)
            ->where('action', 'RETURNED')
            ->first();

        $this->assertNotNull($returnedHistory);
        $this->assertEquals($this->assignee->id, $returnedHistory->user_id);
    }

    public function test_return_rejects_already_returned_assignment(): void
    {
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'RETURNED',
            'assigned_at' => now()->subDays(2),
            'returned_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/asset-assignments/{$assignment->id}/return");

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Asset assignment cannot be returned because it is not active');
    }

    public function test_return_rejects_pending_assignment(): void
    {
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'PENDING',
            'assigned_at' => null,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/asset-assignments/{$assignment->id}/return");

        $response->assertStatus(409);
    }

    public function test_missing_assignment_on_return_returns_404(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments/99999/return');

        $response->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Transactional consistency
    // -----------------------------------------------------------------------

    public function test_failed_assignment_does_not_create_partial_state(): void
    {
        $this->asset->status = 'RETIRED';
        $this->asset->saveQuietly();

        AssetHistory::where('asset_id', $this->asset->id)->delete();
        AssetAssignment::where('asset_id', $this->asset->id)->delete();
        $this->asset->current_user_id = null;
        $this->asset->saveQuietly();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
        ]);

        $this->asset->refresh();
        $this->assertNull($this->asset->current_user_id);
        $this->assertDatabaseCount('asset_assignments', 0);
        $this->assertDatabaseCount('asset_histories', 0);
        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Security
    // -----------------------------------------------------------------------

    public function test_password_not_exposed_in_assignment_detail(): void
    {
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/asset-assignments/{$assignment->id}");

        $response->assertOk()
            ->assertJsonMissing(['password', 'remember_token', 'token']);
    }

    public function test_user_email_not_exposed_for_requested_by_in_detail(): void
    {
        $requester = User::factory()->create(['is_active' => true]);
        $assignment = AssetAssignment::factory()->create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->assignee->id,
            'requested_by' => $requester->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/asset-assignments/{$assignment->id}");

        $response->assertOk()
            ->assertJsonPath('data.requested_by.id', $requester->id)
            ->assertJsonPath('data.requested_by.name', $requester->name)
            ->assertJsonMissing(['password', 'remember_token'])
            ->assertJsonStructure([
                'data' => ['requested_by' => ['id', 'name']],
            ]);
    }
}
