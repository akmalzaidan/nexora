<?php

namespace App\Http\Requests\CommandCenter;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query contract for the Command Center snapshot.
 *
 * The endpoint is a current-state surface, so it deliberately accepts no date
 * parameters: there is no `from`/`to` window here, and asking for one is a
 * validation error rather than a silently ignored filter. Activity recency is
 * expressed by `limit` (how many of the newest events to return) and nothing
 * else — a date-filtered dashboard is the reports' job, not this endpoint's.
 *
 * `limit` bounds the queue item lists and the recent-activity list, so the
 * response size is predictable regardless of how much backlog exists. The
 * queues still report their full `count`, so a bounded list never hides the size
 * of the problem.
 */
class CommandCenterRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],

            // Declared explicitly so a period window is a 422 instead of a
            // parameter the endpoint would quietly ignore.
            'from' => ['prohibited'],
            'to' => ['prohibited'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }
}
