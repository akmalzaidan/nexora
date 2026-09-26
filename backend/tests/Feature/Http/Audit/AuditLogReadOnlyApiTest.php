<?php

namespace Tests\Feature\Http\Audit;

use App\Models\AuditLog;

// The audit API is read-only: any write verb must be rejected as 405 by
// construction (no route exists for it), never silently ignored.

class AuditLogReadOnlyApiTest extends AuditLogTestCase
{
    public function test_post_on_the_audit_log_collection_is_405(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->postJson(self::ENDPOINT, ['action' => 'created'])
            ->assertStatus(405);
    }

    public function test_put_on_an_audit_log_record_is_405(): void
    {
        $admin = $this->userWithRole('admin');
        $log = AuditLog::factory()->create();

        $this->actingAs($admin)
            ->putJson(self::ENDPOINT.'/'.$log->id, ['description' => 'tampered'])
            ->assertStatus(405);

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_patch_on_an_audit_log_record_is_405(): void
    {
        $admin = $this->userWithRole('admin');
        $log = AuditLog::factory()->create();

        $this->actingAs($admin)
            ->patchJson(self::ENDPOINT.'/'.$log->id, ['description' => 'tampered'])
            ->assertStatus(405);

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_delete_on_an_audit_log_record_is_405(): void
    {
        $admin = $this->userWithRole('admin');
        $log = AuditLog::factory()->create();

        $this->actingAs($admin)
            ->deleteJson(self::ENDPOINT.'/'.$log->id)
            ->assertStatus(405);

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }
}
