<?php

namespace App\Services\Reports;

use App\Models\Asset;
use App\Models\AssetAssignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Asset reporting aggregates.
 *
 * Source tables: `assets` (status, category, location, assignment counters) and
 * `asset_assignments` (assignment lifecycle). Soft-deleted assets are excluded,
 * matching `AssetService`.
 *
 * Date basis: the optional period applies to `assets.created_at` (assets added)
 * and `asset_assignments.assigned_at` / `returned_at` (handover activity). Every
 * other section is a current-state snapshot of the whole table and is never date
 * filtered — current state and period activity are always separate.
 *
 * No metric here implies interpretation: counts are facts, and an asset is
 * never labelled healthy, idle, or problematic.
 */
class AssetReportQuery
{
    /**
     * Compact snapshot for the operational overview.
     *
     * @return array{total: int, by_status: list<array{status: string, count: int}>}
     */
    public function summary(): array
    {
        $byStatus = ReportBreakdown::statuses($this->assets());

        return [
            'total' => ReportBreakdown::total($byStatus),
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(ReportPeriod $period): array
    {
        return [
            'current' => $this->current(),
            'assignments' => $this->assignments(),
            'period' => $this->period($period),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function current(): array
    {
        $counters = $this->assets()
            ->selectRaw(
                'COUNT(*) as total, '
                .'COALESCE(SUM(CASE WHEN current_user_id IS NULL THEN 1 ELSE 0 END), 0) as unassigned, '
                .'COALESCE(SUM(CASE WHEN location_id IS NULL THEN 1 ELSE 0 END), 0) as without_location'
            )
            ->first();

        $total = (int) ($counters?->total ?? 0);
        $unassigned = (int) ($counters?->unassigned ?? 0);

        return [
            'total' => $total,
            'by_status' => ReportBreakdown::statuses($this->assets()),
            'by_category' => $this->byCategory(),
            'by_location' => $this->byLocation(),
            'assigned_count' => $total - $unassigned,
            'unassigned_count' => $unassigned,
            'without_location_count' => (int) ($counters?->without_location ?? 0),
        ];
    }

    /**
     * @return list<array{category_id: int, label: string, count: int}>
     */
    private function byCategory(): array
    {
        return $this->assets()
            ->join('asset_categories', 'asset_categories.id', '=', 'assets.asset_category_id')
            ->selectRaw('asset_categories.id as category_id, asset_categories.name as label, COUNT(*) as total')
            ->groupBy('asset_categories.id', 'asset_categories.name')
            ->orderByDesc('total')
            ->orderBy('label')
            ->get()
            ->map(fn ($row): array => [
                'category_id' => (int) $row->category_id,
                'label' => (string) $row->label,
                'count' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Assets are counted only where an asset is actually linked to the
     * location; location is never inferred from a user's department.
     *
     * @return list<array{location_id: int, label: string, count: int}>
     */
    private function byLocation(): array
    {
        return $this->assets()
            ->whereNotNull('assets.location_id')
            ->join('locations', 'locations.id', '=', 'assets.location_id')
            ->selectRaw('locations.id as location_id, locations.name as label, COUNT(*) as total')
            ->groupBy('locations.id', 'locations.name')
            ->orderByDesc('total')
            ->orderBy('label')
            ->get()
            ->map(fn ($row): array => [
                'location_id' => (int) $row->location_id,
                'label' => (string) $row->label,
                'count' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Assignment rows grouped by their lifecycle status (`ACTIVE`, `RETURNED`,
     * ...). Values come straight from `asset_assignments.status`.
     *
     * @return array{total: int, by_status: list<array{status: string, count: int}>}
     */
    private function assignments(): array
    {
        $byStatus = ReportBreakdown::statuses(AssetAssignment::query());

        return [
            'total' => ReportBreakdown::total($byStatus),
            'by_status' => $byStatus,
        ];
    }

    /**
     * @return array{from: string, to: string, assets_created: int, assignments_assigned: int, assignments_returned: int}|null
     */
    private function period(ReportPeriod $period): ?array
    {
        if ($period->isEmpty()) {
            return null;
        }

        return [
            ...$period->toArray(),
            'assets_created' => $period->constrain($this->assets(), 'created_at')->count(),
            'assignments_assigned' => $period->constrain(AssetAssignment::query(), 'assigned_at')->count(),
            'assignments_returned' => $period->constrain(AssetAssignment::query(), 'returned_at')->count(),
        ];
    }

    private function assets(): Builder
    {
        return Asset::query()->withoutTrashed();
    }
}
