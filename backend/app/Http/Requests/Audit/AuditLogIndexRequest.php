<?php

namespace App\Http\Requests\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query contract for the read-only audit log API (Phase 20A).
 *
 * Filters mirror the `audit_logs` schema: actor, action, resource type/id and
 * an inclusive `from`/`to` calendar-day range on `created_at` (same inclusive
 * date convention as the Reports API, DR-018). Date bounds are all-or-nothing
 * and may span at most 366 days so a listing stays bounded. `sort` is
 * whitelisted in the query service; `per_page` is clamped to 1..100 (default
 * 25) — a somewhat larger default than operational resources because audit
 * review is a scanning workflow, permitted by the project's 1..100 clamp
 * convention.
 */
class AuditLogIndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public const MAX_PERIOD_DAYS = 366;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:50'],
            'resource_type' => ['nullable', 'string', 'max:100'],
            'resource_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', 'string', 'max:20'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
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
                        'Provide both from and to to filter by period, or neither to browse all audit logs.'
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

                $days = CarbonImmutable::createFromFormat('!Y-m-d', $from)
                    ->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $to)) + 1;

                if ($days > self::MAX_PERIOD_DAYS) {
                    $validator->errors()->add('to', 'The audit period may not exceed '.self::MAX_PERIOD_DAYS.' days.');
                }
            },
        ];
    }

    /**
     * Normalized filter payload for AuditLogQuery::paginate().
     *
     * @return array{actor_id: int|null, action: string|null, resource_type: string|null, resource_id: int|null, from: string|null, to: string|null, sort: string|null, direction: string|null, per_page: int}
     */
    public function filters(): array
    {
        return [
            'actor_id' => $this->validated('actor_id'),
            'action' => $this->validated('action'),
            'resource_type' => $this->validated('resource_type'),
            'resource_id' => $this->validated('resource_id'),
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
            'sort' => $this->validated('sort'),
            'direction' => $this->validated('direction'),
            'per_page' => (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE),
        ];
    }
}
