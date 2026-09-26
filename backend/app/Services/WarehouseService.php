<?php

namespace App\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Warehouse domain logic: querying, create/update/delete.
 */
class WarehouseService
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    private const SORTABLE_COLUMNS = ['name', 'code', 'created_at'];

    /**
     * @param  array{search?: string|null, location_id?: int|null, is_active?: bool, sort?: string, direction?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<Warehouse>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Warehouse::query()->with(['location']);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->whereLike('name', "%{$search}%")
                    ->orWhereLike('code', "%{$search}%");
            });
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $sort = in_array($filters['sort'] ?? 'name', self::SORTABLE_COLUMNS, true)
            ? ($filters['sort'] ?? 'name')
            : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Warehouse
    {
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        $warehouse = Warehouse::create($data);

        $this->auditLogs->recordCreated(
            AuditResourceType::WAREHOUSE,
            $warehouse->id,
            $actor ?? auth()->user(),
            "Warehouse {$warehouse->name} ({$warehouse->code}) created",
            newValues: ['name' => $warehouse->name, 'code' => $warehouse->code],
        );

        return $warehouse;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Warehouse $warehouse, array $data, ?User $actor = null): Warehouse
    {
        $data = array_key_exists('is_active', $data)
            ? [...$data, 'is_active' => (bool) $data['is_active']]
            : $data;

        $old = ['name' => $warehouse->name, 'code' => $warehouse->code];

        $warehouse->update($data);

        $new = ['name' => $warehouse->name, 'code' => $warehouse->code];

        if ($old !== $new) {
            $this->auditLogs->recordUpdated(
                AuditResourceType::WAREHOUSE,
                $warehouse->id,
                $actor ?? auth()->user(),
                "Warehouse {$warehouse->name} ({$warehouse->code}) updated",
                oldValues: $old,
                newValues: $new,
            );
        }

        return $warehouse->refresh();
    }

    public function delete(Warehouse $warehouse, ?User $actor = null): void
    {
        if ($warehouse->stockMovements()->exists()) {
            abort(409, 'Warehouse cannot be deleted because it has stock movements');
        }

        $audited = ['name' => $warehouse->name, 'code' => $warehouse->code];

        $warehouse->delete();

        $this->auditLogs->recordDeleted(
            AuditResourceType::WAREHOUSE,
            $warehouse->id,
            $actor ?? auth()->user(),
            "Warehouse {$warehouse->name} ({$warehouse->code}) deleted",
            oldValues: $audited,
        );
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
