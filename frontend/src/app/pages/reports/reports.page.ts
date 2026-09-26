import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { Observable } from 'rxjs';

import { ApiResponse } from '../../core/services/api.service';
import {
  AssetReport,
  CategoryCount,
  InventoryReport,
  LocationCount,
  MaintenanceReport,
  PriorityBreakdown,
  ReportErrorInfo,
  ReportFilters,
  ReportOverview,
  ReportPeriod,
  ReportService,
  StatusBreakdown,
  TicketReport,
  REPORT_DEFAULT_LIMIT,
  REPORT_MAX_PERIOD_DAYS,
  mapReportError,
  validateReportPeriod,
} from '../../core/services/report.service';

import { ReportBarListComponent, ReportBarRow } from './components/report-bar-list.component';
import { ReportTrendComponent } from './components/report-trend.component';
import { NxButtonComponent } from '../../shared/components/nx-button/nx-button.component';
import { NxPanelComponent } from '../../shared/components/nx-panel/nx-panel.component';
import { NxEmptyStateComponent } from '../../shared/components/nx-empty-state/nx-empty-state.component';
import { NxErrorStateComponent } from '../../shared/components/nx-error-state/nx-error-state.component';
import { NxLoadingStateComponent } from '../../shared/components/nx-loading-state/nx-loading-state.component';
import { UnauthorizedComponent } from '../../shared/components/unauthorized/unauthorized.component';

export type ReportTab = 'overview' | 'assets' | 'inventory' | 'requests' | 'maintenance';

interface TabOption {
  key: ReportTab;
  label: string;
}

const TAB_OPTIONS: TabOption[] = [
  { key: 'overview', label: 'Overview' },
  { key: 'assets', label: 'Assets' },
  { key: 'inventory', label: 'Inventory' },
  { key: 'requests', label: 'Requests' },
  { key: 'maintenance', label: 'Maintenance' },
];

/**
 * Per-tab request state.
 *
 * Each tab owns its own slot so a failure, a refresh, or an empty result in one
 * report can never disturb another. `key` records which period the loaded data
 * belongs to, which is what makes "switching tabs keeps the range" and
 * "changing the range reloads" both true without a shared cache.
 */
interface TabState<T> {
  data: T | null;
  loading: boolean;
  refreshing: boolean;
  loaded: boolean;
  error: ReportErrorInfo | null;
  key: string;
}

function emptyState<T>(): TabState<T> {
  return { data: null, loading: false, refreshing: false, loaded: false, error: null, key: '' };
}

/**
 * ReportsPage — read-only operational analytics workspace.
 *
 * Design rules this page holds itself to, because a report is a *fact*:
 *  - every number is rendered exactly as the API returned it. No total, rate,
 *    ratio, health score, trend verdict, or forecast is computed here;
 *  - `current` snapshots and `period` activity are always shown as two separate
 *    sections, so "open right now" is never read as "opened in this period";
 *  - `null` is rendered as "no data" and never coerced to 0;
 *  - the overview is undated by contract, so the period control is disabled
 *    there instead of implying a filter the endpoint does not accept.
 */
@Component({
  selector: 'app-reports',
  standalone: true,
  imports: [
    CommonModule,
    ReportBarListComponent,
    ReportTrendComponent,
    NxButtonComponent,
    NxPanelComponent,
    NxEmptyStateComponent,
    NxErrorStateComponent,
    NxLoadingStateComponent,
    UnauthorizedComponent,
  ],
  templateUrl: './reports.page.html',
  styleUrl: './reports.page.scss',
})
export class ReportsPage implements OnInit {
  private readonly reports = inject(ReportService);
  private readonly router = inject(Router);

  readonly tabs: TabOption[] = TAB_OPTIONS;
  readonly maxPeriodDays = REPORT_MAX_PERIOD_DAYS;
  readonly periodLimit = REPORT_DEFAULT_LIMIT;

  readonly activeTab = signal<ReportTab>('overview');
  readonly periodOpen = signal(false);
  readonly fromDraft = signal('');
  readonly toDraft = signal('');
  readonly periodError = signal<string | null>(null);
  readonly appliedPeriod = signal<ReportPeriod | null>(null);

  private readonly state = signal<Record<ReportTab, TabState<unknown>>>({
    overview: emptyState(),
    assets: emptyState(),
    inventory: emptyState(),
    requests: emptyState(),
    maintenance: emptyState(),
  });

  /** Tab → period key of the request currently in flight. */
  private readonly inFlight = new Map<ReportTab, string>();

  /** Tabs whose period changed while a request was running. */
  private readonly queued = new Set<ReportTab>();

  readonly overview = this.tabView<ReportOverview>('overview');
  readonly assets = this.tabView<AssetReport>('assets');
  readonly inventory = this.tabView<InventoryReport>('inventory');
  readonly requests = this.tabView<TicketReport>('requests');
  readonly maintenance = this.tabView<MaintenanceReport>('maintenance');

  /** The period only applies to the four detail reports. */
  readonly periodEnabled = computed(() => this.activeTab() !== 'overview');

  readonly activeView = computed<TabState<unknown>>(() => this.state()[this.activeTab()]);

  /**
   * Compact context line for the report on screen.
   *
   * The overview is undated by contract, so it always reads "Current state" —
   * even when a period is armed for the module reports. Showing a range there
   * would imply a filter the endpoint does not accept.
   */
  readonly periodLabel = computed(() => {
    if (!this.periodEnabled()) {
      return 'Current state';
    }
    const period = this.appliedPeriod();
    if (!period) {
      return 'Current state';
    }
    return `Period ${this.formatDay(period.from)} – ${this.formatDay(period.to)}`;
  });

  /** True while the active report is being fetched for the first time. */
  readonly busy = computed(() => {
    const view = this.activeView();
    return view.loading || view.refreshing;
  });

  ngOnInit(): void {
    this.load('overview');
  }

  private tabView<T>(key: ReportTab): () => TabState<T> {
    return computed(() => this.state()[key] as TabState<T>);
  }

  // ─── Tab navigation ────────────────────────────────────────────────────────

  selectTab(key: ReportTab): void {
    if (this.activeTab() === key) {
      return;
    }
    this.activeTab.set(key);
    this.periodError.set(null);
    this.load(key);
  }

  /** Roving keyboard support for the tablist (arrows, Home, End). */
  onTabKeydown(event: KeyboardEvent, key: ReportTab): void {
    const index = TAB_OPTIONS.findIndex(tab => tab.key === key);
    if (index < 0) {
      return;
    }

    let next = -1;
    if (event.key === 'ArrowRight') next = (index + 1) % TAB_OPTIONS.length;
    if (event.key === 'ArrowLeft') next = (index - 1 + TAB_OPTIONS.length) % TAB_OPTIONS.length;
    if (event.key === 'Home') next = 0;
    if (event.key === 'End') next = TAB_OPTIONS.length - 1;

    if (next < 0) {
      return;
    }

    event.preventDefault();
    const target = TAB_OPTIONS[next].key;
    this.selectTab(target);
    this.focusTab(target);
  }

  private focusTab(key: ReportTab): void {
    const host = typeof document !== 'undefined' ? document : null;
    host?.querySelector<HTMLButtonElement>(`[data-report-tab="${key}"]`)?.focus();
  }

  // ─── Period control ────────────────────────────────────────────────────────

  togglePeriod(): void {
    this.periodOpen.update(open => !open);
  }

  onFromInput(value: string): void {
    this.fromDraft.set(value);
    this.periodError.set(null);
  }

  onToInput(value: string): void {
    this.toDraft.set(value);
    this.periodError.set(null);
  }

  /** Validate locally, then reload only the report on screen. */
  applyPeriod(): void {
    const from = this.fromDraft().trim();
    const to = this.toDraft().trim();
    const invalid = validateReportPeriod(from, to);

    if (invalid) {
      this.periodError.set(invalid);
      return;
    }

    this.periodError.set(null);
    this.appliedPeriod.set(from === '' ? null : { from, to });
    this.periodOpen.set(false);
    this.load(this.activeTab(), true);
  }

  /** Drop the period and return the active report to its current state. */
  clearPeriod(): void {
    this.fromDraft.set('');
    this.toDraft.set('');
    this.periodError.set(null);
    this.appliedPeriod.set(null);
    this.periodOpen.set(false);
    this.load(this.activeTab(), true);
  }

  // ─── Loading ───────────────────────────────────────────────────────────────

  /** Reload the active report on demand (the Refresh control). */
  refresh(): void {
    this.load(this.activeTab(), true);
  }

  /** Retry after an error, on the tab that failed. */
  retry(key: ReportTab): void {
    this.load(key, true);
  }

  returnToCommand(): void {
    void this.router.navigate(['/home']);
  }

  /**
   * Fetch one report. Requests are lazy per tab, deduplicated while in flight,
   * and keyed by the period so a stale range can never be shown as current.
   *
   * A repeated request for the period already in flight is dropped, but a
   * request for a *different* period is queued and replayed once the running
   * one settles — otherwise the screen could keep showing a range the user has
   * already changed.
   */
  private load(key: ReportTab, force = false): void {
    const period = this.appliedPeriod();
    const filterKey = key === 'overview' ? '' : `${period?.from ?? ''}|${period?.to ?? ''}`;
    const current = this.state()[key] as TabState<unknown>;

    if (this.inFlight.has(key)) {
      if (this.inFlight.get(key) !== filterKey) {
        this.queued.add(key);
      }
      return;
    }

    if (!force && current.loaded && current.key === filterKey) {
      return;
    }

    this.patch(key, {
      loading: !current.loaded,
      refreshing: current.loaded,
      error: null,
    });
    this.inFlight.set(key, filterKey);

    const filters = this.filtersFor(key);
    this.request(key, filters).subscribe({
      next: response => {
        this.patch(key, {
          data: response.data,
          loading: false,
          refreshing: false,
          loaded: true,
          error: null,
          key: filterKey,
        });
        this.settle(key);
      },
      error: error => {
        // The previous payload is dropped rather than shown next to a failed
        // request: mixing periods or a stale snapshot would misreport facts.
        this.patch(key, {
          data: null,
          loading: false,
          refreshing: false,
          loaded: false,
          error: mapReportError(error),
          key: filterKey,
        });
        this.settle(key);
      },
    });
  }

  /**
   * Release the in-flight slot and replay a queued reload, if any.
   *
   * Always called *after* the settled request has written its own state, so a
   * replayed request starts from the finished state and the tab never shows a
   * payload that does not belong to the period on screen.
   */
  private settle(key: ReportTab): void {
    this.inFlight.delete(key);
    if (this.queued.delete(key)) {
      this.load(key, true);
    }
  }

  private filtersFor(key: ReportTab): ReportFilters {
    if (key === 'overview') {
      return {};
    }
    const period = this.appliedPeriod();
    return {
      from: period?.from,
      to: period?.to,
      limit: REPORT_DEFAULT_LIMIT,
    };
  }

  private request(
    key: ReportTab,
    filters: ReportFilters,
  ): Observable<ApiResponse<unknown>> {
    switch (key) {
      case 'overview':
        return this.reports.getOverview();
      case 'assets':
        return this.reports.getAssets(filters);
      case 'inventory':
        return this.reports.getInventory(filters);
      case 'requests':
        return this.reports.getTickets(filters);
      case 'maintenance':
        return this.reports.getMaintenance(filters);
    }
  }

  private patch(key: ReportTab, changes: Partial<TabState<unknown>>): void {
    this.state.update(state => ({
      ...state,
      [key]: { ...state[key], ...changes } as TabState<unknown>,
    }));
  }

  // ─── Presentation helpers ──────────────────────────────────────────────────

  /**
   * Humanize a stored value for display only. The raw value stays in the DOM as
   * `data-status` and in the row `key`, so nothing is renamed in the data.
   */
  humanize(value: string): string {
    return value
      .replace(/[_-]+/g, ' ')
      .trim()
      .replace(/\b\w/g, character => character.toUpperCase());
  }

  statusRows(rows: StatusBreakdown[] | undefined): ReportBarRow[] {
    return (rows ?? []).map(row => ({
      key: row.status,
      label: this.humanize(row.status),
      count: row.count,
      status: row.status,
    }));
  }

  priorityRows(rows: PriorityBreakdown[] | undefined): ReportBarRow[] {
    return (rows ?? []).map(row => ({
      key: row.priority,
      label: this.humanize(row.priority),
      count: row.count,
    }));
  }

  categoryRows(rows: CategoryCount[] | undefined): ReportBarRow[] {
    return (rows ?? []).map(row => ({
      key: `category-${row.category_id}`,
      label: row.label,
      count: row.count,
    }));
  }

  locationRows(rows: LocationCount[] | undefined): ReportBarRow[] {
    return (rows ?? []).map(row => ({
      key: `location-${row.location_id}`,
      label: row.label,
      count: row.count,
    }));
  }

  warehouseRows(
    rows: { warehouse_id: number; label: string; quantity: number }[] | undefined,
  ): ReportBarRow[] {
    return (rows ?? []).map(row => ({
      key: `warehouse-${row.warehouse_id}`,
      label: row.label,
      count: row.quantity,
    }));
  }

  itemRows(rows: { item_id: number; quantity: number }[] | undefined): ReportBarRow[] {
    return (rows ?? []).map(row => ({
      key: `item-${row.item_id}`,
      label: `Item #${row.item_id}`,
      count: row.quantity,
    }));
  }

  count(value: number | null | undefined): string {
    return typeof value === 'number' ? value.toLocaleString() : '—';
  }

  /**
   * Money is displayed as the decimal string the API produced. No parsing, no
   * re-formatting, and no currency symbol the API does not supply.
   */
  cost(value: string | null | undefined): string {
    return value === null || value === undefined || value === '' ? '—' : value;
  }

  /** `null` average cost means "undefined", which is not the same as 0. */
  hasAverageCost(value: string | null | undefined): boolean {
    return value !== null && value !== undefined && value !== '';
  }

  /** Timestamp from the API, rendered for reading. */
  generatedAt(value: string | null | undefined): string {
    if (!value) {
      return '';
    }
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
  }

  /** `2026-09-26` → `26 Sep 2026`, parsed in UTC so the day never shifts. */
  formatDay(day: string): string {
    const parsed = new Date(`${day}T00:00:00Z`);
    if (Number.isNaN(parsed.getTime())) {
      return day;
    }
    return parsed.toLocaleDateString('en-GB', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      timeZone: 'UTC',
    });
  }

  isEmptyOverview(data: ReportOverview): boolean {
    return (
      data.assets.total === 0 &&
      data.inventory.item_count === 0 &&
      data.tickets.total === 0 &&
      data.maintenance.total === 0
    );
  }

  isEmptyAssets(data: AssetReport): boolean {
    return data.current.total === 0 && data.assignments.total === 0;
  }

  isEmptyInventory(data: InventoryReport): boolean {
    return (
      data.items.count === 0 &&
      data.warehouses.count === 0 &&
      data.current_stock.total_quantity === 0
    );
  }

  isEmptyRequests(data: TicketReport): boolean {
    return data.current.total === 0;
  }

  isEmptyMaintenance(data: MaintenanceReport): boolean {
    return (
      data.requests.total === 0 && data.records.count === 0 && data.parts.usage_count === 0
    );
  }
}
