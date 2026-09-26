<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read side of the governance audit trail (Phase 20A).
 *
 * Strictly read-only: the only public method is paginate(). Filters map
 * 1:1 onto the existing `audit_logs` schema — nothing is inferred, no join is
 * performed beyond the eager-loaded actor, and every value that reaches the
 * query is whitelisted or cast to a safe type:
 *  - `action` and `resource_type` are validated against their controlled
 *    vocabularies (unknown values are ignored, never injected);
 *  - actor/resource ids are cast to int;
 *  - `from`/`to` are inclusive calendar days on `created_at` (same inclusive
 *    date convention as the Reports API, DR-018).
 *
 * Sort columns are whitelisted (`created_at | action | resource_type`) and the
 * direction is reduced to asc/desc; the default is newest-first, with an `id`
 * tiebreak so equal timestamps keep a stable order.
 */
class AuditLogQuery
{
    private const SORTABLE_COLUMNS = ['created_at', 'action', 'resource_type'];

    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array{actor_id?: int|string|null, action?: string|null, resource_type?: string|null, resource_id?: int|string|null, from?: string|null, to?: string|null, sort?: string|null, direction?: string|null, per_page?: int|string|null}  $filters
     * @return LengthAwarePaginator<AuditLog>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = AuditLog::query()->with('user');

        if (isset($filters['actor_id']) && $filters['actor_id'] !== null && $filters['actor_id'] !== '') {
            $query->where('user_id', (int) $filters['actor_id']);
        }

        if (! empty($filters['action']) && in_array($filters['action'], AuditAction::all(), true)) {
            $query->where('action', (string) $filters['action']);
        }

        if (! empty($filters['resource_type'])
            && in_array($filters['resource_type'], AuditResourceType::all(), true)) {
            $query->where('entity_type', (string) $filters['resource_type']);
        }

        if (isset($filters['resource_id']) && $filters['resource_id'] !== null && $filters['resource_id'] !== '') {
            $query->where('entity_id', (int) $filters['resource_id']);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['to']);
        }

        $requestedSort = str_replace('-', '_', (string) ($filters['sort'] ?? 'created_at'));
        $sort = in_array($requestedSort, self::SORTABLE_COLUMNS, true) ? $requestedSort : 'created_at';
        // The schema column is entity_type; the API contract calls it resource_type.
        $sortColumn = $sort === 'resource_type' ? 'entity_type' : $sort;
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortColumn, $direction)->orderBy('id', 'desc');

        return $query
            ->paginate($this->perPage($filters['per_page'] ?? null))
            ->withQueryString();
    }

    private function perPage(mixed $requested): int
    {
        if (is_numeric($requested)) {
            return max(1, min(self::MAX_PER_PAGE, (int) $requested));
        }

        return self::DEFAULT_PER_PAGE;
    }
}
