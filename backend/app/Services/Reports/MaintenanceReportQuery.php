<?php

namespace App\Services\Reports;

use App\Models\MaintenancePart;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;

/**
 * Maintenance reporting aggregates.
 *
 * Source tables: `maintenance_requests` (backlog/workload),
 * `maintenance_records` (executed work and its cost), and `maintenance_parts`
 * (parts trace). Money is aggregated in SQL at decimal precision over the
 * `cost` column and only normalised to the string form the API already uses for
 * money (`MaintenanceRecordResource`).
 *
 * Parts are trace-only rows: they are **not** inventory consumption. No stock
 * movement is created by a part, so the report never presents them as a stock
 * delta and never derives a balance from them (DR-016).
 *
 * Date basis: the optional period applies to the request lifecycle timestamps
 * (`requested_at`, `approved_at`, `completed_at`) and is reported separately
 * from the current-state sections.
 */
class MaintenanceReportQuery
{
    /**
     * Compact snapshot for the operational overview.
     *
     * @return array{total: int, by_status: list<array{status: string, count: int}>}
     */
    public function summary(): array
    {
        $byStatus = ReportBreakdown::statuses(MaintenanceRequest::query());

        return [
            'total' => ReportBreakdown::total($byStatus),
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(ReportPeriod $period, int $limit): array
    {
        return [
            'requests' => $this->requests(),
            'records' => $this->records(),
            'parts' => $this->parts($limit),
            'by_asset' => [
                'limit' => $limit,
                'items' => $this->byAsset($limit),
            ],
            'period' => $this->period($period),
        ];
    }

    /**
     * @return array{total: int, by_status: list<array{status: string, count: int}>, by_priority: list<array{priority: string, count: int}>}
     */
    private function requests(): array
    {
        $byStatus = ReportBreakdown::statuses(MaintenanceRequest::query());

        return [
            'total' => ReportBreakdown::total($byStatus),
            'by_status' => $byStatus,
            'by_priority' => ReportBreakdown::priorities(MaintenanceRequest::query()),
        ];
    }

    /**
     * `average_cost` is null when no record carries a cost, because an average
     * over zero samples is undefined; `total_cost` over no rows is a hard 0.
     *
     * @return array{count: int, costed_count: int, total_cost: string, average_cost: string|null}
     */
    private function records(): array
    {
        $row = MaintenanceRecord::query()
            ->selectRaw('COUNT(*) as total, COUNT(cost) as costed, COALESCE(SUM(cost), 0) as total_cost, AVG(cost) as average_cost')
            ->first();

        $costed = (int) ($row?->costed ?? 0);

        return [
            'count' => (int) ($row?->total ?? 0),
            'costed_count' => $costed,
            'total_cost' => $this->money($row?->total_cost),
            'average_cost' => $costed === 0 ? null : $this->money($row?->average_cost),
        ];
    }

    /**
     * Parts usage, ranked by total quantity and bounded by `limit`.
     *
     * @return array{usage_count: int, top_items: array{limit: int, items: list<array{item_id: int, sku: string, label: string, quantity: int, usage_count: int}>}}
     */
    private function parts(int $limit): array
    {
        $rows = MaintenancePart::query()
            ->join('items', 'items.id', '=', 'maintenance_parts.item_id')
            ->selectRaw(
                'items.id as item_id, items.sku as sku, items.name as label, '
                .'SUM(maintenance_parts.quantity) as total_quantity, COUNT(*) as usage_count'
            )
            ->groupBy('items.id', 'items.sku', 'items.name')
            ->orderByDesc('total_quantity')
            ->orderBy('sku')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'item_id' => (int) $row->item_id,
                'sku' => (string) $row->sku,
                'label' => (string) $row->label,
                'quantity' => (int) $row->total_quantity,
                'usage_count' => (int) $row->usage_count,
            ])
            ->all();

        return [
            'usage_count' => MaintenancePart::query()->count(),
            'top_items' => [
                'limit' => $limit,
                'items' => $rows,
            ],
        ];
    }

    /**
     * Maintenance requests per asset — a repeat workload fact, bounded by
     * `limit` because it scales with the asset base. Assets are reported as
     * recorded; a soft-deleted asset keeps its maintenance history visible.
     *
     * @return list<array{asset_id: int, asset_code: string, asset_name: string, count: int}>
     */
    private function byAsset(int $limit): array
    {
        return MaintenanceRequest::query()
            ->join('assets', 'assets.id', '=', 'maintenance_requests.asset_id')
            ->selectRaw('assets.id as asset_id, assets.asset_code as asset_code, assets.name as asset_name, COUNT(*) as total')
            ->groupBy('assets.id', 'assets.asset_code', 'assets.name')
            ->orderByDesc('total')
            ->orderBy('asset_code')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'asset_id' => (int) $row->asset_id,
                'asset_code' => (string) $row->asset_code,
                'asset_name' => (string) $row->asset_name,
                'count' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * @return array{from: string, to: string, requested: int, approved: int, completed: int}|null
     */
    private function period(ReportPeriod $period): ?array
    {
        if ($period->isEmpty()) {
            return null;
        }

        return [
            ...$period->toArray(),
            'requested' => $period->constrain(MaintenanceRequest::query(), 'requested_at')->count(),
            'approved' => $period->constrain(MaintenanceRequest::query(), 'approved_at')->count(),
            'completed' => $period->constrain(MaintenanceRequest::query(), 'completed_at')->count(),
        ];
    }

    /**
     * The aggregate was already computed in SQL at decimal precision; this only
     * normalises the driver-specific scalar into the two-decimal string form the
     * API uses for money everywhere else.
     */
    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
