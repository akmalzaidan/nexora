<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;

/**
 * The SQL expression that truncates a timestamp column to a calendar day.
 *
 * Day truncation is not portable SQL: PostgreSQL exposes `col::date` while
 * SQLite and MySQL expose `date(col)`. The choice lives in exactly one place so
 * the reporting layer itself stays database agnostic and every trend shares the
 * same bucket semantics. Supported drivers: pgsql, sqlite, mysql, mariadb.
 */
final class ReportDayBucket
{
    public static function expression(string $column, ?string $driver = null): string
    {
        $driver ??= DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => "{$column}::date",
            default => "date({$column})",
        };
    }
}
