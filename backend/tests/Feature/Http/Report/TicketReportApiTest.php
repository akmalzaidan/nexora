<?php

namespace Tests\Feature\Http\Report;

use App\Models\Ticket;
use App\Models\TicketCategory;

class TicketReportApiTest extends ReportTestCase
{
    public function test_ticket_report_groups_the_current_state_by_status_priority_and_category(): void
    {
        $hardware = TicketCategory::factory()->create(['name' => 'Hardware']);
        $access = TicketCategory::factory()->create(['name' => 'Access']);
        $uncategorised = TicketCategory::factory()->create(['name' => 'Zulu Misc']);

        $this->ticket(['category_id' => $hardware->id, 'status' => 'OPEN', 'priority' => 'HIGH']);
        $this->ticket(['category_id' => $hardware->id, 'status' => 'OPEN', 'priority' => 'LOW']);
        $this->ticket(['category_id' => $access->id, 'status' => 'IN_PROGRESS', 'priority' => 'URGENT']);
        $this->ticket(['category_id' => null, 'status' => 'CLOSED', 'priority' => 'LOW']);
        $this->ticket(['category_id' => $uncategorised->id, 'status' => 'RESOLVED', 'priority' => 'MEDIUM']);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/tickets');

        $response->assertOk()
            ->assertJsonPath('message', 'Ticket report')
            ->assertJsonPath('data.current.total', 5)
            ->assertJsonPath('data.current.by_status', [
                ['status' => 'CLOSED', 'count' => 1],
                ['status' => 'IN_PROGRESS', 'count' => 1],
                ['status' => 'OPEN', 'count' => 2],
                ['status' => 'RESOLVED', 'count' => 1],
            ])
            ->assertJsonPath('data.current.by_priority', [
                ['priority' => 'HIGH', 'count' => 1],
                ['priority' => 'LOW', 'count' => 2],
                ['priority' => 'MEDIUM', 'count' => 1],
                ['priority' => 'URGENT', 'count' => 1],
            ])
            ->assertJsonPath('data.current.by_category', [
                ['category_id' => $hardware->id, 'label' => 'Hardware', 'count' => 2],
                ['category_id' => $access->id, 'label' => 'Access', 'count' => 1],
                ['category_id' => $uncategorised->id, 'label' => 'Zulu Misc', 'count' => 1],
            ])
            ->assertJsonPath('data.period', null);
    }

    public function test_ticket_report_trend_counts_created_tickets_per_day_and_fills_gaps(): void
    {
        $from = now()->subDays(4)->startOfDay();
        $to = now()->startOfDay();

        $this->ticket(['created_at' => $from]);
        $this->ticket(['created_at' => $from->copy()->addHours(3)]);
        $this->ticket(['created_at' => $to]);
        $this->ticket(['created_at' => $to->copy()->subMonth()]);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/reports/tickets?from={$from->toDateString()}&to={$to->toDateString()}");

        $response->assertOk()
            ->assertJsonPath('data.current.total', 4)
            ->assertJsonPath('data.period.created', 3)
            ->assertJsonPath('data.period.trend', [
                ['date' => $from->toDateString(), 'count' => 2],
                ['date' => $from->copy()->addDay()->toDateString(), 'count' => 0],
                ['date' => $from->copy()->addDays(2)->toDateString(), 'count' => 0],
                ['date' => $from->copy()->addDays(3)->toDateString(), 'count' => 0],
                ['date' => $to->toDateString(), 'count' => 1],
            ]);
    }

    public function test_ticket_report_trend_for_an_empty_period_is_a_zero_filled_series(): void
    {
        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        $response = $this->actingAs($this->admin)->getJson("/api/v1/reports/tickets?from={$from}&to={$to}");

        $response->assertOk()
            ->assertJsonPath('data.current.total', 0)
            ->assertJsonPath('data.period.created', 0)
            ->assertJsonCount(3, 'data.period.trend')
            ->assertJsonPath('data.period.trend.0.count', 0)
            ->assertJsonPath('data.period.trend.2.count', 0);
    }

    public function test_empty_ticket_report_returns_zeroes_instead_of_an_error(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/v1/reports/tickets');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.current.total', 0)
            ->assertJsonPath('data.current.by_status', [])
            ->assertJsonPath('data.current.by_priority', [])
            ->assertJsonPath('data.current.by_category', [])
            ->assertJsonPath('data.period', null);
    }

    public function test_ticket_report_denies_roles_without_view_reports(): void
    {
        $this->actingAs($this->warehouseStaff)
            ->getJson('/api/v1/reports/tickets')
            ->assertForbidden();
    }

    public function test_ticket_report_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/tickets')
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create($attributes + ['requester_id' => $this->staff->id]);
    }
}
