<?php

namespace Tests\Unit\Models;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRbacTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $roleSlug, array $permissionSlugs = []): User
    {
        $role = Role::create(['name' => $roleSlug, 'slug' => $roleSlug]);

        if ($permissionSlugs !== []) {
            $permissionIds = collect($permissionSlugs)->map(function (string $slug): int {
                return Permission::create(['name' => $slug, 'slug' => $slug])->id;
            });
            $role->permissions()->sync($permissionIds->all());
        }

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_has_role_detects_the_roles_slug(): void
    {
        $user = $this->userWithRole('admin');

        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('manager'));
    }

    public function test_has_any_role_matches_any_of_the_given_slugs(): void
    {
        $user = $this->userWithRole('manager');

        $this->assertTrue($user->hasAnyRole(['admin', 'manager']));
        $this->assertFalse($user->hasAnyRole(['admin', 'staff']));
    }

    public function test_has_permission_detects_assigned_permission(): void
    {
        $user = $this->userWithRole('admin', ['manage_users', 'view_assets']);

        $this->assertTrue($user->hasPermission('manage_users'));
        $this->assertFalse($user->hasPermission('view_audit_logs'));
    }

    public function test_has_any_permission_matches_any_of_the_given_slugs(): void
    {
        $user = $this->userWithRole('staff', ['view_tickets']);

        $this->assertTrue($user->hasAnyPermission(['manage_users', 'view_tickets']));
        $this->assertFalse($user->hasAnyPermission(['manage_users', 'manage_assets']));
    }

    public function test_super_admin_has_permission_even_when_not_mapped(): void
    {
        $user = $this->userWithRole('super_admin');

        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->hasPermission('anything_at_all'));
        $this->assertTrue($user->hasAnyPermission(['manage_assets', 'view_audit_logs']));
    }

    public function test_user_without_role_has_no_permissions(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasRole('staff'));
        $this->assertFalse($user->hasPermission('view_dashboard'));
        $this->assertFalse($user->hasAnyPermission(['view_dashboard']));
    }
}
