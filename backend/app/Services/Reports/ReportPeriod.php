<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A validated reporting period shared by every report endpoint.
 *
 * The period is either fully absent (current-state metrics only) or a complete
 * `from`/`to` pair of calendar days; `ReportRequest` enforces that contract and
 * the 422 wording, so this object only carries already-validated bounds and the
 * query classes never re-parse raw input.
 *
 * Both bounds are inclusive calendar days in the application timezone
 * (`config('app.timezone')`), which is the timezone the domain timestamps are
 * stored in — no report query hardcodes a timezone.
 */
final class ReportPeriod
{
    /**
     * Maximum inclusive number of days one report period may span. Keeps the
     * daily trend series (and every period aggregate) bounded no matter how
     * wide a range the client asks for.
     */
    public const MAX_DAYS = 366;

    private function __construct(
        private readonly ?CarbonImmutable $from,
        private readonly ?CarbonImmutable $to,
    ) {}

    public static function make(?string $from, ?string $to): self
    {
        return new self(self::date($from), self::date($to));
    }

    /**
     * No period was requested: only current-state metrics are produced.
     */
    public function isEmpty(): bool
    {
        return $this->from === null;
    }

    /**
     * The echoed period bounds, or null when the report is current-state only.
     *
     * @return array{from: string, to: string}|null
     */
    public function toArray(): ?array
    {
        if ($this->isEmpty()) {
            return null;
        }

        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }

    /**
     * Restrict a timestamp column to the period, inclusive on both bounds.
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function constrain(Builder $query, string $column): Builder
    {
        if ($this->isEmpty()) {
            return $query;
        }

        return $query->whereDate($column, '>=', $this->from->toDateString())
            ->whereDate($column, '<=', $this->to->toDateString());
    }

    /**
     * Every calendar day covered by the period, ascending and gap free, so a
     * daily trend can be charted without the client filling missing days.
     *
     * @return list<CarbonImmutable>
     */
    public function days(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $days = [];
        $day = $this->from;

        while ($day->lessThanOrEqualTo($this->to)) {
            $days[] = $day;
            $day = $day->addDay();
        }

        return $days;
    }

    /**
     * Inclusive day count between two `Y-m-d` dates.
     */
    public static function daysBetween(string $from, string $to): int
    {
        return self::date($from)->diffInDays(self::date($to)) + 1;
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== ''
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value)
            : null;
    }
}
