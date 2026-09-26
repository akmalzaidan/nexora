<?php

namespace Tests\Feature\Http\Notification;

use App\Models\Notification;

class NotificationApiTest extends NotificationTestCase
{
    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_unauthenticated_user_cannot_use_notification_endpoints(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();

        $notification = Notification::factory()->create(['user_id' => $this->staff->id]);

        $this->getJson("/api/v1/notifications/{$notification->id}")->assertUnauthorized();
        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertUnauthorized();
        $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    }

    // -----------------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------------

    public function test_index_returns_only_own_notifications(): void
    {
        Notification::factory()->count(3)->create(['user_id' => $this->staff->id]);
        Notification::factory()->count(2)->create(['user_id' => $this->admin->id]);

        $response = $this->actingAs($this->staff)->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Notifications retrieved successfully')
            ->assertJsonPath('data.pagination.total', 3);

        $ids = collect($response->json('data.items'))->pluck('id')->all();

        Notification::query()->where('user_id', $this->admin->id)->get()
            ->each(fn (Notification $n) => $this->assertNotContains($n->id, $ids));
    }

    public function test_index_returns_expected_pagination_shape(): void
    {
        Notification::factory()->count(5)->create(['user_id' => $this->staff->id]);

        $response = $this->actingAs($this->staff)->getJson('/api/v1/notifications?per_page=2&page=2');

        $response->assertOk()
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_index_is_newest_first(): void
    {
        Notification::factory()->create(['user_id' => $this->staff->id, 'title' => 'oldest', 'created_at' => now()->subDays(2)]);
        Notification::factory()->create(['user_id' => $this->staff->id, 'title' => 'newest', 'created_at' => now()]);

        $titles = array_column($this->actingAs($this->staff)->getJson('/api/v1/notifications')->json('data.items'), 'title');

        $this->assertSame(['newest', 'oldest'], $titles);
    }

    public function test_index_filters_by_read_state(): void
    {
        Notification::factory()->count(2)->create(['user_id' => $this->staff->id, 'read_at' => null]);
        Notification::factory()->count(3)->create(['user_id' => $this->staff->id, 'read_at' => now()]);

        $this->actingAs($this->staff)->getJson('/api/v1/notifications?read=true')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3);

        $this->actingAs($this->staff)->getJson('/api/v1/notifications?read=false')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_index_filters_by_type(): void
    {
        Notification::factory()->create(['user_id' => $this->staff->id, 'type' => Notification::TYPE_TICKET_ASSIGNED]);
        Notification::factory()->create(['user_id' => $this->staff->id, 'type' => Notification::TYPE_ASSET_ASSIGNED]);

        $response = $this->actingAs($this->staff)->getJson(sprintf(
            '/api/v1/notifications?type=%s',
            Notification::TYPE_ASSET_ASSIGNED,
        ));

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.type', Notification::TYPE_ASSET_ASSIGNED);
    }

    public function test_index_ignores_user_id_query_parameter(): void
    {
        Notification::factory()->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->staff)->getJson('/api/v1/notifications?user_id=99999')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_resource_shape_does_not_expose_user(): void
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->staff->id,
            'type' => Notification::TYPE_TICKET_ASSIGNED,
            'title' => 'Ticket assigned to you',
            'message' => 'Ticket TCK-1 has been assigned to you.',
            'data' => ['ticket_id' => 10, 'ticket_number' => 'TCK-1'],
            'read_at' => null,
        ]);

        $item = $this->actingAs($this->staff)->getJson('/api/v1/notifications')->json('data.items.0');

        $this->assertSame($notification->id, $item['id']);
        $this->assertSame(Notification::TYPE_TICKET_ASSIGNED, $item['type']);
        $this->assertSame('Ticket assigned to you', $item['title']);
        $this->assertSame('Ticket TCK-1 has been assigned to you.', $item['message']);
        $this->assertSame(['ticket_id' => 10, 'ticket_number' => 'TCK-1'], $item['data']);
        $this->assertFalse($item['is_read']);
        $this->assertNull($item['read_at']);
        $this->assertArrayHasKey('created_at', $item);
        $this->assertArrayNotHasKey('user_id', $item);
        $this->assertArrayNotHasKey('user', $item);
    }

    // -----------------------------------------------------------------------
    // Detail + ownership
    // -----------------------------------------------------------------------

    public function test_show_returns_own_notification(): void
    {
        $notification = Notification::factory()->create(['user_id' => $this->staff->id]);

        $this->actingAs($this->staff)->getJson("/api/v1/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);
    }

    public function test_show_returns_404_for_another_users_notification(): void
    {
        $notification = Notification::factory()->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->staff)->getJson("/api/v1/notifications/{$notification->id}")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_show_returns_404_for_missing_notification(): void
    {
        $this->actingAs($this->staff)->getJson('/api/v1/notifications/99999')->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Unread count
    // -----------------------------------------------------------------------

    public function test_unread_count_is_zero_when_all_read(): void
    {
        Notification::factory()->count(3)->create(['user_id' => $this->staff->id, 'read_at' => now()]);

        $this->actingAs($this->staff)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_unread_count_reflects_only_own_rows(): void
    {
        Notification::factory()->count(2)->create(['user_id' => $this->staff->id]);
        Notification::factory()->count(5)->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->staff)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 2);
    }

    public function test_unread_count_decrements_exactly_once_after_reading(): void
    {
        $a = Notification::factory()->create(['user_id' => $this->staff->id]);
        $b = Notification::factory()->create(['user_id' => $this->staff->id]);

        $this->actingAs($this->staff)->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.count', 2);

        $this->actingAs($this->staff)->postJson("/api/v1/notifications/{$a->id}/read")->assertOk();

        $this->actingAs($this->staff)->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.count', 1);

        $this->actingAs($this->staff)->postJson("/api/v1/notifications/{$b->id}/read")->assertOk();

        $this->actingAs($this->staff)->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.count', 0);
    }

    // -----------------------------------------------------------------------
    // Mark as read
    // -----------------------------------------------------------------------

    public function test_mark_as_read_stamps_read_at_server_side(): void
    {
        $notification = Notification::factory()->create(['user_id' => $this->staff->id, 'read_at' => null]);

        $response = $this->actingAs($this->staff)->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk()
            ->assertJsonPath('message', 'Notification marked as read')
            ->assertJsonPath('data.is_read', true)
            ->assertJsonPath('data.id', $notification->id);

        $this->assertNotNull($response->json('data.read_at'));
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        $this->assertNotNull(Notification::find($notification->id)?->read_at);
    }

    public function test_mark_as_read_is_idempotent(): void
    {
        $notification = Notification::factory()->create(['user_id' => $this->staff->id, 'read_at' => now()]);

        $response = $this->actingAs($this->staff)->postJson("/api/v1/notifications/{$notification->id}/read");

        $response->assertOk()->assertJsonPath('data.is_read', true);

        $this->actingAs($this->staff)->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertSame(1, Notification::where('id', $notification->id)->count());
    }

    public function test_mark_as_read_returns_404_for_another_users_notification(): void
    {
        $notification = Notification::factory()->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->staff)->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->assertNull(Notification::find($notification->id)?->read_at);
    }

    // -----------------------------------------------------------------------
    // Mark all as read
    // -----------------------------------------------------------------------

    public function test_mark_all_reads_only_current_user(): void
    {
        Notification::factory()->count(2)->create(['user_id' => $this->staff->id]);
        Notification::factory()->count(1)->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->staff)->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->actingAs($this->staff)->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.count', 0);

        $this->actingAs($this->admin)->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.count', 1);
    }

    public function test_mark_all_is_idempotent(): void
    {
        Notification::factory()->count(2)->create(['user_id' => $this->staff->id]);

        $this->actingAs($this->staff)->postJson('/api/v1/notifications/read-all')
            ->assertJsonPath('data.count', 2);

        $this->actingAs($this->staff)->postJson('/api/v1/notifications/read-all')
            ->assertJsonPath('data.count', 0);
    }

    // -----------------------------------------------------------------------
    // No client-side creation
    // -----------------------------------------------------------------------

    public function test_post_notifications_is_not_allowed(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/v1/notifications', [
                'user_id' => $this->staff->id,
                'type' => Notification::TYPE_TICKET_ASSIGNED,
                'title' => 'spoofed',
                'message' => 'spoofed',
            ])->assertStatus(405);
    }
}
