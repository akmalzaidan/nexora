<?php

namespace Tests\Feature\Http\Organization;

use App\Models\Location;
use App\Models\Warehouse;

class LocationApiTest extends OrganizationTestCase
{
    protected function makeLocation(array $attributes = []): Location
    {
        return Location::create(array_merge([
            'name' => 'Head Office',
            'code' => 'HO',
            'is_active' => true,
        ], $attributes));
    }

    public function test_authenticated_user_can_list_locations(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $this->makeLocation();

        $this->getJson('/api/v1/locations', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Locations retrieved successfully')
            ->assertJsonStructure(['data' => ['items', 'pagination' => [
                'current_page', 'per_page', 'total', 'last_page',
            ]]]);
    }

    public function test_unauthenticated_user_cannot_list_locations(): void
    {
        $this->getJson('/api/v1/locations')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $token = $this->actingAsUser($this->userWithRole('staff'));

        $this->getJson('/api/v1/locations', $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_authorized_user_can_create_location(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/locations', [
            'name' => 'Head Office',
            'code' => 'HO',
            'description' => 'Main office',
            'address' => 'Example address',
            'is_active' => true,
        ], $this->authorizationHeader($token))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Location created successfully')
            ->assertJsonPath('data.name', 'Head Office')
            ->assertJsonPath('data.code', 'HO')
            ->assertJsonPath('data.address', 'Example address')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('locations', ['code' => 'HO', 'is_active' => true]);
    }

    public function test_validation_rejects_invalid_location(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/locations', [
            'name' => '',
            'code' => '',
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors' => ['name', 'code']]);
    }

    public function test_duplicate_location_code_rejected(): void
    {
        $this->makeLocation(['code' => 'HO']);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/locations', [
            'name' => 'Other Office',
            'code' => 'HO',
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors' => ['code']]);
    }

    public function test_authorized_user_can_update_location(): void
    {
        $location = $this->makeLocation(['code' => 'HO']);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->putJson("/api/v1/locations/{$location->id}", [
            'name' => 'Head Office',
            'code' => 'HO',
            'description' => 'Updated location',
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('message', 'Location updated successfully')
            ->assertJsonPath('data.description', 'Updated location')
            ->assertJsonPath('data.code', 'HO');

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'description' => 'Updated location']);
    }

    public function test_authorized_user_can_delete_unused_location(): void
    {
        $location = $this->makeLocation();
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->deleteJson("/api/v1/locations/{$location->id}", [], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('message', 'Location deleted successfully')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_location_in_use_cannot_be_deleted(): void
    {
        $location = $this->makeLocation();
        Warehouse::create([
            'name' => 'Central Warehouse',
            'code' => 'CW',
            'location_id' => $location->id,
            'is_active' => true,
        ]);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->deleteJson("/api/v1/locations/{$location->id}", [], $this->authorizationHeader($token))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Location cannot be deleted because it is still in use')
            ->assertJsonPath('errors', []);

        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }

    public function test_search_works_case_insensitively(): void
    {
        $this->makeLocation(['name' => 'Head Office', 'code' => 'HO']);
        $this->makeLocation(['name' => 'Central Storage', 'code' => 'CS']);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson('/api/v1/locations?search=ho', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'HO');
    }

    public function test_pagination_works(): void
    {
        foreach (range(1, 25) as $i) {
            $this->makeLocation([
                'name' => "Location {$i}",
                'code' => sprintf('LOC-%03d', $i),
            ]);
        }
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson('/api/v1/locations?per_page=10', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonCount(10, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 10)
            ->assertJsonPath('data.pagination.total', 25)
            ->assertJsonPath('data.pagination.last_page', 3);
    }
}
