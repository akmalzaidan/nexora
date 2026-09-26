<?php

namespace Tests\Feature\Http\Audit;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared setup for the Audit Logs feature tests (Phase 20A).
 *
 * Seeds the real roles + permissions + role-permission mapping so the
 * authorization tests verify the production seeder (view_audit_logs is mapped
 * to super_admin and admin only).
 */
abstract class AuditLogTestCase extends TestCase
{
    use RefreshDatabase;

    protected const ENDPOINT = '/api/v1/audit-logs';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    protected function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);
    }

    protected function roleId(string $slug): int
    {
        return (int) Role::where('slug', $slug)->value('id');
    }
}
