import { HttpErrorResponse } from '@angular/common/http';

import { Component, inject, signal, computed, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormGroup, FormControl, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { IonIcon } from '@ionic/angular';

import {
  MaintenanceService,
  MaintenanceRequest,
  MaintenanceRecord,
  MaintenanceStatus,
  MaintenancePriority,
  MaintenanceRequestListFilters,
  MaintenanceRequestPayload,
  MaintenanceRequestUpdatePayload,
  MaintenanceRecordUpdatePayload,
  MaintenancePartPayload,
  mapMaintenanceError,
  MaintenanceErrorInfo,
} from '../../core/services/maintenance.service';
import { AssetService, Asset, ManagedUser, LocationRecord } from '../../core/services/asset.service';
import { InventoryService, InventoryItem } from '../../core/services/inventory.service';
import { AuthorizationService, roleHasPermission } from '../../core/services/authorization.service';
import { CommandPaletteService } from '../../core/services/command-palette.service';

import { NxSearchComponent } from '../../shared/components/nx-search/nx-search.component';
import { NxBadgeComponent } from '../../shared/components/nx-badge/nx-badge.component';
import { NxButtonComponent } from '../../shared/components/nx-button/nx-button.component';
import { NxPanelComponent } from '../../shared/components/nx-panel/nx-panel.component';
import { NxEmptyStateComponent } from '../../shared/components/nx-empty-state/nx-empty-state.component';
import { NxLoadingStateComponent } from '../../shared/components/nx-loading-state/nx-loading-state.component';
import { NxErrorStateComponent } from '../../shared/components/nx-error-state/nx-error-state.component';
import { NxConfirmDialogComponent } from '../../shared/components/nx-confirm-dialog/nx-confirm-dialog.component';
import { NxHasPermissionDirective } from '../../shared/components/nx-has-permission/nx-has-permission.directive';
import { NxToastService } from '../../shared/components/nx-toast/nx-toast.component';

const SEARCH_DEBOUNCE_MS = 300;

type BadgeStatus = 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'pending';

const STATUS_LABELS: Record<MaintenanceStatus, string> = {
  REQUESTED: 'Requested',
  APPROVED: 'Approved',
  IN_PROGRESS: 'In Progress',
  COMPLETED: 'Completed',
  CANCELLED: 'Cancelled',
};

const STATUS_BADGE: Record<MaintenanceStatus, BadgeStatus> = {
  REQUESTED: 'pending',
  APPROVED: 'info',
  IN_PROGRESS: 'warning',
  COMPLETED: 'success',
  CANCELLED: 'neutral',
};

const PRIORITY_LABELS: Record<MaintenancePriority, string> = {
  LOW: 'Low',
  MEDIUM: 'Medium',
  HIGH: 'High',
  URGENT: 'Urgent',
};

const PRIORITY_BADGE: Record<MaintenancePriority, BadgeStatus> = {
  LOW: 'neutral',
  MEDIUM: 'info',
  HIGH: 'warning',
  URGENT: 'danger',
};

const SORT_OPTIONS: Array<{ value: string; label: string }> = [
  { value: 'requested_desc', label: 'Newest first' },
  { value: 'requested_asc', label: 'Oldest first' },
  { value: 'updated_desc', label: 'Recently updated' },
  { value: 'priority_desc', label: 'Priority (high first)' },
];

/** Asset states a maintenance request may target (mirrors backend eligibility). */
const INELIGIBLE_ASSET_STATUSES = ['RETIRED', 'LOST', 'DISPOSED'];

/** A single confirm-dialog request with the action to run on confirm. */
interface ConfirmRequest {
  title: string;
  message: string;
  confirmLabel: string;
  confirmVariant: 'primary' | 'danger' | 'secondary';
  action: () => void;
}

/**
 * MaintenancePage — Maintenance Management workspace.
 *
 * A permission-aware, master-detail workspace over the maintenance request
 * lifecycle (REQUESTED → APPROVED → IN_PROGRESS → COMPLETED, CANCELLED from
 * REQUESTED/APPROVED). Request status is always rendered from the backend,
 * never derived locally. Work opens through the record endpoint (Start Work),
 * which transitions an approved request to IN_PROGRESS atomically. Parts are
 * a trace-only journal — adding a part never creates a stock movement.
 *
 * Staff / managers (view_maintenance only) see exactly the requests they
 * raised, matching the backend scope. Everyone holding manage_maintenance
 * (admins, technicians) sees and handles every request.
 */
@Component({
  selector: 'app-maintenance',
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    IonIcon,
    NxSearchComponent,
    NxBadgeComponent,
    NxButtonComponent,
    NxPanelComponent,
    NxEmptyStateComponent,
    NxLoadingStateComponent,
    NxErrorStateComponent,
    NxConfirmDialogComponent,
    NxHasPermissionDirective,
  ],
  templateUrl: './maintenance.page.html',
  styleUrl: './maintenance.page.scss',
})
export class MaintenancePage implements OnInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly maintenanceService = inject(MaintenanceService);
  private readonly assetService = inject(AssetService);
  private readonly inventoryService = inject(InventoryService);
  private readonly authorization = inject(AuthorizationService);
  private readonly palette = inject(CommandPaletteService);
  private readonly toast = inject(NxToastService);

  readonly STATUS_LABEL: Record<string, string> = STATUS_LABELS;
  readonly PRIORITY_LABEL: Record<string, string> = PRIORITY_LABELS;
  readonly SORTS: Array<{ value: string; label: string }> = SORT_OPTIONS;

  // ─── View state ───────────────────────────────────────────────────────────

  readonly skeletonRows = [1, 2, 3, 4, 5];
  readonly perPage = 15;

  // ─── Permissions ──────────────────────────────────────────────────────────

  readonly canCreateRequest = computed(() => this.authorization.hasPermission('view_maintenance'));
  readonly hasManage = computed(() => this.authorization.hasPermission('manage_maintenance'));

  // ─── Summary strip (backend-derived counts only) ──────────────────────────

  readonly requestsTotal = signal(0);
  readonly recordsTotal = signal(0);

  // ─── Request list (master) ────────────────────────────────────────────────

  readonly requests = signal<MaintenanceRequest[]>([]);
  readonly currentPage = signal(1);
  readonly lastPage = signal(1);
  readonly requestsLoading = signal(true);
  readonly requestsError = signal<MaintenanceErrorInfo | null>(null);
  readonly searchQuery = signal('');
  readonly statusFilter = signal<MaintenanceStatus | ''>('');
  readonly priorityFilter = signal<MaintenancePriority | ''>('');
  readonly assetFilter = signal<number | ''>('');
  readonly assigneeFilter = signal<number | ''>('');
  readonly locationFilter = signal<number | ''>('');
  readonly requestedFrom = signal('');
  readonly requestedTo = signal('');
  readonly sortOption = signal('requested_desc');

  // ─── Lookups ──────────────────────────────────────────────────────────────

  readonly assetOptions = signal<Asset[]>([]);
  readonly technicianOptions = signal<ManagedUser[]>([]);
  readonly assigneeOptions = signal<ManagedUser[]>([]);
  readonly locationOptions = signal<LocationRecord[]>([]);
  readonly itemOptions = signal<InventoryItem[]>([]);

  readonly activeFilterCount = computed(() => {
    let count = 0;
    if (this.searchQuery()) count++;
    if (this.statusFilter()) count++;
    if (this.priorityFilter()) count++;
    if (this.assetFilter()) count++;
    if (this.assigneeFilter()) count++;
    if (this.locationFilter()) count++;
    if (this.requestedFrom()) count++;
    if (this.requestedTo()) count++;
    return count;
  });

  // ─── Request detail ───────────────────────────────────────────────────────

  readonly selectedRequestId = signal<number | null>(null);
  readonly detailRequest = signal<MaintenanceRequest | null>(null);
  readonly detailLoading = signal(false);
  readonly detailError = signal<MaintenanceErrorInfo | null>(null);

  readonly selectedRequest = computed(() => {
    const id = this.selectedRequestId();
    if (id === null) return null;
    return this.detailRequest();
  });

  // ─── Request create ───────────────────────────────────────────────────────

  readonly isCreateOpen = signal(false);
  readonly createAssetSearch = signal('');
  readonly isCreateSubmitting = signal(false);
  readonly createErrors = signal<Record<string, string>>({});
  readonly createMessage = signal('');

  requestForm = new FormGroup({
    asset_id: new FormControl<number | null>(null, Validators.required),
    title: new FormControl('', [Validators.required, Validators.maxLength(255)]),
    description: new FormControl('', Validators.required),
    priority: new FormControl<MaintenancePriority>('MEDIUM'),
  });

  // ─── Request edit ─────────────────────────────────────────────────────────

  readonly isEditOpen = signal(false);
  readonly editingRequest = signal<MaintenanceRequest | null>(null);
  readonly isEditSubmitting = signal(false);
  readonly editErrors = signal<Record<string, string>>({});
  readonly editMessage = signal('');

  editForm = new FormGroup({
    title: new FormControl('', [Validators.required, Validators.maxLength(255)]),
    description: new FormControl('', Validators.required),
    priority: new FormControl<MaintenancePriority>('MEDIUM'),
  });

  // ─── Assignment ───────────────────────────────────────────────────────────

  readonly isAssignOpen = signal(false);
  readonly assignTarget = signal<MaintenanceRequest | null>(null);
  readonly isAssignSubmitting = signal(false);
  readonly assignErrors = signal<Record<string, string>>({});
  readonly assignMessage = signal('');

  assigneeForm = new FormGroup({
    user_id: new FormControl<number | null>(null, Validators.required),
  });

  // ─── Start Work (record create) ───────────────────────────────────────────

  readonly isStartOpen = signal(false);
  readonly startTarget = signal<MaintenanceRequest | null>(null);
  readonly isStartSubmitting = signal(false);
  readonly startErrors = signal<Record<string, string>>({});
  readonly startMessage = signal('');

  startWorkForm = new FormGroup({
    description: new FormControl('', Validators.required),
    technician_id: new FormControl<number | null>(null),
  });

  // ─── Record edit (work fields) ────────────────────────────────────────────

  readonly isRecordEditOpen = signal(false);
  readonly editingRecord = signal<MaintenanceRecord | null>(null);
  readonly recordTargetRequest = signal<MaintenanceRequest | null>(null);
  readonly isRecordEditSubmitting = signal(false);
  readonly recordEditErrors = signal<Record<string, string>>({});
  readonly recordEditMessage = signal('');

  recordEditForm = new FormGroup({
    description: new FormControl('', Validators.required),
    started_at: new FormControl<string | null>(null),
    completed_at: new FormControl<string | null>(null),
    result: new FormControl<string | null>(null),
    cost: new FormControl<string | null>(null),
    technician_id: new FormControl<number | null>(null),
  });

  // ─── Parts (trace-only) ───────────────────────────────────────────────────

  readonly isPartOpen = signal(false);
  readonly partTargetRecord = signal<MaintenanceRecord | null>(null);
  readonly partItemSearch = signal('');
  readonly isPartSubmitting = signal(false);
  readonly partErrors = signal<Record<string, string>>({});
  readonly partMessage = signal('');

  partForm = new FormGroup({
    item_id: new FormControl<number | null>(null, Validators.required),
    quantity: new FormControl<number | null>(1, [Validators.required, Validators.min(1)]),
  });

  // ─── Confirm dialog ───────────────────────────────────────────────────────

  readonly confirmRequest = signal<ConfirmRequest | null>(null);

  private searchTimer: ReturnType<typeof setTimeout> | null = null;
  private loadSeq = 0;

  trackByRequestId = (_index: number, request: MaintenanceRequest): number => request.id;
  trackById = (_index: number, record: { id: number }): number => record.id;

  // ─── Lifecycle ────────────────────────────────────────────────────────────

  ngOnInit(): void {
    this.loadRequests(true);
    this.loadRecordsSummary();
    this.loadLocations();
    this.loadAssetOptions('', true);
    this.loadMaintenanceUsers();

    this.route.queryParams.subscribe(params => {
      const raw = params['selected'];
      if (raw === undefined || raw === null) return;
      const id = Number(raw);
      if (!Number.isInteger(id) || id <= 0) return;
      if (this.selectedRequestId() === id) return;
      this.selectRequest(id);
    });
  }

  ngOnDestroy(): void {
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
    }
  }

  // ─── Data loading ─────────────────────────────────────────────────────────

  loadRequests(refresh = false): void {
    if (refresh) {
      this.requestsLoading.set(true);
    }
    const seq = ++this.loadSeq;

    const filters: MaintenanceRequestListFilters = {
      page: this.currentPage(),
      per_page: this.perPage,
      ...this.sortParams(),
    };
    const search = this.searchQuery().trim();
    if (search) filters.search = search;
    if (this.statusFilter()) filters.status = this.statusFilter() as MaintenanceStatus;
    if (this.priorityFilter()) filters.priority = this.priorityFilter() as MaintenancePriority;
    if (this.assetFilter()) filters.asset_id = this.assetFilter();
    if (this.assigneeFilter()) filters.assigned_to = this.assigneeFilter();
    if (this.locationFilter()) filters.location_id = this.locationFilter();
    if (this.requestedFrom()) filters.requested_from = this.requestedFrom();
    if (this.requestedTo()) filters.requested_to = this.requestedTo();

    this.maintenanceService.listRequests(filters).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;
        this.requestsLoading.set(false);
        if (res.success) {
          this.requests.set(res.data.items);
          this.requestsTotal.set(res.data.pagination.total);
          this.currentPage.set(res.data.pagination.current_page);
          this.lastPage.set(res.data.pagination.last_page);
          this.requestsError.set(null);
        }
      },
      error: (err) => {
        if (seq !== this.loadSeq) return;
        this.requestsLoading.set(false);
        const info = mapMaintenanceError(err);
        this.requestsError.set(info);
        if (this.requests().length === 0) {
          this.toast.danger('Unable to load requests', info.message);
        }
      },
    });
  }

  refreshRequests(): void {
    this.loadRequests(true);
  }

  /** Records count feeds the summary strip; the backend is the only source. */
  loadRecordsSummary(): void {
    this.maintenanceService.listRecords({ page: 1, per_page: 1 }).subscribe({
      next: (res) => {
        if (res.success) {
          this.recordsTotal.set(res.data.pagination.total);
        }
      },
      error: () => { /* non-fatal: the strip simply keeps its previous value */ },
    });
  }

  selectRequest(id: number): void {
    this.selectedRequestId.set(id);
    this.router.navigate([], {
      queryParams: { selected: id },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    this.loadDetail(id);
  }

  clearSelection(): void {
    this.selectedRequestId.set(null);
    this.detailRequest.set(null);
    this.detailError.set(null);
    this.router.navigate([], {
      queryParams: { selected: null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  retryDetail(): void {
    const id = this.selectedRequestId();
    if (id !== null) {
      this.loadDetail(id);
    }
  }

  loadDetail(id: number): void {
    this.detailRequest.set(null);
    this.detailLoading.set(true);
    this.detailError.set(null);

    this.maintenanceService.getRequest(id).subscribe({
      next: (res) => {
        this.detailLoading.set(false);
        if (!res.success || !res.data) return;
        this.detailRequest.set(res.data);
      },
      error: (err) => {
        this.detailLoading.set(false);
        this.detailError.set(mapMaintenanceError(err));
      },
    });
  }

  /** List + detail are both reloaded so the server always stays authoritative. */
  private refreshAfterRequestChange(id: number): void {
    this.loadDetail(id);
    this.refreshRequests();
  }

  // ─── Lookups ──────────────────────────────────────────────────────────────

  /** Assets for the request filters / create picker. Never shows ineligible assets. */
  loadAssetOptions(search: string, loadIneligible = false): void {
    const query = search.trim();
    this.assetService.listAssets({
      search: query || undefined,
      per_page: 100,
      sort: 'name',
      direction: 'asc',
    }).subscribe({
      next: (res) => {
        if (!res.success) return;
        const filtered = loadIneligible
          ? res.data.items
          : res.data.items.filter(asset => !INELIGIBLE_ASSET_STATUSES.includes(asset.status));
        this.assetOptions.set(filtered);
      },
      error: () => { /* non-fatal: pickers simply stay empty */ },
    });
  }

  /** Users that can perform maintenance work — UX filter; the backend stays authority. */
  loadMaintenanceUsers(): void {
    if (this.technicianOptions().length > 0) return;
    this.assetService.listUsers({ is_active: true, per_page: 100 }).subscribe({
      next: (res) => {
        if (!res.success) return;
        const users = res.data.items;
        this.technicianOptions.set(users.filter(user => roleHasPermission(user.role?.slug ?? null, 'manage_maintenance')));
        this.assigneeOptions.set(this.technicianOptions());
      },
      error: () => { /* non-fatal */ },
    });
  }

  loadLocations(): void {
    if (this.locationOptions().length > 0) return;
    this.assetService.listLocations({ per_page: 100 }).subscribe({
      next: (res) => {
        if (res.success) {
          this.locationOptions.set(res.data.items);
        }
      },
      error: () => { /* non-fatal */ },
    });
  }

  /** Active items for the trace-only part journal picker. */
  loadItemOptions(search: string): void {
    const query = search.trim();
    this.inventoryService.listItems({
      search: query || undefined,
      is_active: true,
      per_page: 100,
      sort: 'name',
      direction: 'asc',
    }).subscribe({
      next: (res) => {
        if (res.success) {
          this.itemOptions.set(res.data.items);
        }
      },
      error: () => { /* non-fatal */ },
    });
  }

  // ─── Search & filters ─────────────────────────────────────────────────────

  onSearch(value: string): void {
    this.searchQuery.set(value);
    this.currentPage.set(1);
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
    }
    this.searchTimer = setTimeout(() => this.loadRequests(true), SEARCH_DEBOUNCE_MS);
  }

  setStatusFilter(status: MaintenanceStatus | ''): void {
    this.statusFilter.set(status);
    this.currentPage.set(1);
    this.loadRequests(true);
  }

  setPriorityFilter(priority: MaintenancePriority | ''): void {
    this.priorityFilter.set(priority);
    this.currentPage.set(1);
    this.loadRequests(true);
  }

  setAssetFilter(id: number | ''): void {
    this.assetFilter.set(id);
    this.currentPage.set(1);
    this.loadRequests(true);
  }

  setAssigneeFilter(id: number | ''): void {
    this.assigneeFilter.set(id);
    this.currentPage.set(1);
    this.loadRequests(true);
  }

  setLocationFilter(id: number | ''): void {
    this.locationFilter.set(id);
    this.currentPage.set(1);
    this.loadRequests(true);
  }

  setRequestedFrom(value: string): void {
    this.requestedFrom.set(value);
    this.currentPage.set(1);
    this.loadRequests(true);
  }

  setRequestedTo(value: string): void {
    this.requestedTo.set(value);
    this.currentPage.set(1);
    this.loadRequests(true);
  }

  setSortOption(value: string): void {
    this.sortOption.set(value);
    this.currentPage.set(1);
    this.loadRequests(true);
  }

  clearFilters(): void {
    this.searchQuery.set('');
    this.statusFilter.set('');
    this.priorityFilter.set('');
    this.assetFilter.set('');
    this.assigneeFilter.set('');
    this.locationFilter.set('');
    this.requestedFrom.set('');
    this.requestedTo.set('');
    this.currentPage.set(1);
    this.loadRequests(true);
  }

  goToPage(page: number): void {
    const last = this.lastPage();
    const clamped = Math.max(1, Math.min(page, last));
    if (clamped === this.currentPage()) return;
    this.currentPage.set(clamped);
    this.loadRequests(true);
  }

  // ─── Presentation helpers ─────────────────────────────────────────────────

  statusBadge(status: MaintenanceStatus): BadgeStatus {
    return STATUS_BADGE[status] ?? 'neutral';
  }

  priorityBadge(priority: MaintenancePriority): BadgeStatus {
    return PRIORITY_BADGE[priority] ?? 'neutral';
  }

  statusLabel(status: MaintenanceStatus | null): string {
    return status ? STATUS_LABELS[status] : '—';
  }

  userName(user: MaintenanceRequest['requester']): string {
    return user?.name ?? 'Unknown user';
  }

  assetName(asset: MaintenanceRequest['asset']): string {
    return asset?.name ?? 'Unknown asset';
  }

  costNumber(cost: string | null): number | null {
    if (cost === null || cost === '') return null;
    const parsed = Number(cost);
    return Number.isFinite(parsed) ? parsed : null;
  }

  /** Convert an ISO timestamp from the API into `datetime-local` input value. */
  toDateTimeLocal(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number): string => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  /** Convert a `datetime-local` input value back into an ISO string (or null). */
  toIso(value: string | null | undefined): string | null {
    if (!value) return null;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? null : d.toISOString();
  }

  // ─── Command palette ──────────────────────────────────────────────────────

  openPalette(): void {
    this.palette.open();
  }

  // ─── Workflow state hints ─────────────────────────────────────────────────

  requestIsPending = computed(() => this.selectedRequest()?.status === 'REQUESTED');
  requestIsInProgress = computed(() => this.selectedRequest()?.status === 'IN_PROGRESS');
  requestIsCompleted = computed(() => this.selectedRequest()?.status === 'COMPLETED');
  requestIsCancelled = computed(() => this.selectedRequest()?.status === 'CANCELLED');
  canStartWork = computed(() =>
    this.selectedRequest()?.status === 'APPROVED' && this.hasManage(),
  );
  canCancel = computed(() => {
    const status = this.selectedRequest()?.status;
    return (status === 'REQUESTED' || status === 'APPROVED') && this.hasManage();
  });
  canEditRequest = computed(() => {
    const status = this.selectedRequest()?.status;
    return (status === 'REQUESTED' || status === 'APPROVED' || status === 'IN_PROGRESS') && this.hasManage();
  });
  canAssign = computed(() => {
    const status = this.selectedRequest()?.status;
    return (status === 'REQUESTED' || status === 'APPROVED' || status === 'IN_PROGRESS') && this.hasManage();
  });

  // ─── Request create ───────────────────────────────────────────────────────

  openCreateForm(): void {
    this.requestForm.reset({
      asset_id: null,
      title: '',
      description: '',
      priority: 'MEDIUM',
    });
    this.createErrors.set({});
    this.createMessage.set('');
    this.createAssetSearch.set('');
    this.loadAssetOptions('');
    this.isCreateOpen.set(true);
  }

  onAssetSearch(value: string): void {
    this.createAssetSearch.set(value);
    this.loadAssetOptions(value);
  }

  closeCreateForm(): void {
    this.isCreateOpen.set(false);
    this.createErrors.set({});
    this.createMessage.set('');
  }

  clearCreateError(field: string): void {
    this.createErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
    this.createMessage.set('');
  }

  submitCreateForm(): void {
    if (this.isCreateSubmitting()) return;

    const errors: Record<string, string> = {};
    const assetId = this.requestForm.value.asset_id;
    const title = String(this.requestForm.value.title ?? '').trim();
    const description = String(this.requestForm.value.description ?? '').trim();
    if (!assetId) errors['asset_id'] = 'Select an asset.';
    if (!title) errors['title'] = 'Title is required.';
    else if (title.length > 255) errors['title'] = 'Title may not be longer than 255 characters.';
    if (!description) errors['description'] = 'Description is required.';
    if (Object.keys(errors).length > 0) {
      this.createErrors.set(errors);
      this.requestForm.markAllAsTouched();
      return;
    }

    const payload: MaintenanceRequestPayload = {
      asset_id: Number(assetId),
      title,
      description,
      priority: (this.requestForm.value.priority ?? 'MEDIUM') as MaintenancePriority,
    };

    this.isCreateSubmitting.set(true);
    this.createErrors.set({});
    this.createMessage.set('');

    this.maintenanceService.createRequest(payload).subscribe({
      next: (res) => {
        this.isCreateSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Request submission failed', res.message);
          return;
        }
        this.toast.success('Maintenance request created', res.data.asset?.name ?? 'Asset scheduled');
        this.closeCreateForm();
        this.selectRequest(res.data.id);
        this.refreshRequests();
      },
      error: (err) => {
        this.isCreateSubmitting.set(false);
        const info = mapMaintenanceError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.createErrors.set(info.fieldErrors);
        } else {
          this.createMessage.set(info.message);
        }
      },
    });
  }

  // ─── Request edit ─────────────────────────────────────────────────────────

  openEditForm(request: MaintenanceRequest): void {
    this.editingRequest.set(request);
    this.editForm.reset({
      title: request.title,
      description: request.description ?? '',
      priority: request.priority,
    });
    this.editErrors.set({});
    this.editMessage.set('');
    this.isEditOpen.set(true);
  }

  closeEditForm(): void {
    this.isEditOpen.set(false);
    this.editingRequest.set(null);
    this.editErrors.set({});
    this.editMessage.set('');
  }

  clearEditError(field: string): void {
    this.editErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
    this.editMessage.set('');
  }

  submitEditForm(): void {
    if (this.isEditSubmitting()) return;
    const request = this.editingRequest();
    if (!request) return;

    const errors: Record<string, string> = {};
    const title = String(this.editForm.value.title ?? '').trim();
    const description = String(this.editForm.value.description ?? '').trim();
    if (!title) errors['title'] = 'Title is required.';
    else if (title.length > 255) errors['title'] = 'Title may not be longer than 255 characters.';
    if (!description) errors['description'] = 'Description is required.';
    if (Object.keys(errors).length > 0) {
      this.editErrors.set(errors);
      this.editForm.markAllAsTouched();
      return;
    }

    const payload: MaintenanceRequestUpdatePayload = {
      title,
      description,
      priority: (this.editForm.value.priority ?? request.priority) as MaintenancePriority,
    };

    this.isEditSubmitting.set(true);
    this.editErrors.set({});
    this.editMessage.set('');

    this.maintenanceService.updateRequest(request.id, payload).subscribe({
      next: (res) => {
        this.isEditSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Update failed', res.message);
          return;
        }
        this.toast.success('Request updated', res.data.title);
        this.closeEditForm();
        this.refreshAfterRequestChange(request.id);
      },
      error: (err) => {
        this.isEditSubmitting.set(false);
        const info = mapMaintenanceError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.editErrors.set(info.fieldErrors);
        } else {
          this.editMessage.set(info.message);
        }
      },
    });
  }

  // ─── Workflow ─────────────────────────────────────────────────────────────

  requestApprove(request: MaintenanceRequest): void {
    this.confirmRequest.set({
      title: 'Approve request?',
      message: `Approve "${request.title}" for maintenance work?`,
      confirmLabel: 'Approve',
      confirmVariant: 'primary',
      action: () => this.runStatusChange(request, 'APPROVED'),
    });
  }

  requestStartWork(request: MaintenanceRequest): void {
    this.startWorkForm.reset({ description: '', technician_id: null });
    this.startErrors.set({});
    this.startMessage.set('');
    this.startTarget.set(request);
    this.loadMaintenanceUsers();
    this.isStartOpen.set(true);
  }

  requestComplete(request: MaintenanceRequest): void {
    this.confirmRequest.set({
      title: 'Complete request?',
      message: `Mark "${request.title}" as completed? Add the completed work result before closing this request.`,
      confirmLabel: 'Complete',
      confirmVariant: 'primary',
      action: () => this.runStatusChange(request, 'COMPLETED'),
    });
  }

  requestCancel(request: MaintenanceRequest): void {
    this.confirmRequest.set({
      title: 'Cancel request?',
      message: `Cancel "${request.title}"? This ends the request and cannot be reopened.`,
      confirmLabel: 'Cancel Request',
      confirmVariant: 'danger',
      action: () => this.runStatusChange(request, 'CANCELLED'),
    });
  }

  private runStatusChange(request: MaintenanceRequest, status: MaintenanceStatus): void {
    this.maintenanceService.updateRequest(request.id, { status }).subscribe({
      next: (res) => {
        if (!res.success) {
          this.toast.danger('Status update failed', res.message);
          return;
        }
        this.toast.success('Request updated', `${res.data.title} → ${STATUS_LABELS[status]}`);
        this.refreshAfterRequestChange(request.id);
      },
      error: (err) => {
        const info = mapMaintenanceError(err);
        if (info.workflowRejected) {
          this.toast.warning('Status unchanged', info.message);
          this.loadDetail(request.id);
        } else {
          this.toast.danger('Status update failed', info.message);
        }
      },
    });
  }

  // ─── Start Work ───────────────────────────────────────────────────────────

  closeStartWorkForm(): void {
    this.isStartOpen.set(false);
    this.startTarget.set(null);
    this.startErrors.set({});
    this.startMessage.set('');
  }

  clearStartError(field: string): void {
    this.startErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
    this.startMessage.set('');
  }

  submitStartWork(): void {
    if (this.isStartSubmitting()) return;
    const request = this.startTarget();
    if (!request) return;

    const errors: Record<string, string> = {};
    const description = String(this.startWorkForm.value.description ?? '').trim();
    if (!description) errors['description'] = 'Describe the work being started.';
    if (Object.keys(errors).length > 0) {
      this.startErrors.set(errors);
      this.startWorkForm.markAllAsTouched();
      return;
    }

    const payload = {
      maintenance_request_id: request.id,
      description,
      technician_id: this.startWorkForm.value.technician_id
        ? Number(this.startWorkForm.value.technician_id)
        : null,
    };

    this.isStartSubmitting.set(true);
    this.startErrors.set({});
    this.startMessage.set('');

    this.maintenanceService.createRecord(payload).subscribe({
      next: (res) => {
        this.isStartSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Unable to start work', res.message);
          return;
        }
        this.toast.success('Work started', `${request.title} is now In Progress`);
        this.closeStartWorkForm();
        this.refreshAfterRequestChange(request.id);
      },
      error: (err) => {
        this.isStartSubmitting.set(false);
        const info = mapMaintenanceError(err);
        if (info.workflowRejected) {
          this.toast.warning('Status unchanged', info.message);
          this.loadDetail(request.id);
        } else if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.startErrors.set(info.fieldErrors);
        } else {
          this.startMessage.set(info.message);
        }
      },
    });
  }

  // ─── Assignment ───────────────────────────────────────────────────────────

  openAssignDialog(request: MaintenanceRequest): void {
    this.loadMaintenanceUsers();
    this.assignTarget.set(request);
    this.assigneeForm.reset({ user_id: request.assignee?.id ?? null });
    this.assignErrors.set({});
    this.assignMessage.set('');
    this.isAssignOpen.set(true);
  }

  closeAssignDialog(): void {
    this.isAssignOpen.set(false);
    this.assignTarget.set(null);
    this.assignErrors.set({});
    this.assignMessage.set('');
  }

  clearAssignError(field: string): void {
    this.assignErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
    this.assignMessage.set('');
  }

  submitAssignment(): void {
    if (this.isAssignSubmitting()) return;
    const target = this.assignTarget();
    if (!target) return;

    const userId = this.assigneeForm.value.user_id;
    if (!userId) {
      this.assignErrors.set({ user_id: 'Select an assignee.' });
      this.assigneeForm.markAllAsTouched();
      return;
    }

    this.isAssignSubmitting.set(true);
    this.assignErrors.set({});
    this.assignMessage.set('');

    this.maintenanceService.updateRequest(target.id, { assigned_to: Number(userId) }).subscribe({
      next: (res) => {
        this.isAssignSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Assignment failed', res.message);
          return;
        }
        this.toast.success('Assignment updated', `Assigned to ${res.data.assignee?.name ?? 'someone'}`);
        this.closeAssignDialog();
        this.refreshAfterRequestChange(target.id);
      },
      error: (err) => {
        this.isAssignSubmitting.set(false);
        const info = mapMaintenanceError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.assignErrors.set(info.fieldErrors);
        } else {
          this.assignMessage.set(info.message);
        }
      },
    });
  }

  // ─── Record edit ──────────────────────────────────────────────────────────

  openRecordEdit(record: MaintenanceRecord, request: MaintenanceRequest): void {
    this.loadMaintenanceUsers();
    this.editingRecord.set(record);
    this.recordTargetRequest.set(request);
    this.recordEditForm.reset({
      description: record.description,
      started_at: this.toDateTimeLocal(record.started_at),
      completed_at: this.toDateTimeLocal(record.completed_at),
      result: record.result ?? '',
      cost: record.cost ?? '',
      technician_id: record.technician?.id ?? null,
    });
    this.recordEditErrors.set({});
    this.recordEditMessage.set('');
    this.isRecordEditOpen.set(true);
  }

  closeRecordEdit(): void {
    this.isRecordEditOpen.set(false);
    this.editingRecord.set(null);
    this.recordTargetRequest.set(null);
    this.recordEditErrors.set({});
    this.recordEditMessage.set('');
  }

  clearRecordEditError(field: string): void {
    this.recordEditErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
    this.recordEditMessage.set('');
  }

  submitRecordEdit(): void {
    if (this.isRecordEditSubmitting()) return;
    const record = this.editingRecord();
    const request = this.recordTargetRequest();
    if (!record || !request) return;

    const errors: Record<string, string> = {};
    const description = String(this.recordEditForm.value.description ?? '').trim();
    if (!description) errors['description'] = 'Description is required.';
    const costValue = this.recordEditForm.value.cost;
    if (costValue !== null && costValue !== '' && Number(costValue) < 0) {
      errors['cost'] = 'Cost may not be negative.';
    }
    if (Object.keys(errors).length > 0) {
      this.recordEditErrors.set(errors);
      this.recordEditForm.markAllAsTouched();
      return;
    }

    const payload: MaintenanceRecordUpdatePayload = {
      description,
      started_at: this.toIso(this.recordEditForm.value.started_at),
      completed_at: this.toIso(this.recordEditForm.value.completed_at),
      result: this.recordEditForm.value.result?.trim() ? this.recordEditForm.value.result.trim() : null,
      cost: costValue !== null && costValue !== '' ? Number(costValue) : null,
      technician_id: this.recordEditForm.value.technician_id
        ? Number(this.recordEditForm.value.technician_id)
        : null,
    };

    this.isRecordEditSubmitting.set(true);
    this.recordEditErrors.set({});
    this.recordEditMessage.set('');

    this.maintenanceService.updateRecord(record.id, payload).subscribe({
      next: (res) => {
        this.isRecordEditSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Record update failed', res.message);
          return;
        }
        this.toast.success('Work record updated', res.data.asset?.name ?? '');
        this.closeRecordEdit();
        this.refreshAfterRequestChange(request.id);
      },
      error: (err) => {
        this.isRecordEditSubmitting.set(false);
        const info = mapMaintenanceError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.recordEditErrors.set(info.fieldErrors);
        } else {
          this.recordEditMessage.set(info.message);
        }
      },
    });
  }

  // ─── Parts (trace-only journal) ───────────────────────────────────────────

  openPartForm(record: MaintenanceRecord): void {
    this.loadItemOptions('');
    this.partTargetRecord.set(record);
    this.partForm.reset({ item_id: null, quantity: 1 });
    this.partErrors.set({});
    this.partMessage.set('');
    this.partItemSearch.set('');
    this.isPartOpen.set(true);
  }

  onPartItemSearch(value: string): void {
    this.partItemSearch.set(value);
    this.loadItemOptions(value);
  }

  closePartForm(): void {
    this.isPartOpen.set(false);
    this.partTargetRecord.set(null);
    this.partErrors.set({});
    this.partMessage.set('');
  }

  clearPartError(field: string): void {
    this.partErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
    this.partMessage.set('');
  }

  /**
   * Add a part to the work record. This only appends to the trace journal —
   * it never calls any stock endpoint; inventory is owned by the Inventory
   * module and the backend never generates a movement from a part.
   */
  submitPartForm(): void {
    if (this.isPartSubmitting()) return;
    const record = this.partTargetRecord();
    if (!record) return;

    const errors: Record<string, string> = {};
    const itemId = this.partForm.value.item_id;
    const quantity = this.partForm.value.quantity;
    if (!itemId) errors['item_id'] = 'Select an item.';
    if (!quantity || !Number.isInteger(Number(quantity)) || Number(quantity) < 1) {
      errors['quantity'] = 'Quantity must be a whole number of at least 1.';
    }
    if (Object.keys(errors).length > 0) {
      this.partErrors.set(errors);
      this.partForm.markAllAsTouched();
      return;
    }

    const payload: MaintenancePartPayload = {
      item_id: Number(itemId),
      quantity: Number(quantity),
    };

    this.isPartSubmitting.set(true);
    this.partErrors.set({});
    this.partMessage.set('');

    this.maintenanceService.createPart(record.id, payload).subscribe({
      next: (res) => {
        this.isPartSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Part not recorded', res.message);
          return;
        }
        this.toast.success('Part recorded', res.data.item?.name ?? '');
        const request = this.selectedRequest();
        if (request) {
          this.closePartForm();
          this.refreshAfterRequestChange(request.id);
        }
      },
      error: (err) => {
        this.isPartSubmitting.set(false);
        const info = mapMaintenanceError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.partErrors.set(info.fieldErrors);
        } else {
          this.partMessage.set(info.message);
        }
      },
    });
  }

  // ─── Confirm dialog ───────────────────────────────────────────────────────

  closeConfirm(): void {
    this.confirmRequest.set(null);
  }

  executeConfirm(): void {
    const request = this.confirmRequest();
    if (!request) return;
    this.confirmRequest.set(null);
    request.action();
  }

  // ─── Internal helpers ─────────────────────────────────────────────────────

  private sortParams(): Pick<MaintenanceRequestListFilters, 'sort' | 'direction'> {
    switch (this.sortOption()) {
      case 'requested_asc':
        return { sort: 'requested_at', direction: 'asc' };
      case 'updated_desc':
        return { sort: 'updated_at', direction: 'desc' };
      case 'priority_desc':
        return { sort: 'priority', direction: 'desc' };
      default:
        return { sort: 'requested_at', direction: 'desc' };
    }
  }
}