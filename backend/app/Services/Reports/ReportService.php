<?php

namespace App\Services\Reports;

/**
 * Read-only reporting façade.
 *
 * Owns the report contract: the overview snapshot plus one report per
 * operational module. Every metric is a server-side database aggregate over
 * existing domain tables — nothing is written, no business state is touched,
 * and no metric is invented or cached. Each domain query class documents the
 * source table, the calculation rule, and the date basis of its metrics.
 */
class ReportService
{
    public function __construct(
        private readonly AssetReportQuery $assetReports,
        private readonly InventoryReportQuery $inventoryReports,
        private readonly TicketReportQuery $ticketReports,
        private readonly MaintenanceReportQuery $maintenanceReports,
    ) {}

    /**
     * Compact current-state executive summary.
     *
     * The overview is a snapshot by definition and therefore carries no period
     * section: period metrics live on the per-module reports.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'generated_at' => now()->toISOString(),
            'assets' => $this->assetReports->summary(),
            'inventory' => $this->inventoryReports->summary(),
            'tickets' => $this->ticketReports->summary(),
            'maintenance' => $this->maintenanceReports->summary(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assets(ReportPeriod $period): array
    {
        return $this->assetReports->report($period);
    }

    /**
     * @return array<string, mixed>
     */
    public function inventory(ReportPeriod $period, int $limit): array
    {
        return $this->inventoryReports->report($period, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function tickets(ReportPeriod $period): array
    {
        return $this->ticketReports->report($period);
    }

    /**
     * @return array<string, mixed>
     */
    public function maintenance(ReportPeriod $period, int $limit): array
    {
        return $this->maintenanceReports->report($period, $limit);
    }
}
