<?php

namespace App\Services\Reports;

use App\Models\Ticket;
use Carbon\CarbonImmutable;

/**
 * Helpdesk reporting aggregates.
 *
 * Source table: `tickets` (with `ticket_categories` for the labelled
 * breakdown). There is no soft delete on tickets — a ticket is an operational
 * record, never removed (DR-015).
 *
 * Date basis: the optional period applies to `tickets.created_at` and produces
 * the creation trend. The `current` section is a state snapshot over every
 * ticket and is never date filtered, so "open right now" can never be confused
 * with "opened during the period".
 */
class TicketReportQuery
{
    /**
     * Compact snapshot for the operational overview.
     *
     * @return array{total: int, by_status: list<array{status: string, count: int}>}
     */
    public function summary(): array
    {
        $byStatus = ReportBreakdown::statuses(Ticket::query());

        return [
            'total' => ReportBreakdown::total($byStatus),
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(ReportPeriod $period): array
    {
        return [
            'current' => $this->current(),
            'period' => $this->period($period),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function current(): array
    {
        $byStatus = ReportBreakdown::statuses(Ticket::query());

        return [
            'total' => ReportBreakdown::total($byStatus),
            'by_status' => $byStatus,
            'by_priority' => ReportBreakdown::priorities(Ticket::query()),
            'by_category' => $this->byCategory(),
        ];
    }

    /**
     * Tickets with no category are excluded from the labelled breakdown (the
     * join cannot match a null `category_id`); they are still counted in
     * `current.total`.
     *
     * @return list<array{category_id: int, label: string, count: int}>
     */
    private function byCategory(): array
    {
        return Ticket::query()
            ->join('ticket_categories', 'ticket_categories.id', '=', 'tickets.category_id')
            ->selectRaw('ticket_categories.id as category_id, ticket_categories.name as label, COUNT(*) as total')
            ->groupBy('ticket_categories.id', 'ticket_categories.name')
            ->orderByDesc('total')
            ->orderBy('label')
            ->get()
            ->map(fn ($row): array => [
                'category_id' => (int) $row->category_id,
                'label' => (string) $row->label,
                'count' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * @return array{from: string, to: string, created: int, trend: list<array{date: string, count: int}>}|null
     */
    private function period(ReportPeriod $period): ?array
    {
        if ($period->isEmpty()) {
            return null;
        }

        return [
            ...$period->toArray(),
            'created' => $period->constrain(Ticket::query(), 'created_at')->count(),
            'trend' => $this->trend($period),
        ];
    }

    /**
     * Daily creation counts for the period, gap filled so the series can be
     * charted directly. Bucketing happens in SQL through the one driver-aware
     * day expression, never by loading rows into PHP.
     *
     * @return list<array{date: string, count: int}>
     */
    private function trend(ReportPeriod $period): array
    {
        $day = ReportDayBucket::expression('created_at');

        $counts = $period->constrain(Ticket::query(), 'created_at')
            ->selectRaw("{$day} as bucket, COUNT(*) as total")
            ->groupByRaw($day)
            ->orderByRaw($day)
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->bucket => (int) $row->total])
            ->all();

        return array_map(fn (CarbonImmutable $day): array => [
            'date' => $day->toDateString(),
            'count' => $counts[$day->toDateString()] ?? 0,
        ], $period->days());
    }
}
