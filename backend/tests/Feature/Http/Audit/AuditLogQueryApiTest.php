<?php

namespace Tests\Feature\Http\Audit;

use App\Models\AuditLog;
use App\Models\AuditLog as AuditLogAlias;
use App\Support\Audit\AuditResourceType;

// Query model: whitelisted filters (actor, action, resource type/id, inclusive
// date range), pagination default 25 / max 100, whitelisted sort, newest first.

class AuditLogQueryApiTest extends AuditLogTestCase
{
    public function test_lists_audit_logs_newest_first(): void
    {
        $older = AuditLog::factory()->create(['created_at' => now()->subHour()]);
        $newer = AuditLog::factory()->create(['created_at' => now()]);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT)
            ->assertStatus(200);

        $this->assertSame($newer->id, $response->json('data.items.0.id'));
        $this->assertSame($older->id, $response->json('data.items.1.id'));
    }

    public function test_paginates_with_default_25_and_clamps_per_page(): void
    {
        AuditLog::factory()->count(30)->create();
        $actor = $this->userWithRole('admin');

        $default = $this->actingAs($actor)->getJson(self::ENDPOINT)->assertStatus(200);
        $this->assertSame(25, $default->json('data.pagination.per_page'));
        $this->assertCount(25, $default->json('data.items'));

        $max = $this->actingAs($actor)->getJson(self::ENDPOINT.'?per_page=100')->assertStatus(200);
        $this->assertSame(100, $max->json('data.pagination.per_page'));

        $over = $this->actingAs($actor)->getJson(self::ENDPOINT.'?per_page=9999')->assertStatus(200);
        $this->assertSame(100, $over->json('data.pagination.per_page'));
    }

    public function test_filters_by_actor(): void
    {
        $admin = $this->userWithRole('admin');
        $other = $this->userWithRole('manager');

        AuditLog::factory()->create(['user_id' => $admin->id]);
        AuditLog::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($admin)
            ->getJson(self::ENDPOINT.'?actor_id='.$other->id)
            ->assertStatus(200);

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame($other->id, $response->json('data.items.0.actor.id'));
    }

    public function test_filters_by_action_and_ignores_unknown_actions(): void
    {
        AuditLog::factory()->create(['action' => 'created']);
        AuditLog::factory()->create(['action' => 'deleted']);
        AuditLog::factory()->create(['action' => 'DROP_TABLE_USERS']);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'?action=created')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame('created', $response->json('data.items.0.action'));
    }

    public function test_filters_by_whitelisted_resource_type_and_ignores_unknown_types(): void
    {
        AuditLog::factory()->create(['entity_type' => AuditResourceType::TICKET]);
        AuditLog::factory()->create(['entity_type' => AuditResourceType::ASSET]);
        AuditLog::factory()->create(['entity_type' => 'App\\Models\\Ticket']);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'?resource_type=ticket')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame('ticket', $response->json('data.items.0.resource.type'));
    }

    public function test_filters_by_resource_id_combined_with_resource_type(): void
    {
        AuditLog::factory()->create(['entity_type' => 'ticket', 'entity_id' => 7]);
        AuditLog::factory()->create(['entity_type' => 'ticket', 'entity_id' => 8]);
        AuditLog::factory()->create(['entity_type' => 'asset', 'entity_id' => 7]);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'?resource_type=ticket&resource_id=7')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('data.pagination.total'));
        $this->assertSame(7, $response->json('data.items.0.resource.id'));
    }

    public function test_filters_by_an_inclusive_from_to_date_range(): void
    {
        AuditLog::factory()->create(['created_at' => '2026-01-01 10:00:00']);
        AuditLog::factory()->create(['created_at' => '2026-01-10 23:59:59']);
        AuditLog::factory()->create(['created_at' => '2026-01-20 08:00:00']);

        $response = $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'?from=2026-01-01&to=2026-01-10')
            ->assertStatus(200);

        $this->assertSame(2, $response->json('data.pagination.total'));
    }

    public function test_rejects_a_one_sided_or_reversed_date_range_with_422(): void
    {
        $actor = $this->userWithRole('admin');

        $this->actingAs($actor)
            ->getJson(self::ENDPOINT.'?from=2026-01-01')
            ->assertStatus(422);

        $this->actingAs($actor)
            ->getJson(self::ENDPOINT.'?from=2026-02-01&to=2026-01-01')
            ->assertStatus(422);
    }

    public function test_rejects_a_date_range_wider_than_366_days_with_422(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'?from=2024-01-01&to=2026-01-02')
            ->assertStatus(422);
    }

    public function test_sorts_by_whitelisted_columns_and_falls_back_to_created_at_desc(): void
    {
        AuditLog::factory()->create(['action' => 'created']);
        AuditLog::factory()->create(['action' => 'deleted']);

        $byAction = $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'?sort=action&direction=asc')
            ->assertStatus(200);

        $this->assertSame('created', $byAction->json('data.items.0.action'));

        $invalid = $this->actingAs($this->userWithRole('admin'))
            ->getJson(self::ENDPOINT.'?sort=user_id;--drop')
            ->assertStatus(200);

        // Invalid sort falls back to the newest-first default.
        $this->assertSame(
            AuditLogAlias::query()->orderByDesc('id')->first()->id,
            $invalid->json('data.items.0.id'),
        );
    }
}
