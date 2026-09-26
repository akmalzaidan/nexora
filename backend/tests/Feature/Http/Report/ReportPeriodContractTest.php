<?php

namespace Tests\Feature\Http\Report;

use App\Http\Requests\Report\ReportRequest;
use App\Services\Reports\ReportPeriod;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The period and limit contract is shared by every detail report, so it is
 * asserted once for all four endpoints.
 */
class ReportPeriodContractTest extends ReportTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function detailEndpoints(): array
    {
        return [
            'assets' => ['/api/v1/reports/assets'],
            'inventory' => ['/api/v1/reports/inventory'],
            'tickets' => ['/api/v1/reports/tickets'],
            'maintenance' => ['/api/v1/reports/maintenance'],
        ];
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_period_is_optional_and_reported_as_null_when_absent(string $endpoint): void
    {
        $this->actingAs($this->admin)
            ->getJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.period', null);
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_period_is_all_or_nothing(string $endpoint): void
    {
        $this->actingAs($this->admin)
            ->getJson($endpoint.'?from='.now()->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->actingAs($this->admin)
            ->getJson($endpoint.'?to='.now()->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_period_must_use_the_iso_calendar_date_format(string $endpoint): void
    {
        $this->actingAs($this->admin)
            ->getJson($endpoint.'?from=01-01-2025&to=2025-01-31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_period_must_run_forwards(string $endpoint): void
    {
        $this->actingAs($this->admin)
            ->getJson($endpoint.'?from=2025-02-01&to=2025-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_single_day_period_is_accepted(string $endpoint): void
    {
        $day = now()->toDateString();

        $this->actingAs($this->admin)
            ->getJson($endpoint."?from={$day}&to={$day}")
            ->assertOk()
            ->assertJsonPath('data.period.from', $day)
            ->assertJsonPath('data.period.to', $day);
    }

    #[DataProvider('detailEndpoints')]
    public function test_a_period_may_not_exceed_the_supported_window(string $endpoint): void
    {
        $to = now()->toDateString();
        $widestAllowed = now()->subDays(ReportPeriod::MAX_DAYS - 1)->toDateString();

        $this->actingAs($this->admin)
            ->getJson($endpoint."?from={$widestAllowed}&to={$to}")
            ->assertOk();

        $this->actingAs($this->admin)
            ->getJson($endpoint.'?from='.now()->subDays(ReportPeriod::MAX_DAYS)->toDateString().'&to='.$to)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    #[DataProvider('detailEndpoints')]
    public function test_limit_must_be_a_positive_bounded_integer(string $endpoint): void
    {
        $this->actingAs($this->admin)
            ->getJson($endpoint.'?limit=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        $this->actingAs($this->admin)
            ->getJson($endpoint.'?limit=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        $this->actingAs($this->admin)
            ->getJson($endpoint.'?limit='.(ReportRequest::MAX_LIMIT + 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    public function test_limit_defaults_to_ten_and_bounds_the_ranked_sections(): void
    {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/inventory')
            ->assertOk()
            ->assertJsonPath('data.current_stock.by_item.limit', 10);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/maintenance')
            ->assertOk()
            ->assertJsonPath('data.by_asset.limit', 10)
            ->assertJsonPath('data.parts.top_items.limit', 10);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/reports/inventory?limit=100')
            ->assertOk()
            ->assertJsonPath('data.current_stock.by_item.limit', 100);
    }

    /**
     * Reports are read-only: no write verb reaches a report path.
     */
    public function test_reports_only_expose_a_read_surface(): void
    {
        foreach (array_keys(self::detailEndpoints()) as $name) {
            foreach (['post', 'put', 'patch', 'delete'] as $method) {
                $this->actingAs($this->admin)
                    ->json($method, "/api/v1/reports/{$name}")
                    ->assertStatus(405);
            }
        }
    }
}
