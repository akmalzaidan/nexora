<?php

namespace App\Services;

use App\Models\ItemCategory;
use App\Models\User;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Item category domain logic: querying, create/update/delete.
 */
class ItemCategoryService
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    private const SORTABLE_COLUMNS = ['name', 'code', 'created_at'];

    /**
     * @param  array{search?: string|null, sort?: string, direction?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<ItemCategory>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = ItemCategory::query()->withCount('items');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->whereLike('name', "%{$search}%")
                    ->orWhereLike('code', "%{$search}%");
            });
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
    public function create(array $data, ?User $actor = null): ItemCategory
    {
        $itemCategory = ItemCategory::create($data);

        $this->auditLogs->recordCreated(
            AuditResourceType::ITEM_CATEGORY,
            $itemCategory->id,
            $actor ?? auth()->user(),
            "Item category {$itemCategory->name} created",
            newValues: ['name' => $itemCategory->name, 'code' => $itemCategory->code],
        );

        return $itemCategory;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ItemCategory $itemCategory, array $data, ?User $actor = null): ItemCategory
    {
        $old = ['name' => $itemCategory->name, 'code' => $itemCategory->code];

        $itemCategory->update($data);

        $new = ['name' => $itemCategory->name, 'code' => $itemCategory->code];

        if ($old !== $new) {
            $this->auditLogs->recordUpdated(
                AuditResourceType::ITEM_CATEGORY,
                $itemCategory->id,
                $actor ?? auth()->user(),
                "Item category {$itemCategory->name} updated",
                oldValues: $old,
                newValues: $new,
            );
        }

        return $itemCategory->refresh();
    }

    public function delete(ItemCategory $itemCategory, ?User $actor = null): void
    {
        if ($itemCategory->items()->exists()) {
            abort(409, 'Item category cannot be deleted because it is still in use');
        }

        $audited = ['name' => $itemCategory->name, 'code' => $itemCategory->code];

        $itemCategory->delete();

        $this->auditLogs->recordDeleted(
            AuditResourceType::ITEM_CATEGORY,
            $itemCategory->id,
            $actor ?? auth()->user(),
            "Item category {$itemCategory->name} deleted",
            oldValues: $audited,
        );
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
