<?php

namespace App\Services\Reports;

use Illuminate\Database\Eloquent\Builder;

/**
 * Shared aggregate shapes for the report breakdowns.
 *
 * Every breakdown is a list of `{domain value, count}` rows sorted by a
 * deterministic key, so repeated calls return byte-identical payloads. Values
 * are reported exactly as stored — status and priority vocabulary is never
 * renamed inside the API, and only statuses that actually exist are emitted, so
 * no metric can be invented or hidden.
 */
final class ReportBreakdown
{
    /**
     * @return list<array{status: string, count: int}>
     */
    public static function statuses(Builder $query, string $column = 'status'): array
    {
        return self::counts($query, $column, 'status');
    }

    /**
     * @return list<array{priority: string, count: int}>
     */
    public static function priorities(Builder $query, string $column = 'priority'): array
    {
        return self::counts($query, $column, 'priority');
    }

    /**
     * @param  list<array<string, mixed>>  $breakdown
     */
    public static function total(array $breakdown): int
    {
        return array_sum(array_column($breakdown, 'count'));
    }

    /**
     * @param  Builder  $query  already scoped by the caller (soft deletes, etc.)
     * @return list<array<string, int|string>>
     */
    private static function counts(Builder $query, string $column, string $key): array
    {
        return $query
            ->selectRaw("{$column} as bucket, COUNT(*) as total")
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn ($row): array => [
                $key => (string) $row->bucket,
                'count' => (int) $row->total,
            ])
            ->all();
    }
}
