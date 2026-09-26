<?php

namespace Tests\Feature\Http\Audit;

use App\Models\AuditLog;

// Access control: guests 401, roles without view_audit_logs 403, super_admin
// and admin 200. Audit logs are global to authorized governance users — the
// actor_id filter is a query aid, never a scope bypass.

class AuditLogSecurityApiTest extends AuditLogTestCase
{
    public function test_requires_authentication_for_the_list(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    public function test_requires_authentication_for_the_detail(): void
    {
        $log = AuditLog::factory()->create();

        $this->getJson(self::ENDPOINT.'/'.$log->id)->assertStatus(401);
    }

    public function test_denies_the_list_to_staff(): void
    {
        $this->actingAs($this->userWithRole('staff'))
            ->getJson(self::ENDPOINT)
            ->assertStatus(403);
    }

    public function test_denies_the_list_to_technician(): void
    {
        $this->actingAs($this->userWithRole('technician'))
            ->getJson(self::ENDPOINT)
            ->assertStatus(403);
    }

    public function test_denies_the_list_to_warehouse_staff(): void
    {
        $this->actingAs($this->userWithRole('warehouse_staff'))
            ->getJson(self::ENDPOINT)
            ->assertStatus(403);
    }

    public function test_denies_the_detail_to_manager(): void
    {
        $log = AuditLog::factory()->create();

        $this->actingAs($this->userWithRole('manager'))
            ->getJson(self::ENDPOINT.'/'.$log->id)
            ->assertStatus(403);
    }

    public function test_allows_super_admin_to_read_the_list(): void
    {
        AuditLog::factory()->count(3)->create();

        $this->actingAs($this->userWithRole('super_admin'))
            ->getJson(self::ENDPOINT)
            ->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 3);
    }

    public function test_allows_admin_to_read_the_detail(): void
    {
        $log = AuditLog::factory()->create();

        $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'/'.$log->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $log->id);
    }

    public function test_returns_404_for_a_missing_audit_log_id(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'/99999')
            ->assertStatus(404);
    }

    public function test_response_never_exposes_secrets(): void
    {
        $log = AuditLog::factory()->create([
            'description' => 'User admin@nexora.test updated',
            'new_values' => ['name' => 'New Name'],
        ]);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'/'.$log->id)
            ->assertStatus(200);

        $content = $response->getContent();
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('plainTextToken', $content);
        $this->assertStringNotContainsString('remember_token', $content);
    }
}
