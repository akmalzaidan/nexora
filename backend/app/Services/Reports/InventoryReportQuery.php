<?php

namespace App\Services\Reports;

use App\Models\Item;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockMovementService;

/**
 * Inventory reporting aggregates.
 *
 * Source tables: `items` (catalog counts) and `stock_movements` (the journal).
 * Balances are never recomputed here: every quantity comes from
 * `StockMovementService`, which owns the one and only balance rule
 * (SUM(STOCK_IN) − SUM(STOCK_OUT)) per DR-014, so the report cannot drift from
 * the inventory module.
 *
 * Date basis: `current_stock` is a *current state* over the full journal and is
 * never date filtered; the optional `period` section is *period activity* on
 * `stock_movements.created_at` (movement count, quantity in, quantity out).
 * The two are reported separately on purpose.
 */
class InventoryReportQuery
{
    public function __construct(private readonly StockMovementService $movements) {}

    /**
     * Compact snapshot for the operational overview.
     *
     * @return array{item_count: int, warehouse_count: int, stock_quantity: int}
     */
    public function summary(): array
    {
        return [
            'item_count' => $this->itemCount(),
            'warehouse_count' => $this->warehouseCount(),
            'stock_quantity' => $this->movements->totalBalance(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(ReportPeriod $period, int $limit): array
    {
        return [
            'items' => [
                'count' => $this->itemCount(),
                'category_count' => $this->itemCategoryCount(),
            ],
            'warehouses' => [
                'count' => $this->warehouseCount(),
            ],
            'current_stock' => [
                'total_quantity' => $this->movements->totalBalance(),
                'by_warehouse' => $this->byWarehouse(),
                'by_item' => [
                    'limit' => $limit,
                    'items' => $this->byItem($limit),
                ],
            ],
            'period' => $this->period($period),
        ];
    }

    private function itemCount(): int
    {
        return Item::query()->withoutTrashed()->count();
    }

    /**
     * Distinct item categories actually referenced by non-deleted items.
     */
    private function itemCategoryCount(): int
    {
        return Item::query()->withoutTrashed()->distinct()->count('item_category_id');
    }

    private function warehouseCount(): int
    {
        return Warehouse::query()->count();
    }

    /**
     * Complete breakdown: warehouses hold a bounded set of rows.
     *
     * @return list<array{warehouse_id: int, label: string, quantity: int}>
     */
    private function byWarehouse(): array
    {
        $rows = $this->movements->balancesByWarehouse();

        if ($rows === []) {
            return [];
        }

        $names = Warehouse::query()
            ->whereIn('id', array_column($rows, 'warehouse_id'))
            ->pluck('name', 'id');

        return array_map(fn (array $row): array => [
            'warehouse_id' => $row['warehouse_id'],
            'label' => (string) $names->get($row['warehouse_id']),
            'quantity' => $row['quantity'],
        ], $rows);
    }

    /**
     * Ranked breakdown, bounded by `limit` because it scales with catalog size.
     *
     * @return list<array{item_id: int, quantity: int}>
     */
    private function byItem(int $limit): array
    {
        return $this->movements->balancesByItem($limit);
    }

    /**
     * Movement activity inside the period. Quantities are reported as positive
     * in/out totals; direction lives in `stock_movements.type` (DR-014).
     *
     * @return array{from: string, to: string, movement_count: int, stock_in_total: int, stock_out_total: int}|null
     */
    private function period(ReportPeriod $period): ?array
    {
        if ($period->isEmpty()) {
            return null;
        }

        $row = $period->constrain(StockMovement::query(), 'created_at')
            ->selectRaw(
                'COUNT(*) as movement_count, '
                .'COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) as stock_in_total, '
                .'COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) as stock_out_total',
                [StockMovement::TYPE_STOCK_IN, StockMovement::TYPE_STOCK_OUT]
            )
            ->first();

        return [
            ...$period->toArray(),
            'movement_count' => (int) ($row?->movement_count ?? 0),
            'stock_in_total' => (int) ($row?->stock_in_total ?? 0),
            'stock_out_total' => (int) ($row?->stock_out_total ?? 0),
        ];
    }
}
