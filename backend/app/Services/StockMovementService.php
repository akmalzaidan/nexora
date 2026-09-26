<?php

namespace App\Services;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditResourceType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Stock movement domain logic.
 *
 * `stock_movements` is an append-only journal. The balance for an item in a
 * warehouse is always derived from this journal — never stored — so a
 * movement can never be silently lost or edited.
 */
class StockMovementService
{
    private const SORTABLE_COLUMNS = ['created_at', 'quantity'];

    public function __construct(private readonly AuditLogService $auditLogs) {}

    /**
     * @param  array{item_id?: int|null, warehouse_id?: int|null, type?: string|null, sort?: string, direction?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<StockMovement>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = StockMovement::query()
            ->with(['item', 'warehouse', 'performer']);

        if (! empty($filters['item_id'])) {
            $query->where('item_id', (int) $filters['item_id']);
        }

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', (string) $filters['type']);
        }

        $sort = in_array($filters['sort'] ?? 'created_at', self::SORTABLE_COLUMNS, true)
            ? ($filters['sort'] ?? 'created_at')
            : 'created_at';
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        return $query->paginate($this->perPage($filters['per_page'] ?? null))->withQueryString();
    }

    /**
     * Record a movement inside a transaction.
     *
     * The item row is locked for the duration of the transaction so two
     * concurrent movements for the same item serialize and the balance check
     * stays correct. A STOCK_OUT is rejected with 422 when it would drive the
     * balance below zero.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $performedBy): StockMovement
    {
        return DB::transaction(function () use ($data, $performedBy): StockMovement {
            $item = Item::query()
                ->lockForUpdate()
                ->withoutTrashed()
                ->findOrFail((int) $data['item_id']);
            $warehouse = Warehouse::query()
                ->lockForUpdate()
                ->findOrFail((int) $data['warehouse_id']);

            if ($data['type'] === StockMovement::TYPE_STOCK_OUT) {
                $balance = $this->balance($item->id, $warehouse->id);

                if ($balance < (int) $data['quantity']) {
                    throw new UnprocessableEntityHttpException('Insufficient stock for this movement.');
                }
            }

            $movement = StockMovement::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'type' => $data['type'],
                'quantity' => (int) $data['quantity'],
                'reference_type' => $data['reference_type'] ?? null,
                'reference_id' => $data['reference_id'] ?? null,
                'performed_by' => $performedBy,
                'notes' => $data['notes'] ?? null,
            ]);

            // Inside the same transaction as the journal insert (DR-020 §12):
            // a rollback removes both the movement and its audit row.
            $this->auditLogs->recordEvent(
                AuditAction::CREATED,
                AuditResourceType::STOCK_MOVEMENT,
                $movement->id,
                User::find($performedBy),
                "Stock {$data['type']} of {$movement->quantity} x {$item->sku} in {$warehouse->code}",
                newValues: [
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'type' => $data['type'],
                    'quantity' => (int) $data['quantity'],
                ],
            );

            return $movement;
        });
    }

    /**
     * Current signed balance for an item in a warehouse, derived from the
     * journal: STOCK_IN adds, STOCK_OUT subtracts.
     */
    public function balance(int $itemId, int $warehouseId): int
    {
        $row = StockMovement::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE -quantity END), 0) AS balance', [StockMovement::TYPE_STOCK_IN])
            ->first();

        return (int) $row->balance;
    }

    /**
     * Current signed balance per warehouse, derived from the journal with the
     * same rule as balance(): STOCK_IN adds, STOCK_OUT subtracts.
     *
     * Only warehouses that currently hold a non-zero balance are returned, and
     * the result is ordered by balance descending then id ascending so callers
     * get a deterministic ranking.
     *
     * @return list<array{warehouse_id: int, quantity: int}>
     */
    public function balancesByWarehouse(?int $limit = null): array
    {
        return $this->balanceRows('warehouse_id', $limit);
    }

    /**
     * Current signed balance per item, derived with the same rule as balance().
     *
     * @return list<array{item_id: int, quantity: int}>
     */
    public function balancesByItem(?int $limit = null): array
    {
        return $this->balanceRows('item_id', $limit);
    }

    /**
     * Signed balance across the whole journal — the current total quantity held
     * in every warehouse for every item.
     */
    public function totalBalance(): int
    {
        $row = StockMovement::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE -quantity END), 0) AS balance', [StockMovement::TYPE_STOCK_IN])
            ->first();

        return (int) $row->balance;
    }

    /**
     * @return list<array<string, int>>
     */
    private function balanceRows(string $column, ?int $limit): array
    {
        $query = StockMovement::query()
            ->selectRaw("{$column}, COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE -quantity END), 0) AS balance", [StockMovement::TYPE_STOCK_IN])
            ->groupBy($column)
            ->havingRaw('balance <> 0')
            ->orderByDesc('balance')
            ->orderBy($column);

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        return $query->get()
            ->map(fn (StockMovement $movement): array => [
                $column => (int) $movement->{$column},
                'quantity' => (int) $movement->balance,
            ])
            ->all();
    }

    private function perPage(?int $requested): int
    {
        return max(1, min(100, (int) (is_numeric($requested) ? $requested : 15)));
    }
}
