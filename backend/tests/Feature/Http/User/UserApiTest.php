<?php

namespace Tests\Feature\Http\User;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserApiTest extends UserTestCase
{
    public function test_admin_can_list_users_with_eager_loaded_relationships(): void
    {
        $department = $this->makeDepartment();
        $admin = $this->userWithRole('admin', ['name' => 'Zoe Administrator']);
        $admin->update(['department_id' => $department->id]);
        $token = $this->actingAsUser($admin);

        $staff = $this->userWithRole('staff', ['name' => 'Akmal Ramadhan', 'email' => 'akmal@nexora.test', 'department_id' => $department->id]);

        $this->getJson('/api/v1/users', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Users retrieved successfully')
            ->assertJsonStructure(['data' => ['items', 'pagination' => [
                'current_page', 'per_page', 'total', 'last_page',
            ]]])
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.name', $staff->name)
            ->assertJsonPath('data.items.0.email', 'akmal@nexora.test')
            ->assertJsonPath('data.items.0.role.slug', 'staff')
            ->assertJsonPath('data.items.0.department.name', 'Information Technology')
            ->assertJsonPath('data.items.1.name', 'Zoe Administrator')
            ->assertJsonPath('data.items.1.role.slug', 'admin')
            ->assertJsonPath('data.items.1.department.code', 'IT')
            ->assertJsonMissingPath('data.items.0.department.users')
            ->assertJsonMissingPath('data.items.0.role.permissions');
    }

    public function test_list_paginates_with_default_page_size(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $this->userWithRole('staff', ['name' => 'First Staff']);

        $this->getJson('/api/v1/users', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 15);
    }

    public function test_list_clamps_per_page(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson('/api/v1/users?per_page=1000', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 100);

        $this->getJson('/api/v1/users?per_page=1', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.per_page', 1);
    }

    public function test_list_filters_by_name_in_case_insensitive_way(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $this->userWithRole('staff', ['name' => 'Budi Santoso']);
        $this->userWithRole('staff', ['name' => 'Siti Aminah']);

        $this->getJson('/api/v1/users?search=budi', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Budi Santoso');

        $this->getJson('/api/v1/users?search=SITI', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Siti Aminah');
    }

    public function test_list_searches_by_email(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $this->userWithRole('staff', ['email' => 'target@nexora.test']);

        $this->getJson('/api/v1/users?search=target@', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.email', 'target@nexora.test');
    }

    public function test_list_filters_by_is_active(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $this->userWithRole('staff', ['is_active' => false, 'name' => 'Disabled One']);

        $this->getJson('/api/v1/users?is_active=false', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.is_active', false);
    }

    public function test_list_filters_by_role_and_department(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $department = $this->makeDepartment(['name' => 'Human Resources', 'code' => 'HR']);
        $this->userWithRole('manager', ['department_id' => $department->id]);
        $this->userWithRole('staff');

        $this->getJson('/api/v1/users?'.http_build_query(['role_id' => $this->roleId('manager')]), $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.role.slug', 'manager');

        $this->getJson('/api/v1/users?'.http_build_query(['department_id' => $department->id]), $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.department.code', 'HR');
    }

    public function test_list_sorts_by_name_and_email(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin', [
            'name' => 'Zoe Administrator',
            'email' => 'zz@nexora.test',
        ]));
        $this->userWithRole('staff', ['name' => 'Zara Last', 'email' => 'zara@nexora.test']);
        $this->userWithRole('staff', ['name' => 'Adam First', 'email' => 'adam@nexora.test']);

        $this->getJson('/api/v1/users?sort=name&direction=asc', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.items.0.name', 'Adam First')
            ->assertJsonPath('data.pagination.total', 3);

        $this->getJson('/api/v1/users?sort=email&direction=desc', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.items.0.email', 'zz@nexora.test');
    }

    public function test_list_rejects_arbitrary_sort_column(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $this->userWithRole('staff', ['name' => 'Zara Last']);
        $this->userWithRole('staff', ['name' => 'Adam First']);

        $this->getJson('/api/v1/users?sort=password', $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3);
    }

    public function test_admin_can_create_user_with_role_and_department(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $department = $this->makeDepartment();

        $this->postJson('/api/v1/users', [
            'name' => 'New Staff',
            'email' => 'newstaff@nexora.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => $this->roleId('staff'),
            'department_id' => $department->id,
            'is_active' => true,
        ], $this->authorizationHeader($token))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User created successfully')
            ->assertJsonPath('data.name', 'New Staff')
            ->assertJsonPath('data.email', 'newstaff@nexora.test')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.role.slug', 'staff')
            ->assertJsonPath('data.department.code', 'IT');

        $this->assertDatabaseHas('users', [
            'email' => 'newstaff@nexora.test',
            'role_id' => $this->roleId('staff'),
            'department_id' => $department->id,
        ]);
    }

    public function test_create_rejects_duplicate_email(): void
    {
        $this->userWithRole('staff', ['email' => 'taken@nexora.test']);
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', [
            'name' => 'Duplicate',
            'email' => 'taken@nexora.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => $this->roleId('staff'),
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_create_rejects_invalid_role(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', [
            'name' => 'Bad Role',
            'email' => 'badrole@nexora.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => 99999,
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['role_id']]);
    }

    public function test_create_rejects_invalid_department(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', [
            'name' => 'Bad Dept',
            'email' => 'baddept@nexora.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => $this->roleId('staff'),
            'department_id' => 99999,
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['department_id']]);
    }

    public function test_create_rejects_weak_password(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', [
            'name' => 'Weak Pass',
            'email' => 'weak@nexora.test',
            'password' => 'short',
            'password_confirmation' => 'short',
            'role_id' => $this->roleId('staff'),
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_create_hashes_password(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', [
            'name' => 'Hashed Pass',
            'email' => 'hashed@nexora.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => $this->roleId('staff'),
        ], $this->authorizationHeader($token))->assertStatus(201);

        $user = User::where('email', 'hashed@nexora.test')->firstOrFail();
        $this->assertNotSame('secret1234', $user->password);
        $this->assertTrue(Hash::check('secret1234', $user->password));
    }

    public function test_admin_can_view_user_detail(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $department = $this->makeDepartment();
        $target = $this->userWithRole('manager', ['department_id' => $department->id]);

        $this->getJson("/api/v1/users/{$target->id}", $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.role.slug', 'manager')
            ->assertJsonPath('data.department.code', 'IT');
    }

    public function test_show_missing_user_returns_404(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->getJson('/api/v1/users/999999', $this->authorizationHeader($token))
            ->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The requested resource was not found.');
    }

    public function test_admin_can_update_user_fields(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $department = $this->makeDepartment(['name' => 'Operations', 'code' => 'OPS']);
        $target = $this->userWithRole('staff');

        $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'Updated Name',
            'email' => 'updated@nexora.test',
            'role_id' => $this->roleId('manager'),
            'department_id' => $department->id,
            'is_active' => false,
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User updated successfully')
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.email', 'updated@nexora.test')
            ->assertJsonPath('data.role.slug', 'manager')
            ->assertJsonPath('data.department.code', 'OPS')
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'Updated Name',
            'role_id' => $this->roleId('manager'),
            'department_id' => $department->id,
            'is_active' => false,
        ]);
    }

    public function test_update_allows_keeping_own_email(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $target = $this->userWithRole('staff', ['email' => 'keeper@nexora.test']);

        $this->putJson("/api/v1/users/{$target->id}", [
            'email' => 'keeper@nexora.test',
            'name' => 'Still Mine',
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.email', 'keeper@nexora.test');
    }

    public function test_update_rejects_duplicate_email(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $this->userWithRole('staff', ['email' => 'occupied@nexora.test']);
        $target = $this->userWithRole('manager');

        $this->putJson("/api/v1/users/{$target->id}", [
            'email' => 'occupied@nexora.test',
        ], $this->authorizationHeader($token))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_update_can_change_password(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $target = $this->userWithRole('staff');

        $this->putJson("/api/v1/users/{$target->id}", [
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $target->fresh();
        $this->assertNotSame('brand-new-pass', $fresh->password);
        $this->assertTrue(Hash::check('brand-new-pass', $fresh->password));
    }

    public function test_update_applies_only_provided_fields(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $department = $this->makeDepartment();
        $target = $this->userWithRole('manager', ['email' => 'untouched@nexora.test', 'department_id' => $department->id]);

        $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'Only Name Changed',
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.name', 'Only Name Changed')
            ->assertJsonPath('data.email', 'untouched@nexora.test')
            ->assertJsonPath('data.role.slug', 'manager')
            ->assertJsonPath('data.department.code', 'IT');
    }

    public function test_update_can_clear_department(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $department = $this->makeDepartment();
        $target = $this->userWithRole('staff', ['department_id' => $department->id]);

        $this->putJson("/api/v1/users/{$target->id}", [
            'department_id' => null,
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.department', null);

        $this->assertNull($target->fresh()->department_id);
    }

    public function test_admin_can_delete_unused_user(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $target = $this->userWithRole('staff');

        $this->deleteJson("/api/v1/users/{$target->id}", [], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User deleted successfully')
            ->assertJsonPath('data', null);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_delete_missing_user_returns_404(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->deleteJson('/api/v1/users/999999', [], $this->authorizationHeader($token))
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = $this->userWithRole('admin');
        $token = $this->actingAsUser($admin);

        $this->deleteJson("/api/v1/users/{$admin->id}", [], $this->authorizationHeader($token))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You cannot delete your own account');
    }

    public function test_user_who_manages_a_department_can_be_deleted_and_department_survives(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $manager = $this->userWithRole('manager');
        $department = Department::create([
            'name' => 'Managed Dept',
            'code' => 'MGD',
            'manager_id' => $manager->id,
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/users/{$manager->id}", [], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('message', 'User deleted successfully');

        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'Managed Dept']);
        $this->assertDatabaseMissing('users', ['id' => $manager->id]);
        $this->assertNull($department->fresh()->manager_id);
    }

    public function test_delete_user_with_blocking_reference_returns_409(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $requester = $this->userWithRole('staff');

        Ticket::factory()->create(['requester_id' => $requester->id]);

        $this->deleteJson("/api/v1/users/{$requester->id}", [], $this->authorizationHeader($token))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'User cannot be deleted because it is still in use');

        $this->assertDatabaseHas('users', ['id' => $requester->id]);
    }
}
