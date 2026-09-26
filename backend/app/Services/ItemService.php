<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Item domain logic: querying, create/update/delete, and stock totals derived
 * from the movement journal (never stored as a denormalized balance).
 */
class ItemService
{
    private const AUDITABLE_FIELDS = ['sku', 'name', 'item_category_id', 'is_active'];

    private const SORTABLE_COLUMNS = ['name', 'sku', 'created_at'];

    public function __construct(private readonly AuditLogService $auditLogs) {}

    /**
     * @param  array{search?: string|null, item_category_id?: int|null, warehouse_id?: int|null, is_active?: bool, sort?: string, direction?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<Item>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Item::query()
            ->with(['category'])
            ->withoutTrashed()
            ->withSum(['stockMovements as stock_in_total' => fn ($q) => $q->where('type', StockMovement::TYPE_STOCK_IN)], 'quantity')
            ->withSum(['stockMovements as stock_out_total' => fn ($q) => $q->where('type', StockMovement::TYPE_STOCK_OUT)], 'quantity');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->whereLike('sku', "%{$search}%")
                    ->orWhereLike('name', "%{$search}%");
            });
        }

        if (! empty($filters['item_category_id'])) {
            $query->where('item_category_id', (int) $filters['item_category_id']);
        }

        if (! empty($filters['warehouse_id'])) {
            $query->whereHas('stockMovements', fn ($q) => $q->where('warehouse_id', (int) $filters['warehouse_id']));
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
     * Load the relationships and derived stock totals needed by the detail
     * resource, including the per-warehouse breakdown.
     */
    public function show(Item $item): Item
    {
        $item->load(['category']);
        $item->stock_in_total = (int) $item->stockMovements()
            ->where('type', StockMovement::TYPE_STOCK_IN)
            ->sum('quantity');
        $item->stock_out_total = (int) $item->stockMovements()
            ->where('type', StockMovement::TYPE_STOCK_OUT)
            ->sum('quantity');
        $item->stock_warehouses = $this->stockByWarehouse($item);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Item
    {
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        $item = Item::create($data);

        $this->auditLogs->recordCreated(
            AuditResourceType::ITEM,
            $item->id,
            $actor ?? auth()->user(),
            "Item {$item->name} ({$item->sku}) created",
            newValues: $this->auditedAttributes($item),
        );

        return $item;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Item $item, array $data, ?User $actor = null): Item
    {
        $data = array_key_exists('is_active', $data)
            ? [...$data, 'is_active' => (bool) $data['is_active']]
            : $data;

        $changes = $this->auditedChanges($item, $data);

        $item->update($data);

        if ($changes !== []) {
            $this->auditLogs->recordUpdated(
                AuditResourceType::ITEM,
                $item->id,
                $actor ?? auth()->user(),
                "Item {$item->name} ({$item->sku}) updated",
                oldValues: $changes['old_values'],
                newValues: $changes['new_values'],
            );
        }

        return $item->refresh();
    }

    public function delete(Item $item, ?User $actor = null): void
    {
        if ($item->stockMovements()->exists()) {
            abort(409, 'Item cannot be deleted because it has stock movements');
        }

        if ($item->maintenanceParts()->exists()) {
            abort(409, 'Item cannot be deleted because it is used in maintenance records');
        }

        $audited = $this->auditedAttributes($item);

        $item->delete();

        $this->auditLogs->recordDeleted(
            AuditResourceType::ITEM,
            $item->id,
            $actor ?? auth()->user(),
            "Item {$item->name} ({$item->sku}) deleted",
            oldValues: $audited,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function auditedAttributes(Item $item): array
    {
        $attributes = [];

        foreach (self::AUDITABLE_FIELDS as $field) {
            $attributes[$field] = $item->getAttribute($field);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{old_values: array<string, mixed>, new_values: array<string, mixed>}|array<empty, empty>
     */
    private function auditedChanges(Item $item, array $data): array
    {
        $old = [];
        $new = [];

        foreach ($data as $field => $value) {
            if (! in_array($field, self::AUDITABLE_FIELDS, true)) {
                continue;
            }

            if ($item->getAttribute($field) === $value) {
                continue;
            }

            $old[$field] = $item->getAttribute($field);
            $new[$field] = $value;
        }

        return $old === [] ? [] : ['old_values' => $old, 'new_values' => $new];
    }

    /**
     * Signed stock balance per warehouse for an item.
     *
     * @return list<array{warehouse: array{id: int, name: string, code: string}, quantity: int}>
     */
    private function stockByWarehouse(Item $item): array
    {
        $rows = StockMovement::query()
            ->where('item_id', $item->id)
            ->selectRaw('warehouse_id, SUM(CASE WHEN type = ? THEN quantity ELSE -quantity END) AS balance', [StockMovement::TYPE_STOCK_IN])
            ->groupBy('warehouse_id')
            ->havingRaw('balance <> 0')
            ->pluck('balance', 'warehouse_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $warehouses = Warehouse::whereIn('id', $rows->keys())
            ->get(['id', 'name', 'code']);

        return $rows
            ->map(fn ($balance, $warehouseId) => [
                'warehouse' => $warehouses->firstWhere('id', (int) $warehouseId)?->only(['id', 'name', 'code']) ?? ['id' => (int) $warehouseId, 'name' => null, 'code' => null],
                'quantity' => (int) $balance,
            ])
            ->values()
            ->all();
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
