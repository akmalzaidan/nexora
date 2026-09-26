import { HttpErrorResponse } from '@angular/common/http';

import { Component, inject, signal, computed, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { IonIcon } from '@ionic/angular';

import {
  AssetService,
  Asset,
  AssetStatus,
  AssetCondition,
  AssetPayload,
  AssignmentPayload,
  AssetAssignmentItem,
  QrAssetMetadata,
  AssetListFilters,
  AssetCategoryItem,
  LocationRecord,
  ManagedUser,
  mapAssetError,
  AssetErrorInfo,
} from '../../core/services/asset.service';
import { AuthorizationService } from '../../core/services/authorization.service';
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

const STATUS_LABELS: Record<AssetStatus, string> = {
  DRAFT: 'Draft',
  ACTIVE: 'Active',
  INACTIVE: 'Inactive',
  MAINTENANCE: 'Maintenance',
  RETIRED: 'Retired',
  LOST: 'Lost',
  DISPOSED: 'Disposed',
};

const CONDITION_LABELS: Record<AssetCondition, string> = {
  GOOD: 'Good',
  FAIR: 'Fair',
  POOR: 'Poor',
  DAMAGED: 'Damaged',
  FAILED: 'Failed',
};

type BadgeStatus = 'active' | 'inactive' | 'pending' | 'maintenance' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';

const STATUS_BADGE: Record<string, BadgeStatus> = {
  DRAFT: 'neutral',
  ACTIVE: 'active',
  INACTIVE: 'inactive',
  MAINTENANCE: 'maintenance',
  RETIRED: 'inactive',
  LOST: 'danger',
  DISPOSED: 'inactive',
};

/** A single confirm-dialog request with the action to run on confirm. */
interface ConfirmRequest {
  title: string;
  message: string;
  confirmLabel: string;
  action: () => void;
}

interface AssignModel {
  user_id: number | null;
  location_id: number | null;
  notes: string | null;
}

const SEARCH_DEBOUNCE_MS = 300;

/**
 * AssetsPage — master-detail asset management workspace.
 *
 * Read-only users (view_assets) get the list and detail. Mutation controls
 * (create/edit/delete → manage_assets, assign/return → manage_asset_assignments)
 * are driven by AuthorizationService through the NxHasPermissionDirective.
 * QR identity is resolved through the backend QR endpoint; the manual lookup
 * field uses the QR identifier endpoint.
 */
@Component({
  selector: 'app-assets',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
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
  templateUrl: './assets.page.html',
  styleUrl: './assets.page.scss',
})
export class AssetsPage implements OnInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly assetService = inject(AssetService);
  private readonly authorization = inject(AuthorizationService);
  private readonly palette = inject(CommandPaletteService);
  private readonly toast = inject(NxToastService);

  readonly STATUS_LABEL: Record<string, string> = STATUS_LABELS;
  readonly CONDITION_LABEL: Record<string, string> = CONDITION_LABELS;

  // ─── List state ────────────────────────────────────────────────────────────

  readonly assets = signal<Asset[]>([]);
  readonly totalAssets = signal(0);
  readonly currentPage = signal(1);
  readonly lastPage = signal(1);
  readonly isLoading = signal(true);
  readonly errorState = signal<AssetErrorInfo | null>(null);
  readonly searchQuery = signal('');
  readonly statusFilter = signal<AssetStatus | ''>('');
  readonly categoryFilter = signal<number | ''>('');
  readonly locationFilter = signal<number | ''>('');
  readonly categories = signal<AssetCategoryItem[]>([]);
  readonly locations = signal<LocationRecord[]>([]);
  readonly perPage = 15;

  // ─── Detail state ──────────────────────────────────────────────────────────

  readonly selectedAssetId = signal<number | null>(null);
  readonly detailAsset = signal<Asset | null>(null);
  readonly detailLoading = signal(false);
  readonly detailError = signal<AssetErrorInfo | null>(null);
  readonly qrMetadata = signal<QrAssetMetadata | null>(null);
  readonly activeAssignment = signal<AssetAssignmentItem | null>(null);

  // ─── Permissions ───────────────────────────────────────────────────────────

  readonly hasManageAssets = computed(() => this.authorization.hasPermission('manage_assets'));
  readonly hasManageAssignments = computed(() => this.authorization.hasPermission('manage_asset_assignments'));
  readonly hasViewAssignments = computed(() => this.authorization.hasPermission('view_asset_assignments'));
  readonly canViewAssignments = computed(() => this.hasViewAssignments() || this.hasManageAssignments());

  readonly selectedAsset = computed(() => {
    const id = this.selectedAssetId();
    if (id === null) return null;
    return this.detailAsset();
  });

  readonly activeFilterCount = computed(() => {
    let count = 0;
    if (this.searchQuery()) count++;
    if (this.statusFilter()) count++;
    if (this.categoryFilter()) count++;
    if (this.locationFilter()) count++;
    return count;
  });

  readonly qrIdentity = computed<{ identifier: string; payload: string } | null>(() => {
    const meta = this.qrMetadata();
    if (meta) {
      return { identifier: meta.identifier, payload: meta.payload };
    }
    const asset = this.selectedAsset();
    if (!asset) return null;
    return { identifier: asset.asset_code, payload: `NEXORA:ASSET:${asset.asset_code}` };
  });

  // ─── Form state (create / edit) ────────────────────────────────────────────

  readonly isFormOpen = signal(false);
  readonly editingAsset = signal<Asset | null>(null);
  readonly formErrors = signal<Record<string, string>>({});
  readonly isSubmitting = signal(false);

  formModel: AssetPayload = {
    asset_code: '',
    name: '',
    asset_category_id: null,
    status: 'DRAFT',
    condition: 'GOOD',
    description: null,
    serial_number: null,
    purchase_date: null,
    purchase_price: null,
    warranty_expiry: null,
    location_id: null,
    current_user_id: null,
  };

  // ─── Confirm dialog ─────────────────────────────────────────────────────────

  readonly confirmRequest = signal<ConfirmRequest | null>(null);

  // ─── Assignment modal ──────────────────────────────────────────────────────

  readonly assignOpen = signal(false);
  readonly assignTarget = signal<Asset | null>(null);
  readonly assignUsers = signal<ManagedUser[]>([]);
  readonly assignSaving = signal(false);
  readonly assignErrors = signal<Record<string, string>>({});

  assignModel: AssignModel = { user_id: null, location_id: null, notes: null };

  // ─── QR lookup ────────────────────────────────────────────────────────────

  readonly lookupInput = signal('');
  readonly lookupBusy = signal(false);

  // ─── Misc ──────────────────────────────────────────────────────────────────

  readonly statusOptions: AssetStatus[] = ['DRAFT', 'ACTIVE', 'INACTIVE', 'MAINTENANCE', 'RETIRED', 'LOST', 'DISPOSED'];
  readonly conditionOptions: AssetCondition[] = ['GOOD', 'FAIR', 'POOR', 'DAMAGED', 'FAILED'];
  readonly skeletonRows = [1, 2, 3, 4, 5];

  private searchTimer: ReturnType<typeof setTimeout> | null = null;
  private loadSeq = 0;

  trackByAssetId = (_index: number, asset: Asset): number => asset.id;

  // ─── Lifecycle ─────────────────────────────────────────────────────────────

  ngOnInit(): void {
    this.loadLookups();
    this.loadAssets(true);

    this.route.queryParams.subscribe(params => {
      const raw = params['selected'];
      if (raw === undefined || raw === null) return;
      const id = Number(raw);
      if (!Number.isInteger(id) || id <= 0) return;
      if (this.selectedAssetId() === id) return;
      this.selectedAssetId.set(id);
      this.loadDetail(id);
    });
  }

  ngOnDestroy(): void {
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
    }
  }

  // ─── Data loading ───────────────────────────────────────────────────────────

  private loadLookups(): void {
    this.assetService.listCategories({ per_page: 100 }).subscribe({
      next: (res) => {
        if (res.success) {
          this.categories.set(res.data.items);
        }
      },
      error: () => { /* non-fatal */ },
    });

    this.assetService.listLocations({ per_page: 100 }).subscribe({
      next: (res) => {
        if (res.success) {
          this.locations.set(res.data.items);
        }
      },
      error: () => { /* non-fatal */ },
    });
  }

  loadAssets(refresh = false): void {
    if (refresh) {
      this.isLoading.set(true);
    }
    const seq = ++this.loadSeq;

    const filters: AssetListFilters = {
      page: this.currentPage(),
      per_page: this.perPage,
      sort: 'asset_code',
      direction: 'asc',
    };
    const search = this.searchQuery().trim();
    if (search) filters.search = search;
    if (this.statusFilter()) filters.status = this.statusFilter() as AssetStatus;
    if (this.categoryFilter()) filters.asset_category_id = this.categoryFilter();
    if (this.locationFilter()) filters.location_id = this.locationFilter();

    this.assetService.listAssets(filters).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;
        this.isLoading.set(false);
        if (res.success) {
          this.assets.set(res.data.items);
          this.totalAssets.set(res.data.pagination.total);
          this.currentPage.set(res.data.pagination.current_page);
          this.lastPage.set(res.data.pagination.last_page);
          this.errorState.set(null);
        }
      },
      error: (err) => {
        if (seq !== this.loadSeq) return;
        this.isLoading.set(false);
        const info = mapAssetError(err);
        this.errorState.set(info);
        if (this.assets().length === 0) {
          this.toast.danger('Unable to load assets', info.message);
        }
      },
    });
  }

  refreshList(): void {
    this.loadAssets(true);
  }

  // ─── Search & filters ────────────────────────────────────────────────────────

  onSearch(value: string): void {
    this.searchQuery.set(value);
    this.currentPage.set(1);
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
    }
    this.searchTimer = setTimeout(() => this.loadAssets(true), SEARCH_DEBOUNCE_MS);
  }

  setStatusFilter(status: AssetStatus | ''): void {
    this.statusFilter.set(status);
    this.currentPage.set(1);
    this.loadAssets(true);
  }

  setCategoryFilter(id: number | ''): void {
    this.categoryFilter.set(id);
    this.currentPage.set(1);
    this.loadAssets(true);
  }

  setLocationFilter(id: number | ''): void {
    this.locationFilter.set(id);
    this.currentPage.set(1);
    this.loadAssets(true);
  }

  clearFilters(): void {
    this.searchQuery.set('');
    this.statusFilter.set('');
    this.categoryFilter.set('');
    this.locationFilter.set('');
    this.currentPage.set(1);
    this.loadAssets(true);
  }

  goToPage(page: number): void {
    const last = this.lastPage();
    const clamped = Math.max(1, Math.min(page, last));
    if (clamped === this.currentPage()) return;
    this.currentPage.set(clamped);
    this.loadAssets(true);
  }

  // ─── Selection & detail ───────────────────────────────────────────────────────

  selectAsset(asset: Asset): void {
    this.setSelected(asset.id);
  }

  private setSelected(id: number): void {
    this.selectedAssetId.set(id);
    this.router.navigate([], {
      queryParams: { selected: id },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    this.loadDetail(id);
  }

  clearSelection(): void {
    this.selectedAssetId.set(null);
    this.detailAsset.set(null);
    this.activeAssignment.set(null);
    this.qrMetadata.set(null);
    this.router.navigate([], {
      queryParams: { selected: null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  retryDetail(): void {
    const id = this.selectedAssetId();
    if (id !== null) {
      this.loadDetail(id);
    }
  }

  loadDetail(id: number): void {
    this.detailAsset.set(null);
    this.activeAssignment.set(null);
    this.qrMetadata.set(null);
    this.detailLoading.set(true);
    this.detailError.set(null);

    this.assetService.getAsset(id).subscribe({
      next: (res) => {
        this.detailLoading.set(false);
        if (!res.success || !res.data) return;
        this.detailAsset.set(res.data);
        this.assets.update(list => (list.some(a => a.id === res.data.id) ? list : [...list, res.data]));
        this.loadQr(id);
        if (this.canViewAssignments()) {
          this.loadActiveAssignment(id);
        }
      },
      error: (err) => {
        this.detailLoading.set(false);
        this.detailError.set(mapAssetError(err));
      },
    });
  }

  private loadQr(id: number): void {
    this.qrMetadata.set(null);
    this.assetService.getQrMetadata(id).subscribe({
      next: (res) => {
        if (res.success && res.data) {
          this.qrMetadata.set(res.data);
        }
      },
      error: () => { /* derived identity is shown as fallback */ },
    });
  }

  private loadActiveAssignment(id: number): void {
    this.activeAssignment.set(null);
    this.assetService.listAssignments({ asset_id: id, status: 'ACTIVE', per_page: 1 }).subscribe({
      next: (res) => {
        this.activeAssignment.set(res.success ? (res.data.items[0] ?? null) : null);
      },
      error: () => { /* non-fatal */ },
    });
  }

  // ─── Command palette ─────────────────────────────────────────────────────────

  openPalette(): void {
    this.palette.open();
  }

  // ─── Presentation helpers ────────────────────────────────────────────────────

  statusToBadge(status: string): BadgeStatus {
    return STATUS_BADGE[status] ?? 'neutral';
  }

  // ─── Form: create / edit ───────────────────────────────────────────────────────

  openCreateForm(): void {
    this.editingAsset.set(null);
    this.formModel = {
      asset_code: '',
      name: '',
      asset_category_id: null,
      status: 'DRAFT',
      condition: 'GOOD',
      description: null,
      serial_number: null,
      purchase_date: null,
      purchase_price: null,
      warranty_expiry: null,
      location_id: null,
      current_user_id: null,
    };
    this.formErrors.set({});
    this.isFormOpen.set(true);
  }

  openEditForm(asset: Asset): void {
    this.editingAsset.set(asset);
    this.formModel = {
      asset_code: asset.asset_code,
      name: asset.name,
      asset_category_id: asset.category?.id ?? null,
      status: asset.status,
      condition: asset.condition,
      description: asset.description,
      serial_number: asset.serial_number,
      purchase_date: asset.purchase_date,
      purchase_price: asset.purchase_price,
      warranty_expiry: asset.warranty_expiry,
      location_id: asset.location?.id ?? null,
      current_user_id: asset.current_user?.id ?? null,
    };
    this.formErrors.set({});
    this.isFormOpen.set(true);
  }

  closeForm(): void {
    this.isFormOpen.set(false);
    this.editingAsset.set(null);
    this.formErrors.set({});
  }

  onFormFieldChange(field: keyof AssetPayload, value: string | number | null): void {
    (this.formModel as Partial<AssetPayload>)[field] = value as never;
    this.formErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
  }

  submitForm(): void {
    const payload = this.buildPayload();
    const fieldErrors: Record<string, string> = {};
    if (!payload.asset_code.trim()) fieldErrors['asset_code'] = 'Asset code is required.';
    if (!payload.name.trim()) fieldErrors['name'] = 'Asset name is required.';
    if (!payload.asset_category_id) fieldErrors['asset_category_id'] = 'Asset category is required.';

    if (Object.keys(fieldErrors).length > 0) {
      this.formErrors.set(fieldErrors);
      return;
    }

    this.isSubmitting.set(true);
    this.formErrors.set({});

    const obs = this.editingAsset()
      ? this.assetService.updateAsset(this.editingAsset()!.id, payload)
      : this.assetService.createAsset(payload);

    obs.subscribe({
      next: (res) => {
        this.isSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Form submission failed', res.message);
          return;
        }
        this.toast.success(this.editingAsset() ? 'Asset updated' : 'Asset created', res.data.asset_code);
        this.closeForm();
        this.refreshList();
        this.setSelected(res.data.id);
      },
      error: (err) => {
        this.isSubmitting.set(false);
        const info = mapAssetError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.formErrors.set(info.fieldErrors);
        } else {
          this.toast.danger('Form submission failed', info.message);
        }
      },
    });
  }

  private buildPayload(): AssetPayload {
    const m = this.formModel;
    return {
      asset_category_id: m.asset_category_id,
      asset_code: m.asset_code.trim(),
      name: m.name.trim(),
      description: m.description ?? null,
      serial_number: m.serial_number ?? null,
      status: m.status,
      condition: m.condition,
      purchase_date: m.purchase_date ?? null,
      purchase_price: m.purchase_price ?? null,
      warranty_expiry: m.warranty_expiry ?? null,
      location_id: m.location_id ?? null,
      current_user_id: m.current_user_id ?? null,
    };
  }

  // ─── Delete ───────────────────────────────────────────────────────────────────

  requestDelete(asset: Asset): void {
    this.confirmRequest.set({
      title: 'Delete asset?',
      message: `"${asset.asset_code}" will be permanently removed. This action cannot be undone. Note: assets with assignment or history records cannot be deleted.`,
      confirmLabel: 'Delete',
      action: () => this.runDelete(asset.id),
    });
  }

  private runDelete(id: number): void {
    this.isSubmitting.set(true);
    this.assetService.deleteAsset(id).subscribe({
      next: (res) => {
        this.isSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Delete failed', res.message);
          return;
        }
        this.toast.success('Asset deleted', 'The asset has been removed.');
        if (this.selectedAssetId() === id) {
          this.clearSelection();
        }
        this.refreshList();
      },
      error: (err) => {
        this.isSubmitting.set(false);
        const info = mapAssetError(err);
        if (err instanceof HttpErrorResponse && err.status === 409) {
          this.toast.warning('Cannot delete asset', info.message);
        } else {
          this.toast.danger('Delete failed', info.message);
        }
      },
    });
  }

  // ─── Assignment ───────────────────────────────────────────────────────────────

  openAssignForm(asset: Asset): void {
    if (this.assignUsers().length === 0) {
      this.assetService.listUsers({ per_page: 100 }).subscribe({
        next: (res) => {
          if (res.success) {
            this.assignUsers.set(res.data.items);
          }
        },
        error: () => { /* non-fatal */ },
      });
    }
    this.assignTarget.set(asset);
    this.assignModel = { user_id: null, location_id: null, notes: null };
    this.assignErrors.set({});
    this.assignOpen.set(true);
  }

  closeAssign(): void {
    this.assignOpen.set(false);
    this.assignTarget.set(null);
    this.assignErrors.set({});
  }

  onAssignFieldChange(field: keyof AssignModel, value: string | number | null): void {
    (this.assignModel as Partial<AssignModel>)[field] = value as never;
    this.assignErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
  }

  submitAssign(): void {
    const asset = this.assignTarget();
    if (!asset || this.assignSaving()) return;

    const errors: Record<string, string> = {};
    if (!this.assignModel.user_id) {
      errors['user_id'] = 'Choose a user to assign this asset to.';
    }
    if (Object.keys(errors).length > 0) {
      this.assignErrors.set(errors);
      return;
    }

    this.assignSaving.set(true);
    this.assignErrors.set({});

    const payload: AssignmentPayload = {
      asset_id: asset.id,
      user_id: Number(this.assignModel.user_id),
      location_id: this.assignModel.location_id ? Number(this.assignModel.location_id) : null,
      notes: this.assignModel.notes?.trim() ? this.assignModel.notes.trim() : null,
    };

    this.assetService.createAssignment(payload).subscribe({
      next: (res) => {
        this.assignSaving.set(false);
        if (!res.success) {
          this.toast.danger('Assignment failed', res.message);
          return;
        }
        this.toast.success('Asset assigned', `${asset.asset_code} assigned successfully.`);
        this.closeAssign();
        this.refreshList();
        this.loadDetail(asset.id);
      },
      error: (err) => {
        this.assignSaving.set(false);
        const info = mapAssetError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.assignErrors.set(info.fieldErrors);
        } else {
          this.toast.danger('Assignment failed', info.message);
        }
      },
    });
  }

  requestReturn(): void {
    const assignment = this.activeAssignment();
    if (!assignment) return;
    this.confirmRequest.set({
      title: 'Return asset?',
      message: `Mark "${this.selectedAsset()?.asset_code ?? 'this asset'}" as returned from ${assignment.user?.name ?? 'its current holder'}.`,
      confirmLabel: 'Return',
      action: () => this.runReturn(assignment.id),
    });
  }

  private runReturn(assignmentId: number): void {
    this.isSubmitting.set(true);
    this.assetService.returnAssignment(assignmentId).subscribe({
      next: (res) => {
        this.isSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Return failed', res.message);
          return;
        }
        this.toast.success('Asset returned', 'The asset has been returned.');
        const id = this.selectedAssetId();
        if (id !== null) {
          this.loadDetail(id);
        }
        this.refreshList();
      },
      error: (err) => {
        this.isSubmitting.set(false);
        const info = mapAssetError(err);
        this.toast.danger('Return failed', info.message);
      },
    });
  }

  // ─── Confirm dialog ─────────────────────────────────────────────────────────

  closeConfirm(): void {
    this.confirmRequest.set(null);
  }

  executeConfirm(): void {
    const request = this.confirmRequest();
    if (!request) return;
    this.confirmRequest.set(null);
    request.action();
  }

  // ─── QR lookup ─────────────────────────────────────────────────────────────

  onLookupInput(value: string): void {
    this.lookupInput.set(value);
  }

  clearLookup(): void {
    this.lookupInput.set('');
  }

  lookupQr(): void {
    const identifier = this.lookupInput().trim();
    if (!identifier) return;

    this.lookupBusy.set(true);
    this.assetService.lookupQr(identifier).subscribe({
      next: (res) => {
        this.lookupBusy.set(false);
        if (!res.success || !res.data) {
          this.toast.warning('QR lookup failed', res.message ?? 'Asset not found.');
          return;
        }
        this.toast.info('QR lookup', `Found ${res.data.asset_code}.`);
        this.lookupInput.set('');
        this.setSelected(res.data.id);
      },
      error: (err) => {
        this.lookupBusy.set(false);
        const info = mapAssetError(err);
        this.toast.danger('QR lookup failed', info.message);
      },
    });
  }
}