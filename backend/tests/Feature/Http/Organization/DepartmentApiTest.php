<?php

namespace Tests\Feature\Http\Organization;

use App\Models\User;

class DepartmentApiTest extends OrganizationTestCase
{
    public function test_authenticated_user_can_list_departments(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $this->makeDepartment();

        $this->getJson('/api/v1/departments', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Departments retrieved successfully')
            ->assertJsonStructure(['data' => ['items', 'pagination' => [
                'current_page', 'per_page', 'total', 'last_page',
            ]]]);
    }

    public function test_unauthenticated_user_cannot_list_departments(): void
    {
        $this->getJson('/api/v1/departments')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $token = $this->actingAsUser($this->userWithRole('staff'));

        $this->getJson('/api/v1/departments', $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_authorized_user_can_create_department(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/departments', [
            'name' => 'Information Technology',
            'code' => 'IT',
            'description' => 'Technology department',
            'is_active' => true,
        ], $this->authorizationHeader($token))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Department created successfully')
            ->assertJsonPath('data.name', 'Information Technology')
            ->assertJsonPath('data.code', 'IT')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.manager', null);

        $this->assertDatabaseHas('departments', ['code' => 'IT', 'is_active' => true]);
    }

    public function test_validation_rejects_invalid_department(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/departments', [
            'name' => '',
            'code' => '',
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors' => ['name', 'code']]);
    }

    public function test_duplicate_department_code_rejected(): void
    {
        $this->makeDepartment(['code' => 'IT']);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/departments', [
            'name' => 'Other Department',
            'code' => 'IT',
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors' => ['code']]);
    }

    public function test_authorized_user_can_update_department(): void
    {
        $department = $this->makeDepartment(['code' => 'IT']);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->putJson("/api/v1/departments/{$department->id}", [
            'name' => 'Information Technology',
            'code' => 'IT',
            'description' => 'Updated description',
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('message', 'Department updated successfully')
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.code', 'IT');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'description' => 'Updated description']);
    }

    public function test_authorized_user_can_delete_unused_department(): void
    {
        $department = $this->makeDepartment();
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->deleteJson("/api/v1/departments/{$department->id}", [], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('message', 'Department deleted successfully')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_department_with_users_cannot_be_deleted(): void
    {
        $department = $this->makeDepartment();
        User::factory()->create(['department_id' => $department->id]);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->deleteJson("/api/v1/departments/{$department->id}", [], $this->authorizationHeader($token))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Department cannot be deleted because it is still in use')
            ->assertJsonPath('errors', []);
    }

    public function test_department_detail_includes_manager(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        $department = $this->makeDepartment(['manager_id' => $manager->id]);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson("/api/v1/departments/{$department->id}", $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.manager.id', $manager->id)
            ->assertJsonPath('data.manager.name', $manager->name)
            ->assertJsonPath('data.manager.email', $manager->email);
    }

    public function test_department_detail_has_null_manager_when_not_assigned(): void
    {
        $department = $this->makeDepartment();
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson("/api/v1/departments/{$department->id}", $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.manager', null);
    }

    public function test_department_detail_includes_users_count(): void
    {
        $department = $this->makeDepartment();
        User::factory()->count(2)->create(['department_id' => $department->id]);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson("/api/v1/departments/{$department->id}", $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.users_count', 2);
    }

    public function test_search_works_case_insensitively(): void
    {
        $this->makeDepartment(['name' => 'Information Technology', 'code' => 'IT']);
        $this->makeDepartment(['name' => 'Human Resources', 'code' => 'HR']);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson('/api/v1/departments?search=it', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'IT');
    }

    public function test_pagination_works(): void
    {
        foreach (range(1, 25) as $i) {
            $this->makeDepartment([
                'name' => "Department {$i}",
                'code' => sprintf('DPT-%03d', $i),
            ]);
        }
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson('/api/v1/departments?per_page=10', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonCount(10, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 10)
            ->assertJsonPath('data.pagination.total', 25)
            ->assertJsonPath('data.pagination.last_page', 3);
    }
}
