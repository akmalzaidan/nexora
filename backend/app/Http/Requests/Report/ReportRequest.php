<?php

namespace App\Http\Requests\Report;

use App\Services\Reports\ReportPeriod;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared query contract for every report endpoint.
 *
 * `from`/`to` are inclusive `Y-m-d` calendar days in the application timezone.
 * A period is all-or-nothing: sending only one bound is rejected rather than
 * silently defaulted, and a period may not span more than
 * `ReportPeriod::MAX_DAYS` days so a daily trend stays bounded. Invalid input
 * returns 422 through the standard exception renderer.
 *
 * `limit` bounds only the rankings that scale with row count
 * (`inventory.current_stock.by_item`, `maintenance.by_asset`,
 * `maintenance.parts.top_items`); breakdowns over reference dimensions
 * (status, priority, category, location, warehouse) are always complete and
 * ignore it.
 */
class ReportRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = $this->input('from');
                $to = $this->input('to');
                $hasFrom = is_string($from) && $from !== '';
                $hasTo = is_string($to) && $to !== '';

                if ($hasFrom !== $hasTo) {
                    $validator->errors()->add(
                        $hasFrom ? 'to' : 'from',
                        'Provide both from and to to request a report period, or neither for current-state metrics only.'
                    );

                    return;
                }

                if (! $hasFrom) {
                    return;
                }

                if ($from > $to) {
                    $validator->errors()->add('to', 'The from date must be on or before the to date.');

                    return;
                }

                if (ReportPeriod::daysBetween($from, $to) > ReportPeriod::MAX_DAYS) {
                    $validator->errors()->add('to', 'The report period may not exceed '.ReportPeriod::MAX_DAYS.' days.');
                }
            },
        ];
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::make($this->validated('from'), $this->validated('to'));
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }
}
