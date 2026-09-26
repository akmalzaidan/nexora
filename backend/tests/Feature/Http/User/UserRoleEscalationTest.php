<?php

namespace Tests\Feature\Http\User;

class UserRoleEscalationTest extends UserTestCase
{
    /**
     * @return array<string, string|int>
     */
    private function payload(string $email, int $roleId): array
    {
        return [
            'name' => 'Escalation Probe',
            'email' => $email,
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role_id' => $roleId,
        ];
    }

    public function test_admin_can_create_staff(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', $this->payload('staff@nexora.test', $this->roleId('staff')), $this->authorizationHeader($token))
            ->assertStatus(201);
    }

    public function test_admin_can_create_manager(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', $this->payload('manager@nexora.test', $this->roleId('manager')), $this->authorizationHeader($token))
            ->assertStatus(201);
    }

    public function test_admin_can_create_technician(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', $this->payload('technician@nexora.test', $this->roleId('technician')), $this->authorizationHeader($token))
            ->assertStatus(201);
    }

    public function test_admin_can_create_another_admin(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', $this->payload('admin2@nexora.test', $this->roleId('admin')), $this->authorizationHeader($token))
            ->assertStatus(201);
    }

    public function test_admin_cannot_create_super_admin(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));

        $this->postJson('/api/v1/users', $this->payload('intruder@nexora.test', $this->roleId('super_admin')), $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Only a super admin can assign the super_admin role');

        $this->assertDatabaseMissing('users', ['email' => 'intruder@nexora.test']);
    }

    public function test_super_admin_can_create_super_admin(): void
    {
        $token = $this->actingAsUser($this->userWithRole('super_admin'));

        $this->postJson('/api/v1/users', $this->payload('root2@nexora.test', $this->roleId('super_admin')), $this->authorizationHeader($token))
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'root2@nexora.test']);
    }

    public function test_admin_cannot_upgrade_existing_user_to_super_admin(): void
    {
        $admin = $this->userWithRole('admin');
        $token = $this->actingAsUser($admin);
        $target = $this->userWithRole('staff');

        $this->putJson("/api/v1/users/{$target->id}", [
            'role_id' => $this->roleId('super_admin'),
        ], $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Only a super admin can assign the super_admin role');

        $this->assertSame($this->roleId('staff'), $target->fresh()->role_id);
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = $this->userWithRole('admin');
        $token = $this->actingAsUser($admin);

        $this->putJson("/api/v1/users/{$admin->id}", [
            'role_id' => $this->roleId('manager'),
        ], $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You cannot change your own role');

        $this->assertSame($this->roleId('admin'), $admin->fresh()->role_id);
    }

    public function test_admin_cannot_deactivate_own_account(): void
    {
        $admin = $this->userWithRole('admin');
        $token = $this->actingAsUser($admin);

        $this->putJson("/api/v1/users/{$admin->id}", [
            'is_active' => false,
        ], $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You cannot deactivate your own account');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_can_manage_another_admin(): void
    {
        $adminA = $this->userWithRole('admin');
        $token = $this->actingAsUser($adminA);
        $adminB = $this->userWithRole('admin');

        $this->putJson("/api/v1/users/{$adminB->id}", [
            'role_id' => $this->roleId('staff'),
        ], $this->authorizationHeader($token))
            ->assertOk();

        $this->putJson("/api/v1/users/{$adminB->id}", [
            'is_active' => false,
        ], $this->authorizationHeader($token))
            ->assertOk();
    }

    public function test_admin_cannot_deactivate_the_last_active_super_admin(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $last = $this->userWithRole('super_admin');

        $this->putJson("/api/v1/users/{$last->id}", [
            'is_active' => false,
        ], $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cannot deactivate the last active super admin');

        $this->assertTrue($last->fresh()->is_active);
    }

    public function test_admin_cannot_change_role_of_the_last_active_super_admin(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $last = $this->userWithRole('super_admin');

        $this->putJson("/api/v1/users/{$last->id}", [
            'role_id' => $this->roleId('admin'),
        ], $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cannot change the role of the last active super admin');

        $this->assertSame($this->roleId('super_admin'), $last->fresh()->role_id);
    }

    public function test_admin_cannot_delete_the_last_active_super_admin(): void
    {
        $token = $this->actingAsUser($this->userWithRole('admin'));
        $last = $this->userWithRole('super_admin');

        $this->deleteJson("/api/v1/users/{$last->id}", [], $this->authorizationHeader($token))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Cannot delete the last active super admin');

        $this->assertDatabaseHas('users', ['id' => $last->id]);
    }

    public function test_super_admin_can_manage_roles_after_a_second_super_admin_exists(): void
    {
        $actor = $this->userWithRole('super_admin');
        $token = $this->actingAsUser($actor);
        $other = $this->userWithRole('super_admin', ['email' => 'other@nexora.test']);

        $this->putJson("/api/v1/users/{$other->id}", [
            'is_active' => false,
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->putJson("/api/v1/users/{$other->id}", [
            'is_active' => true,
        ], $this->authorizationHeader($token))
            ->assertOk();

        $this->putJson("/api/v1/users/{$other->id}", [
            'role_id' => $this->roleId('admin'),
        ], $this->authorizationHeader($token))
            ->assertOk()
            ->assertJsonPath('data.role.slug', 'admin');

        $this->deleteJson("/api/v1/users/{$other->id}", [], $this->authorizationHeader($token))
            ->assertOk();
    }
}
