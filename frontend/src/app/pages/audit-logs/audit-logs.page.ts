import { Component, inject, signal, computed, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { IonIcon } from '@ionic/angular';

import {
  AuditLogService,
  AuditLog,
  AuditFilters,
  AuditErrorInfo,
  mapAuditError,
  AUDIT_ACTIONS,
  AUDIT_RESOURCE_TYPES,
  humanizeAuditValue,
  formatAuditTime,
  auditChangedRows,
  AuditChangeRow,
  auditValueText,
  auditResourceTarget,
  AuditNavigationTarget,
} from '../../core/services/audit-log.service';
import { AssetService } from '../../core/services/asset.service';
import { CommandPaletteService } from '../../core/services/command-palette.service';
import { AuthorizationService } from '../../core/services/authorization.service';
import { NxButtonComponent } from '../../shared/components/nx-button/nx-button.component';
import { NxEmptyStateComponent } from '../../shared/components/nx-empty-state/nx-empty-state.component';
import { NxErrorStateComponent } from '../../shared/components/nx-error-state/nx-error-state.component';

/** The maximum inclusive date-range span the backend accepts (Phase 20A). */
const MAX_RANGE_DAYS = 366;

/**
 * AuditLogsPage — the Audit Logs & Governance workspace.
 *
 * A read-only master/detail view over the immutable governance trail
 * (GET /audit-logs, GET /audit-logs/{id}). Backend-driven pagination and
 * filters (actor, action, resource type/id, inclusive date range) are sent
 * exactly as the API expects; the newest-first order from the backend is
 * never re-sorted. The page renders governance facts only — it never offers
 * create/edit/delete, never invents values, and degrades gracefully for
 * unknown future actions/resource types.
 */
@Component({
  selector: 'app-audit-logs',
  standalone: true,
  imports: [CommonModule, IonIcon, NxButtonComponent, NxEmptyStateComponent, NxErrorStateComponent],
  templateUrl: './audit-logs.page.html',
  styleUrl: './audit-logs.page.scss',
})
export class AuditLogsPage implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly service = inject(AuditLogService);
  private readonly assetService = inject(AssetService);
  readonly authorization = inject(AuthorizationService);
  private readonly palette = inject(CommandPaletteService);

  readonly skeletonRows = [1, 2, 3, 4, 5];
  readonly perPage = 25;
  readonly formatTime = formatAuditTime;
  readonly humanize = humanizeAuditValue;
  readonly valueText = auditValueText;
  readonly ACTIONS = AUDIT_ACTIONS;
  readonly RESOURCE_TYPES = AUDIT_RESOURCE_TYPES;

  // ─── List state ────────────────────────────────────────────────────────────

  readonly logs = signal<AuditLog[]>([]);
  readonly total = signal(0);
  readonly currentPage = signal(1);
  readonly lastPage = signal(1);
  readonly loading = signal(true);
  readonly refreshing = signal(false);
  readonly stale = signal(false);
  readonly error = signal<AuditErrorInfo | null>(null);
  /** True when the backend denied audit access (403). */
  readonly denied = computed(() => this.error()?.status === 403);

  // ─── Filter state ──────────────────────────────────────────────────────────

  readonly actorFilter = signal('');
  readonly actionFilter = signal('');
  readonly resourceTypeFilter = signal('');
  readonly resourceIdFilter = signal('');
  readonly dateFrom = signal('');
  readonly dateTo = signal('');

  /** Draft inputs for the date range; applied explicitly via applyDateRange(). */
  readonly draftFrom = signal('');
  readonly draftTo = signal('');

  /** Actor options come from the existing users API (id + name only used). */
  readonly actorOptions = signal<Array<{ id: number; name: string }>>([]);

  readonly activeFilterCount = computed(() => {
    let count = 0;
    if (this.actorFilter()) count++;
    if (this.actionFilter()) count++;
    if (this.resourceTypeFilter()) count++;
    if (this.resourceIdFilter()) count++;
    if (this.dateFrom() || this.dateTo()) count++;
    return count;
  });

  readonly dateRangeError = computed(() => {
    if (this.dateFrom() || this.dateTo()) return ''; // applied range is valid
    const from = this.draftFrom();
    const to = this.draftTo();
    if (!from && !to) return '';
    if (!from || !to) return 'Provide both dates or clear both.';
    if (from > to) return 'From must be on or before To.';
    if (this.daysBetween(from, to) > MAX_RANGE_DAYS) {
      return `Range may not exceed ${MAX_RANGE_DAYS} days.`;
    }
    return '';
  });

  // ─── Detail state ──────────────────────────────────────────────────────────

  readonly selectedId = signal<number | null>(null);
  readonly detail = signal<AuditLog | null>(null);
  readonly detailLoading = signal(false);
  readonly detailError = signal<AuditErrorInfo | null>(null);

  readonly selectedLog = computed(() => {
    const id = this.selectedId();
    return id === null ? null : this.detail();
  });

  /** Structured diff rows ("Changed fields"); null when not a two-sided change. */
  readonly changedRows = computed<AuditChangeRow[] | null>(() => {
    const log = this.selectedLog();
    if (!log || !log.old_values || !log.new_values) return null;
    return auditChangedRows(log.old_values, log.new_values);
  });

  /** "Created values" for created-style events (no old_values side). */
  readonly createdValues = computed<Array<{ field: string; value: string }> | null>(() => {
    const log = this.selectedLog();
    if (!log || log.old_values || !log.new_values) return null;
    return Object.entries(log.new_values).map(([field, value]) => ({
      field: this.humanize(field),
      value: auditValueText(value),
    }));
  });

  /** "Previous values" for deleted-style events (no new_values side). */
  readonly previousValues = computed<Array<{ field: string; value: string }>>(() => {
    const log = this.selectedLog();
    if (!log || !log.old_values || log.new_values) return [];
    return Object.entries(log.old_values).map(([field, value]) => ({
      field: this.humanize(field),
      value: auditValueText(value),
    }));
  });

  /**
   * Related-record deep link (verified mappings only; null = none). Suppressed
   * for `deleted` events: the resource may no longer exist, and the audit trail
   * must not imply that it does.
   */
  readonly detailTarget = computed<AuditNavigationTarget | null>(() => {
    const log = this.selectedLog();
    if (!log || log.action === 'deleted') return null;
    return auditResourceTarget(log.resource.type, log.resource.id);
  });

  trackById = (_index: number, item: { id: number }): number => item.id;

  ngOnInit(): void {
    this.loadActors();
    this.loadLogs(true);
    this.route.queryParams.subscribe(params => {
      const raw = params['selected'];
      if (raw === undefined || raw === null) return;
      const id = Number(raw);
      if (!Number.isInteger(id) || id <= 0) return;
      if (this.selectedId() === id) return;
      this.selectLog(id);
    });
  }

  // ─── Loading ───────────────────────────────────────────────────────────────

  loadLogs(refresh = false): void {
    if (refresh) this.loading.set(true);
    this.error.set(null);
    this.stale.set(false);

    this.service.getAuditLogs(this.buildFilters()).subscribe({
      next: res => {
        this.loading.set(false);
        this.refreshing.set(false);
        if (res.success) {
          this.logs.set(res.data.items);
          this.total.set(res.data.pagination.total);
          this.currentPage.set(res.data.pagination.current_page);
          this.lastPage.set(res.data.pagination.last_page);
        }
      },
      error: err => {
        this.loading.set(false);
        this.refreshing.set(false);
        const info = mapAuditError(err);
        if (this.logs().length > 0) {
          // Refresh failure: preserve the last list, flag it stale.
          this.stale.set(true);
        } else {
          // Initial failure: show the error state.
          this.error.set(info);
        }
      },
    });
  }

  /** Refresh only the current list/detail; ignores duplicate in-flight clicks. */
  refresh(): void {
    if (this.refreshing() || this.loading()) return;
    this.refreshing.set(true);
    this.error.set(null);
    this.stale.set(false);
    this.service.getAuditLogs(this.buildFilters()).subscribe({
      next: res => {
        this.refreshing.set(false);
        if (res.success) {
          this.logs.set(res.data.items);
          this.total.set(res.data.pagination.total);
          this.currentPage.set(res.data.pagination.current_page);
          this.lastPage.set(res.data.pagination.last_page);
        }
      },
      error: err => {
        this.refreshing.set(false);
        if (this.logs().length > 0) {
          this.stale.set(true);
        } else {
          this.error.set(mapAuditError(err));
        }
      },
    });
  }

  private buildFilters(): AuditFilters {
    const filters: AuditFilters = {
      page: this.currentPage(),
      per_page: this.perPage,
    };
    if (this.actorFilter()) filters.actor_id = this.actorFilter();
    if (this.actionFilter()) filters.action = this.actionFilter();
    if (this.resourceTypeFilter()) filters.resource_type = this.resourceTypeFilter();
    if (this.resourceIdFilter()) filters.resource_id = this.resourceIdFilter();
    if (this.dateFrom() && this.dateTo()) {
      filters.from = this.dateFrom();
      filters.to = this.dateTo();
    }
    return filters;
  }

  /** Actor options from the existing users API; only id + name are used. */
  private loadActors(): void {
    this.assetService.listUsers({ per_page: 100 }).subscribe({
      next: res => {
        if (res.success) {
          this.actorOptions.set(res.data.items.map(u => ({ id: u.id, name: u.name })));
        }
      },
      error: () => {
        /* Non-fatal: the selector stays empty; the actor filter is optional. */
      },
    });
  }

  // ─── Filters (every change resets page → 1 and reloads) ───────────────────

  setActorFilter(value: string): void {
    this.actorFilter.set(value);
    this.gotoPage(1);
  }

  setActionFilter(value: string): void {
    this.actionFilter.set(value);
    this.gotoPage(1);
  }

  setResourceTypeFilter(value: string): void {
    this.resourceTypeFilter.set(value);
    this.gotoPage(1);
  }

  setResourceIdFilter(value: string): void {
    this.resourceIdFilter.set(value.trim());
    this.gotoPage(1);
  }

  applyDateRange(): void {
    if (this.dateRangeError()) return;
    const from = this.draftFrom();
    const to = this.draftTo();
    if (!from && !to) return; // nothing to apply
    this.dateFrom.set(from);
    this.dateTo.set(to);
    this.gotoPage(1);
  }

  clearDateRange(): void {
    this.draftFrom.set('');
    this.draftTo.set('');
    if (!this.dateFrom() && !this.dateTo()) return;
    this.dateFrom.set('');
    this.dateTo.set('');
    this.gotoPage(1);
  }

  clearFilters(): void {
    this.actorFilter.set('');
    this.actionFilter.set('');
    this.resourceTypeFilter.set('');
    this.resourceIdFilter.set('');
    this.dateFrom.set('');
    this.dateTo.set('');
    this.draftFrom.set('');
    this.draftTo.set('');
    this.gotoPage(1);
  }

  private daysBetween(from: string, to: string): number {
    const ms = Date.parse(to) - Date.parse(from);
    if (Number.isNaN(ms)) return 0;
    return Math.round(ms / 86_400_000) + 1;
  }

  // ─── Pagination ────────────────────────────────────────────────────────────

  gotoPage(page: number): void {
    const last = this.lastPage();
    const target = page < 1 ? 1 : page > last ? last : page;
    this.currentPage.set(target);
    this.loadLogs(true);
  }

  // ─── Selection / detail ────────────────────────────────────────────────────

  selectLog(id: number): void {
    this.selectedId.set(id);
    this.router.navigate([], {
      queryParams: { selected: id },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    this.loadDetail(id);
  }

  clearSelection(): void {
    this.selectedId.set(null);
    this.detail.set(null);
    this.detailError.set(null);
    this.router.navigate([], {
      queryParams: { selected: null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  loadDetail(id: number): void {
    this.detail.set(null);
    this.detailLoading.set(true);
    this.detailError.set(null);
    this.service.getAuditLog(id).subscribe({
      next: res => {
        this.detailLoading.set(false);
        if (res.success) {
          this.detail.set(res.data);
        }
      },
      error: err => {
        this.detailLoading.set(false);
        this.detailError.set(mapAuditError(err));
      },
    });
  }

  /** Navigate to the related record when a verified mapping exists. */
  openRelated(): void {
    const target = this.detailTarget();
    if (target) {
      void this.router.navigate([target.path], { queryParams: target.queryParams });
    }
  }

  openPalette(): void {
    this.palette.open();
  }
}
