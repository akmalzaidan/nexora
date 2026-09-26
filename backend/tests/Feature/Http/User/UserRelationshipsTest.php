<?php

namespace Tests\Feature\Http\User;

class UserRelationshipsTest extends UserTestCase
{
    public function test_user_detail_serializes_role_and_department_as_flat_objects(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $department = $this->makeDepartment();
        $target = $this->userWithRole('manager', ['department_id' => $department->id]);

        $this->getJson("/api/v1/users/{$target->id}", $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.role.id', $this->roleId('manager'))
            ->assertJsonPath('data.role.name', 'Manager')
            ->assertJsonPath('data.department.id', $department->id)
            ->assertJsonPath('data.department.name', 'Information Technology')
            ->assertJsonPath('data.department.code', 'IT')
            ->assertJsonMissingPath('data.department.users')
            ->assertJsonMissingPath('data.department.manager')
            ->assertJsonMissingPath('data.role.permissions')
            ->assertJsonMissingPath('data.role.users');
    }

    public function test_list_never_recurses_into_department_users(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $department = $this->makeDepartment();
        $this->userWithRole('staff', ['name' => 'First Member', 'department_id' => $department->id]);
        $this->userWithRole('staff', ['name' => 'Second Member', 'department_id' => $department->id]);

        $this->getJson('/api/v1/users', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonMissingPath('data.items.0.department.users');
    }

    public function test_department_has_many_users_via_relationship(): void
    {
        $department = $this->makeDepartment();
        $userA = $this->userWithRole('staff', ['department_id' => $department->id]);
        $userB = $this->userWithRole('staff', ['department_id' => $department->id]);

        $this->assertSame(2, $department->users()->count());
        $this->assertTrue($department->users->contains($userA));
        $this->assertTrue($department->users->contains($userB));
    }

    public function test_user_belongs_to_role_and_department_via_relationship(): void
    {
        $department = $this->makeDepartment();
        $user = $this->userWithRole('technician', ['department_id' => $department->id]);

        $this->assertSame('technician', $user->role->slug);
        $this->assertTrue($user->department->is($department));
    }

    public function test_user_without_department_serializes_department_as_null(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $target = $this->userWithRole('staff');

        $this->getJson("/api/v1/users/{$target->id}", $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.department', null);
    }
}
