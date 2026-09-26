<?php

namespace Tests\Feature\Http\CommandCenter;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\Notification;
use App\Models\StockMovement;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The surface is read-only: no write verb is routed, a read never changes
 * business state, and one call stays a bounded number of queries.
 */
class CommandCenterReadOnlyApiTest extends CommandCenterTestCase
{
    public function test_no_write_verb_is_routed_to_the_command_center(): void
    {
        $this->actingAs($this->admin)->postJson(self::ENDPOINT)->assertStatus(405);
        $this->actingAs($this->admin)->putJson(self::ENDPOINT)->assertStatus(405);
        $this->actingAs($this->admin)->patchJson(self::ENDPOINT)->assertStatus(405);
        $this->actingAs($this->admin)->deleteJson(self::ENDPOINT)->assertStatus(405);
    }

    public function test_reading_the_snapshot_writes_nothing(): void
    {
        $asset = Asset::factory()->create(['status' => 'ACTIVE']);
        $ticket = Ticket::factory()->create([
            'requester_id' => $this->staff->id,
            'status' => Ticket::STATUS_OPEN,
        ]);
        $request = MaintenanceRequest::factory()->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
            'status' => MaintenanceRequest::STATUS_REQUESTED,
        ]);
        MaintenanceRecord::factory()->create([
            'maintenance_request_id' => $request->id,
            'asset_id' => $asset->id,
        ]);
        AssetAssignment::factory()->create(['asset_id' => $asset->id, 'status' => 'PENDING']);
        $unread = Notification::factory()->create(['user_id' => $this->admin->id, 'read_at' => null]);

        $before = [
            'assets' => Asset::withoutTrashed()->count(),
            'tickets' => Ticket::count(),
            'tickets_updated_at' => Ticket::max('updated_at'),
            'requests' => MaintenanceRequest::count(),
            'requests_updated_at' => MaintenanceRequest::max('updated_at'),
            'records' => MaintenanceRecord::count(),
            'assignments' => AssetAssignment::count(),
            'movements' => StockMovement::count(),
            'notifications' => Notification::count(),
        ];

        $this->snapshotFor($this->admin)->assertOk();
        $this->snapshotFor($this->admin)->assertOk();

        $this->assertSame($before['assets'], Asset::withoutTrashed()->count());
        $this->assertSame($before['tickets'], Ticket::count());
        $this->assertSame($before['tickets_updated_at'], Ticket::max('updated_at'));
        $this->assertSame($before['requests'], MaintenanceRequest::count());
        $this->assertSame($before['requests_updated_at'], MaintenanceRequest::max('updated_at'));
        $this->assertSame($before['records'], MaintenanceRecord::count());
        $this->assertSame($before['assignments'], AssetAssignment::count());
        $this->assertSame($before['movements'], StockMovement::count());

        // No notification is created, and the caller's own unread row is not
        // silently marked read by a dashboard view.
        $this->assertSame($before['notifications'], Notification::count());
        $this->assertNull($unread->fresh()?->read_at);
        $this->assertSame(1, $this->snapshotFor($this->admin)->assertOk()->json('data.snapshot.notifications.unread_count'));
    }

    public function test_reading_the_snapshot_emits_no_model_write_events(): void
    {
        $fired = [];

        foreach (['created', 'updated', 'deleted', 'saved', 'restored'] as $event) {
            Event::listen('eloquent.'.$event.': *', function (string $name) use (&$fired): void {
                $fired[] = $name;
            });
        }

        $this->snapshotFor($this->admin)->assertOk();

        $this->assertSame([], $fired, 'The Command Center must not write through any model.');
    }

    public function test_one_snapshot_stays_within_the_query_budget(): void
    {
        // A dashboard is loaded repeatedly, so a single call must stay a small,
        // fixed number of aggregates instead of one query per row.
        Asset::factory()->count(5)->create();
        Ticket::factory()->count(5)->create(['requester_id' => $this->staff->id]);
        MaintenanceRequest::factory()->count(5)->create(['requested_by' => $this->staff->id]);
        Notification::factory()->count(5)->create(['user_id' => $this->admin->id]);

        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->snapshotFor($this->admin)->assertOk();

        $this->assertLessThanOrEqual(20, $queries, 'The Command Center must not fan out into per-row queries.');
        $this->assertGreaterThan(0, $queries);
    }

    public function test_the_snapshot_issues_no_query_per_queue_row(): void
    {
        // 20 waiting records must not cost 20 extra lookups: the N+1 check is the
        // query total, and it must not grow with the size of the queue.
        $asset = Asset::factory()->create();

        Ticket::factory()->count(20)->create(['requester_id' => $this->staff->id]);
        MaintenanceRequest::factory()->count(20)->create([
            'asset_id' => $asset->id,
            'requested_by' => $this->staff->id,
        ]);

        // Warm up the request first: the first call in a process also loads the
        // role relation, which would otherwise hide a per-row regression.
        $this->snapshotFor($this->admin)->assertOk();

        $small = $this->countQueries(fn () => $this->snapshotFor($this->admin, '?limit=2')->assertOk());
        $large = $this->countQueries(fn () => $this->snapshotFor($this->admin, '?limit=20')->assertOk());

        $this->assertSame($small, $large, 'Queue size must not change the number of queries.');
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return $count;
    }
}
