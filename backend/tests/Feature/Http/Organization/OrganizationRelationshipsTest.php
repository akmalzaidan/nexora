<?php

namespace Tests\Feature\Http\Organization;

use App\Models\Role;
use App\Models\User;

/**
 * Verifies the User ↔ Department relationships ($30) and the manager rule
 * ($11/$12): a manager must be a valid, active user and must not be granted a
 * role automatically — appointment never changes a user's role.
 */
class OrganizationRelationshipsTest extends OrganizationTestCase
{
    public function test_user_belongs_to_department(): void
    {
        $department = $this->makeDepartment(['code' => 'FIN']);
        $user = User::factory()->create([
            'department_id' => $department->id,
            'is_active' => true,
        ]);

        $this->assertSame($department->id, $user->department->id);
    }

    public function test_department_has_many_users(): void
    {
        $department = $this->makeDepartment(['code' => 'FIN']);
        $users = User::factory()->count(3)->create(['department_id' => $department->id]);

        $this->assertSame(3, $department->users()->count());
        $this->assertEqualsCanonicalizing(
            $users->pluck('id')->all(),
            $department->users->pluck('id')->all(),
        );
    }

    public function test_department_belongs_to_manager(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        $department = $this->makeDepartment(['manager_id' => $manager->id]);

        $this->assertSame($manager->id, $department->manager->id);
    }

    public function test_inactive_manager_cannot_be_assigned(): void
    {
        $inactiveUser = User::factory()->create(['is_active' => false]);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/departments', [
            'name' => 'Information Technology',
            'code' => 'IT',
            'manager_id' => $inactiveUser->id,
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors' => ['manager_id']]);
    }

    public function test_assigning_a_manager_does_not_change_the_users_role(): void
    {
        $staffUser = User::factory()->create([
            'role_id' => Role::where('slug', 'staff')->value('id'),
            'is_active' => true,
        ]);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Information Technology',
            'code' => 'IT',
            'manager_id' => $staffUser->id,
        ], $this->authorizationHeader($token))
            ->assertStatus(201)
            ->assertJsonPath('data.manager.id', $staffUser->id);

        $staffUser->refresh();
        $this->assertSame('staff', $staffUser->role->slug);
        $this->assertNotNull($response->json('data.manager'));
    }

    public function test_updating_department_can_clear_manager(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        $department = $this->makeDepartment(['manager_id' => $manager->id]);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->putJson("/api/v1/departments/{$department->id}", [
            'name' => 'Information Technology',
            'code' => 'IT',
            'manager_id' => null,
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.manager', null);

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'manager_id' => null]);
    }
}
