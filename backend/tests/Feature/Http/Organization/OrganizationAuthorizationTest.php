<?php

namespace Tests\Feature\Http\Organization;

/**
 * Verifies the seeded role-permission mapping grants organization permissions
 * to super_admin/admin and denies them to staff without relying on role IDs.
 */
class OrganizationAuthorizationTest extends OrganizationTestCase
{
    private const ORGANIZATION_PERMISSIONS = [
        'view_departments',
        'manage_departments',
        'view_locations',
        'manage_locations',
    ];

    public function test_super_admin_has_all_organization_permissions(): void
    {
        $user = $this->userWithRole('super_admin');

        foreach (self::ORGANIZATION_PERMISSIONS as $permission) {
            $this->assertTrue($user->hasPermission($permission), "Missing {$permission} for super_admin");
        }
    }

    public function test_admin_has_all_organization_permissions(): void
    {
        $user = $this->userWithRole('admin');

        foreach (self::ORGANIZATION_PERMISSIONS as $permission) {
            $this->assertTrue($user->hasPermission($permission), "Missing {$permission} for admin");
        }
    }

    public function test_staff_has_no_organization_permissions(): void
    {
        $user = $this->userWithRole('staff');

        foreach (self::ORGANIZATION_PERMISSIONS as $permission) {
            $this->assertFalse($user->hasPermission($permission), "{$permission} granted to staff");
        }
    }

    public function test_staff_cannot_manage_locations(): void
    {
        $token = $this->actingAsUser($this->userWithRole('staff'));

        $this->postJson('/api/v1/locations', [
            'name' => 'Head Office',
            'code' => 'HO',
        ], $this->authorizationHeader($token))
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Access denied.');
    }

    public function test_super_admin_bypasses_permission_middleware(): void
    {
        $token = $this->actingAsUser($this->userWithRole('super_admin'));

        $this->getJson('/api/v1/departments', $this->authorizationHeader($token))
            ->assertOk();
    }
}
