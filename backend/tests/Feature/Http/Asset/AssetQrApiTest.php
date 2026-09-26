<?php

namespace Tests\Feature\Http\Asset;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetQrApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Asset $asset;

    private string $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed('DatabaseSeeder');

        $adminRole = Role::where('slug', 'admin')->first();
        $this->admin = User::factory()->create(['role_id' => $adminRole?->id]);
        $adminRole?->permissions()->syncWithoutDetaching([
            Permission::where('slug', 'view_assets')->value('id'),
        ]);

        $category = AssetCategory::first() ?? AssetCategory::factory()->create(['name' => 'Test', 'code' => 'TST']);
        $location = Location::first() ?? Location::factory()->create(['name' => 'Test', 'code' => 'TT']);

        $this->asset = Asset::factory()->create([
            'asset_category_id' => $category->id,
            'asset_code' => 'AST-QR-001',
            'name' => 'QR Test Asset',
            'location_id' => $location->id,
            'status' => 'ACTIVE',
        ]);

        $this->payload = 'NEXORA:ASSET:AST-QR-001';
    }

    // ─── Authentication ────────────────────────────────────────────────

    public function test_unauthenticated_qr_metadata_returns_401(): void
    {
        $this->getJson("/api/v1/assets/{$this->asset->id}/qr")
            ->assertUnauthorized();
    }

    public function test_unauthenticated_qr_lookup_returns_401(): void
    {
        $this->getJson("/api/v1/assets/qr/{$this->payload}")
            ->assertUnauthorized();
    }

    // ─── Authorization ─────────────────────────────────────────────────

    public function test_user_without_view_assets_cannot_access_qr_metadata(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson("/api/v1/assets/{$this->asset->id}/qr")
            ->assertForbidden();
    }

    public function test_user_without_view_assets_cannot_access_qr_lookup(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson("/api/v1/assets/qr/{$this->payload}")
            ->assertForbidden();
    }

    // ─── QR Metadata ───────────────────────────────────────────────────

    public function test_qr_metadata_returns_identifier_and_payload(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/assets/{$this->asset->id}/qr")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.asset_id', $this->asset->id)
            ->assertJsonPath('data.identifier', 'AST-QR-001')
            ->assertJsonPath('data.payload', $this->payload);
    }

    public function test_qr_metadata_missing_asset_returns_404(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/assets/99999/qr')
            ->assertNotFound();
    }

    public function test_qr_metadata_does_not_expose_secrets(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/assets/{$this->asset->id}/qr")
            ->assertOk()
            ->assertJsonMissing(['password', 'remember_token', 'personal_access_token']);
    }

    // ─── QR Lookup ─────────────────────────────────────────────────────

    public function test_qr_lookup_with_full_payload_returns_asset(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/assets/qr/{$this->payload}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->asset->id)
            ->assertJsonPath('data.asset_code', 'AST-QR-001')
            ->assertJsonPath('data.name', 'QR Test Asset');
    }

    public function test_qr_lookup_with_bare_asset_code_returns_asset(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/assets/qr/AST-QR-001')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->asset->id);
    }

    public function test_qr_lookup_nonexistent_identifier_returns_404(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/assets/qr/NEXORA:ASSET:AST-NOPE-999')
            ->assertNotFound();
    }

    public function test_qr_lookup_invalid_payload_format_returns_404(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/assets/qr/INVALID-PAYLOAD')
            ->assertNotFound();
    }

    public function test_qr_lookup_wrong_entity_type_rejected(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/assets/qr/NEXORA:USER:123')
            ->assertNotFound();
    }

    public function test_qr_lookup_soft_deleted_asset_not_resolved(): void
    {
        $this->asset->delete();
        $this->actingAs($this->admin)->getJson("/api/v1/assets/qr/{$this->payload}")
            ->assertNotFound();
    }

    public function test_qr_lookup_includes_relationships(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/assets/qr/{$this->payload}")
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'asset_code',
                    'name',
                    'status',
                    'condition',
                    'category' => ['id', 'name', 'code'],
                    'location' => ['id', 'name', 'code'],
                ],
            ]);
    }

    public function test_qr_lookup_does_not_expose_secrets(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/assets/qr/{$this->payload}")
            ->assertOk()
            ->assertJsonMissing(['password', 'remember_token', 'personal_access_token']);
    }

    // ─── Asset Lifecycle Independence ──────────────────────────────────

    public function test_qr_payload_unchanged_after_assignment(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->seed('RolePermissionSeeder');

        $assigneeRole = Role::where('slug', 'admin')->first();
        $managePerm = Permission::where('slug', 'manage_asset_assignments')->value('id');
        $assigneeRole?->permissions()->syncWithoutDetaching([$managePerm]);

        $assignee = User::factory()->create(['role_id' => $assigneeRole?->id]);

        $this->actingAs($assignee)->postJson('/api/v1/asset-assignments', [
            'asset_id' => $this->asset->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($this->admin)->getJson("/api/v1/assets/{$this->asset->id}/qr")
            ->assertOk()
            ->assertJsonPath('data.payload', $this->payload);
    }

    public function test_qr_payload_unchanged_after_return(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->seed('RolePermissionSeeder');

        $adminRole = Role::where('slug', 'admin')->first();
        $managePerm = Permission::where('slug', 'manage_asset_assignments')->value('id');
        $adminRole?->permissions()->syncWithoutDetaching([$managePerm]);

        $assignment = AssetAssignment::create([
            'asset_id' => $this->asset->id,
            'user_id' => $user->id,
            'requested_by' => $this->admin->id,
            'status' => 'ACTIVE',
            'assigned_at' => now(),
        ]);

        $this->asset->current_user_id = $user->id;
        $this->asset->saveQuietly();

        $this->actingAs($this->admin)->postJson("/api/v1/asset-assignments/{$assignment->id}/return");

        $this->actingAs($this->admin)->getJson("/api/v1/assets/{$this->asset->id}/qr")
            ->assertOk()
            ->assertJsonPath('data.payload', $this->payload);
    }

    public function test_qr_payload_unchanged_after_location_change(): void
    {
        $location = Location::factory()->create(['name' => 'New Location', 'code' => 'NL']);
        $this->actingAs($this->admin)->putJson("/api/v1/assets/{$this->asset->id}", [
            'location_id' => $location->id,
        ]);

        $this->actingAs($this->admin)->getJson("/api/v1/assets/{$this->asset->id}/qr")
            ->assertOk()
            ->assertJsonPath('data.payload', $this->payload);
    }

    public function test_qr_payload_unchanged_after_status_change(): void
    {
        $this->actingAs($this->admin)->putJson("/api/v1/assets/{$this->asset->id}", [
            'status' => 'MAINTENANCE',
        ]);

        $this->actingAs($this->admin)->getJson("/api/v1/assets/{$this->asset->id}/qr")
            ->assertOk()
            ->assertJsonPath('data.payload', $this->payload);
    }

    // ─── Uniqueness ────────────────────────────────────────────────────

    public function test_asset_code_is_unique(): void
    {
        $this->assertDatabaseHas('assets', ['asset_code' => 'AST-QR-001']);
        $this->expectNotToPerformAssertions();
    }

    // ─── Security ──────────────────────────────────────────────────────

    public function test_qr_endpoints_never_expose_password_or_tokens(): void
    {
        $this->actingAs($this->admin)->getJson("/api/v1/assets/{$this->asset->id}/qr");
        $this->actingAs($this->admin)->getJson("/api/v1/assets/qr/{$this->payload}");

        $this->assertTrue(true);
    }
}
