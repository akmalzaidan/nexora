import { HttpErrorResponse } from '@angular/common/http';

import { Component, inject, signal, computed, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormGroup, FormControl, Validators } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { IonIcon } from '@ionic/angular';

import {
  InventoryService,
  InventoryItem,
  ItemCategory,
  InventoryWarehouse,
  StockMovement,
  MovementType,
  ItemPayload,
  CategoryPayload,
  WarehousePayload,
  StockMovementPayload,
  ItemListFilters,
  mapInventoryError,
  InventoryErrorInfo,
} from '../../core/services/inventory.service';
import { AssetService, LocationRecord } from '../../core/services/asset.service';
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

const SEARCH_DEBOUNCE_MS = 300;

type WorkspaceView = 'items' | 'categories' | 'warehouses' | 'movements';

type BadgeStatus = 'success' | 'warning' | 'info' | 'neutral';

/** A single confirm-dialog request with the action to run on confirm. */
interface ConfirmRequest {
  title: string;
  message: string;
  confirmLabel: string;
  action: () => void;
}

const MOVEMENT_LABELS: Record<MovementType, string> = {
  STOCK_IN: 'Stock In',
  STOCK_OUT: 'Stock Out',
};

const MOVEMENT_BADGE: Record<MovementType, BadgeStatus> = {
  STOCK_IN: 'success',
  STOCK_OUT: 'info',
};

function isIntegerText(value: string): boolean {
  return /^\d+$/.test(value);
}

/**
 * InventoryPage — inventory management workspace.
 *
 * A permission-aware, master-detail workspace over item categories, items,
 * warehouses, and the immutable stock movement journal. Stock balance is
 * always rendered from the backend (item.stock), never recomputed here.
 * Mutations are gated by AuthorizationService: item/category/warehouse
 * administration requires `manage_inventory`, stock in/out requires
 * `manage_stock`.
 */
@Component({
  selector: 'app-inventory',
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
  templateUrl: './inventory.page.html',
  styleUrl: './inventory.page.scss',
})
export class InventoryPage implements OnInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly inventory = inject(InventoryService);
  private readonly assetService = inject(AssetService);
  private readonly authorization = inject(AuthorizationService);
  private readonly palette = inject(CommandPaletteService);
  private readonly toast = inject(NxToastService);

  readonly MOVEMENT_LABEL: Record<string, string> = MOVEMENT_LABELS;

  // ─── View state ───────────────────────────────────────────────────────────

  readonly activeView = signal<WorkspaceView>('items');
  readonly skeletonRows = [1, 2, 3, 4, 5];

  // ─── Permissions ───────────────────────────────────────────────────────────

  readonly hasManageInventory = computed(() => this.authorization.hasPermission('manage_inventory'));
  readonly hasManageStock = computed(() => this.authorization.hasPermission('manage_stock'));

  // ─── Summary strip ─────────────────────────────────────────────────────────

  readonly itemsTotal = signal(0);
  readonly categoriesTotal = signal(0);
  readonly warehousesTotal = signal(0);
  readonly movementsTotal = signal(0);

  // ─── Item list (master) ────────────────────────────────────────────────────

  readonly items = signal<InventoryItem[]>([]);
  readonly currentPage = signal(1);
  readonly lastPage = signal(1);
  readonly itemsLoading = signal(true);
  readonly itemsError = signal<InventoryErrorInfo | null>(null);
  readonly searchQuery = signal('');
  readonly categoryFilter = signal<number | ''>('');
  readonly warehouseFilter = signal<number | ''>('');
  readonly categories = signal<ItemCategory[]>([]);
  readonly warehouses = signal<InventoryWarehouse[]>([]);
  readonly perPage = 15;

  // ─── Item detail ───────────────────────────────────────────────────────────

  readonly selectedItemId = signal<number | null>(null);
  readonly detailItem = signal<InventoryItem | null>(null);
  readonly detailLoading = signal(false);
  readonly detailError = signal<InventoryErrorInfo | null>(null);

  readonly selectedItem = computed(() => {
    const id = this.selectedItemId();
    if (id === null) return null;
    return this.detailItem();
  });

  readonly activeFilterCount = computed(() => {
    let count = 0;
    if (this.searchQuery()) count++;
    if (this.categoryFilter()) count++;
    if (this.warehouseFilter()) count++;
    return count;
  });

  // ─── Categories tab ─────────────────────────────────────────────────────────

  readonly categoryPage = signal(1);
  readonly categoryLastPage = signal(1);
  readonly categoriesLoading = signal(false);
  readonly categoriesError = signal<InventoryErrorInfo | null>(null);

  // ─── Warehouses tab ─────────────────────────────────────────────────────────

  readonly warehousePage = signal(1);
  readonly warehouseLastPage = signal(1);
  readonly warehousesLoading = signal(false);
  readonly warehousesError = signal<InventoryErrorInfo | null>(null);
  readonly locations = signal<LocationRecord[]>([]);

  // ─── Movements tab ──────────────────────────────────────────────────────────

  readonly movements = signal<StockMovement[]>([]);
  readonly movementPage = signal(1);
  readonly movementLastPage = signal(1);
  readonly movementsLoading = signal(false);
  readonly movementsError = signal<InventoryErrorInfo | null>(null);
  readonly movementTypeFilter = signal<MovementType | ''>('');
  readonly movementItemFilter = signal<number | ''>('');
  readonly movementWarehouseFilter = signal<number | ''>('');
  readonly movementItems = signal<InventoryItem[]>([]);

  // ─── Form: create / edit (reactive) ─────────────────────────────────────────

  readonly isItemFormOpen = signal(false);
  readonly editingItem = signal<InventoryItem | null>(null);
  readonly isItemSubmitting = signal(false);
  readonly itemErrors = signal<Record<string, string>>({});

  itemForm = new FormGroup({
    item_category_id: new FormControl<number | null>(null, Validators.required),
    sku: new FormControl('', Validators.required),
    name: new FormControl('', Validators.required),
    unit: new FormControl('', Validators.required),
    description: new FormControl<string | null>(null),
    minimum_stock: new FormControl<number | null>(null),
    maximum_stock: new FormControl<number | null>(null),
    is_active: new FormControl(true),
  });

  // ─── Form: stock in / stock out ────────────────────────────────────────────

  readonly isStockFormOpen = signal(false);
  readonly stockType = signal<MovementType>('STOCK_IN');
  readonly stockTarget = signal<InventoryItem | null>(null);
  readonly isStockSubmitting = signal(false);
  readonly stockErrors = signal<Record<string, string>>({});
  readonly stockServerMessage = signal('');
  readonly stockInsufficient = signal(false);

  stockForm = new FormGroup({
    warehouse_id: new FormControl<number | null>(null, Validators.required),
    quantity: new FormControl<string>('', [Validators.required, Validators.min(1)]),
    notes: new FormControl<string | null>(null),
  });

  // ─── Form: category ────────────────────────────────────────────────────────

  readonly isCategoryFormOpen = signal(false);
  readonly editingCategory = signal<ItemCategory | null>(null);
  readonly isCategorySubmitting = signal(false);
  readonly categoryErrors = signal<Record<string, string>>({});

  categoryForm = new FormGroup({
    name: new FormControl('', Validators.required),
    code: new FormControl('', Validators.required),
    description: new FormControl<string | null>(null),
  });

  // ─── Form: warehouse ────────────────────────────────────────────────────────

  readonly isWarehouseFormOpen = signal(false);
  readonly editingWarehouse = signal<InventoryWarehouse | null>(null);
  readonly isWarehouseSubmitting = signal(false);
  readonly warehouseErrors = signal<Record<string, string>>({});

  warehouseForm = new FormGroup({
    name: new FormControl('', Validators.required),
    code: new FormControl('', Validators.required),
    location_id: new FormControl<number | ''>(''),
    description: new FormControl<string | null>(null),
    is_active: new FormControl(true),
  });

  // ─── Confirm dialog ─────────────────────────────────────────────────────────

  readonly confirmRequest = signal<ConfirmRequest | null>(null);

  private searchTimer: ReturnType<typeof setTimeout> | null = null;
  private loadSeq = 0;

  trackByItemId = (_index: number, item: InventoryItem): number => item.id;
  trackById = (_index: number, record: { id: number }): number => record.id;

  // ─── Lifecycle ─────────────────────────────────────────────────────────────

  ngOnInit(): void {
    this.loadItems(true);
    this.loadCategories(true);
    this.loadWarehouses(true);
    this.loadMovements(true);

    this.route.queryParams.subscribe(params => {
      const raw = params['selected'];
      if (raw === undefined || raw === null) return;
      const id = Number(raw);
      if (!Number.isInteger(id) || id <= 0) return;
      if (this.selectedItemId() === id) return;
      this.selectItem(id);
    });
  }

  ngOnDestroy(): void {
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
    }
  }

  // ─── View switching ────────────────────────────────────────────────────────

  setView(view: WorkspaceView): void {
    this.activeView.set(view);
    if (view === 'movements' && this.movementItems().length === 0) {
      this.loadMovementLookups();
    }
  }

  // ─── Data loading ───────────────────────────────────────────────────────────

  loadItems(refresh = false): void {
    if (refresh) {
      this.itemsLoading.set(true);
    }
    const seq = ++this.loadSeq;

    const filters: ItemListFilters = {
      page: this.currentPage(),
      per_page: this.perPage,
      sort: 'sku',
      direction: 'asc',
    };
    const search = this.searchQuery().trim();
    if (search) filters.search = search;
    if (this.categoryFilter()) filters.item_category_id = this.categoryFilter();
    if (this.warehouseFilter()) filters.warehouse_id = this.warehouseFilter();

    this.inventory.listItems(filters).subscribe({
      next: (res) => {
        if (seq !== this.loadSeq) return;
        this.itemsLoading.set(false);
        if (res.success) {
          this.items.set(res.data.items);
          this.itemsTotal.set(res.data.pagination.total);
          this.currentPage.set(res.data.pagination.current_page);
          this.lastPage.set(res.data.pagination.last_page);
          this.itemsError.set(null);
        }
      },
      error: (err) => {
        if (seq !== this.loadSeq) return;
        this.itemsLoading.set(false);
        const info = mapInventoryError(err);
        this.itemsError.set(info);
        if (this.items().length === 0) {
          this.toast.danger('Unable to load items', info.message);
        }
      },
    });
  }

  refreshItems(): void {
    this.loadItems(true);
  }

  loadCategories(refresh = false): void {
    if (refresh) {
      this.categoriesLoading.set(true);
    }
    this.inventory.listCategories({ page: this.categoryPage(), per_page: 100, sort: 'name', direction: 'asc' }).subscribe({
      next: (res) => {
        this.categoriesLoading.set(false);
        if (res.success) {
          this.categories.set(res.data.items);
          this.categoriesTotal.set(res.data.pagination.total);
          this.categoryPage.set(res.data.pagination.current_page);
          this.categoryLastPage.set(res.data.pagination.last_page);
          this.categoriesError.set(null);
        }
      },
      error: (err) => {
        this.categoriesLoading.set(false);
        this.categoriesError.set(mapInventoryError(err));
      },
    });
  }

  loadWarehouses(refresh = false): void {
    if (refresh) {
      this.warehousesLoading.set(true);
    }
    this.inventory.listWarehouses({ page: this.warehousePage(), per_page: 100, sort: 'name', direction: 'asc' }).subscribe({
      next: (res) => {
        this.warehousesLoading.set(false);
        if (res.success) {
          this.warehouses.set(res.data.items);
          this.warehousesTotal.set(res.data.pagination.total);
          this.warehousePage.set(res.data.pagination.current_page);
          this.warehouseLastPage.set(res.data.pagination.last_page);
          this.warehousesError.set(null);
        }
      },
      error: (err) => {
        this.warehousesLoading.set(false);
        this.warehousesError.set(mapInventoryError(err));
      },
    });
  }

  loadWarehouseLocationLookup(): void {
    if (this.locations().length > 0) return;
    this.assetService.listLocations({ per_page: 100 }).subscribe({
      next: (res) => {
        if (res.success) {
          this.locations.set(res.data.items);
        }
      },
      error: () => { /* non-fatal */ },
    });
  }

  loadMovements(refresh = false): void {
    if (refresh) {
      this.movementsLoading.set(true);
    }
    const filters: Record<string, string | number> = {
      page: this.movementPage(),
      per_page: this.perPage,
    };
    if (this.movementTypeFilter()) filters['type'] = this.movementTypeFilter() as string;
    if (this.movementItemFilter()) filters['item_id'] = this.movementItemFilter();
    if (this.movementWarehouseFilter()) filters['warehouse_id'] = this.movementWarehouseFilter();

    this.inventory.listStockMovements(filters).subscribe({
      next: (res) => {
        this.movementsLoading.set(false);
        if (res.success) {
          this.movements.set(res.data.items);
          this.movementsTotal.set(res.data.pagination.total);
          this.movementPage.set(res.data.pagination.current_page);
          this.movementLastPage.set(res.data.pagination.last_page);
          this.movementsError.set(null);
        }
      },
      error: (err) => {
        this.movementsLoading.set(false);
        this.movementsError.set(mapInventoryError(err));
      },
    });
  }

  private loadMovementLookups(): void {
    this.inventory.listItems({ per_page: 100, sort: 'sku', direction: 'asc' }).subscribe({
      next: (res) => {
        if (res.success) {
          this.movementItems.set(res.data.items);
        }
      },
      error: () => { /* non-fatal */ },
    });
  }

  // ─── Search & filters (items) ───────────────────────────────────────────────

  onSearch(value: string): void {
    this.searchQuery.set(value);
    this.currentPage.set(1);
    if (this.searchTimer !== null) {
      clearTimeout(this.searchTimer);
    }
    this.searchTimer = setTimeout(() => this.loadItems(true), SEARCH_DEBOUNCE_MS);
  }

  setCategoryFilter(id: number | ''): void {
    this.categoryFilter.set(id);
    this.currentPage.set(1);
    this.loadItems(true);
  }

  setWarehouseFilter(id: number | ''): void {
    this.warehouseFilter.set(id);
    this.currentPage.set(1);
    this.loadItems(true);
  }

  clearFilters(): void {
    this.searchQuery.set('');
    this.categoryFilter.set('');
    this.warehouseFilter.set('');
    this.currentPage.set(1);
    this.loadItems(true);
  }

  goToPage(page: number): void {
    const last = this.lastPage();
    const clamped = Math.max(1, Math.min(page, last));
    if (clamped === this.currentPage()) return;
    this.currentPage.set(clamped);
    this.loadItems(true);
  }

  goToCategoryPage(page: number): void {
    const last = this.categoryLastPage();
    const clamped = Math.max(1, Math.min(page, last));
    if (clamped === this.categoryPage()) return;
    this.categoryPage.set(clamped);
    this.loadCategories(true);
  }

  goToWarehousePage(page: number): void {
    const last = this.warehouseLastPage();
    const clamped = Math.max(1, Math.min(page, last));
    if (clamped === this.warehousePage()) return;
    this.warehousePage.set(clamped);
    this.loadWarehouses(true);
  }

  // ─── Movement filters ────────────────────────────────────────────────────────

  setMovementTypeFilter(type: MovementType | ''): void {
    this.movementTypeFilter.set(type);
    this.movementPage.set(1);
    this.loadMovements(true);
  }

  setMovementItemFilter(id: number | ''): void {
    this.movementItemFilter.set(id);
    this.movementPage.set(1);
    this.loadMovements(true);
  }

  setMovementWarehouseFilter(id: number | ''): void {
    this.movementWarehouseFilter.set(id);
    this.movementPage.set(1);
    this.loadMovements(true);
  }

  clearMovementFilters(): void {
    this.movementTypeFilter.set('');
    this.movementItemFilter.set('');
    this.movementWarehouseFilter.set('');
    this.movementPage.set(1);
    this.loadMovements(true);
  }

  goToMovementPage(page: number): void {
    const last = this.movementLastPage();
    const clamped = Math.max(1, Math.min(page, last));
    if (clamped === this.movementPage()) return;
    this.movementPage.set(clamped);
    this.loadMovements(true);
  }

  movementActiveFilters = computed(() => {
    let count = 0;
    if (this.movementTypeFilter()) count++;
    if (this.movementItemFilter()) count++;
    if (this.movementWarehouseFilter()) count++;
    return count;
  });

  // ─── Selection & detail ─────────────────────────────────────────────────────

  selectItem(id: number): void {
    this.selectedItemId.set(id);
    this.router.navigate([], {
      queryParams: { selected: id },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    this.loadDetail(id);
  }

  clearSelection(): void {
    this.selectedItemId.set(null);
    this.detailItem.set(null);
    this.router.navigate([], {
      queryParams: { selected: null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  retryDetail(): void {
    const id = this.selectedItemId();
    if (id !== null) {
      this.loadDetail(id);
    }
  }

  loadDetail(id: number): void {
    this.detailItem.set(null);
    this.detailLoading.set(true);
    this.detailError.set(null);

    this.inventory.getItem(id).subscribe({
      next: (res) => {
        this.detailLoading.set(false);
        if (!res.success || !res.data) return;
        this.detailItem.set(res.data);
      },
      error: (err) => {
        this.detailLoading.set(false);
        this.detailError.set(mapInventoryError(err));
      },
    });
  }

  // ─── Presentation helpers ───────────────────────────────────────────────────

  movementBadge(type: MovementType): BadgeStatus {
    return MOVEMENT_BADGE[type] ?? 'neutral';
  }

  /** Warehouse balance for the selected item (backend breakdown). */
  warehouseBalance(item: InventoryItem, warehouseId: number): number | null {
    const rows = item.stock.warehouses ?? [];
    const row = rows.find(r => r.warehouse.id === warehouseId);
    return row ? row.quantity : null;
  }

  quantityLabel(movement: StockMovement): string {
    const sign = movement.type === 'STOCK_IN' ? '+' : '−';
    return `${sign}${movement.quantity}`;
  }

  movementTypeClass(type: MovementType): string {
    return type === 'STOCK_IN' ? 'nx-qty-in' : 'nx-qty-out';
  }

  // ─── Command palette ─────────────────────────────────────────────────────────

  openPalette(): void {
    this.palette.open();
  }

  // ─── Item form ──────────────────────────────────────────────────────────────

  openCreateItemForm(): void {
    this.editingItem.set(null);
    this.itemForm.reset({
      item_category_id: null,
      sku: '',
      name: '',
      unit: '',
      description: null,
      minimum_stock: null,
      maximum_stock: null,
      is_active: true,
    });
    this.itemErrors.set({});
    this.isItemFormOpen.set(true);
  }

  openEditItemForm(item: InventoryItem): void {
    this.editingItem.set(item);
    this.itemForm.reset({
      item_category_id: item.category?.id ?? null,
      sku: item.sku,
      name: item.name,
      unit: item.unit,
      description: item.description ?? null,
      minimum_stock: item.minimum_stock,
      maximum_stock: item.maximum_stock,
      is_active: item.is_active,
    });
    this.itemErrors.set({});
    this.isItemFormOpen.set(true);
  }

  closeItemForm(): void {
    this.isItemFormOpen.set(false);
    this.editingItem.set(null);
    this.itemErrors.set({});
  }

  clearItemError(field: string): void {
    this.itemErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
  }

  submitItemForm(): void {
    if (this.isItemSubmitting()) return;

    const required: Array<[string, string]> = [
      ['item_category_id', 'Category is required.'],
      ['sku', 'SKU is required.'],
      ['name', 'Item name is required.'],
      ['unit', 'Unit is required.'],
    ];
    const errors: Record<string, string> = {};
    for (const [field, message] of required) {
      const control = this.itemForm.get(field);
      if (!control?.value) {
        errors[field] = message;
      }
    }

    if (Object.keys(errors).length > 0) {
      this.itemErrors.set(errors);
      this.itemForm.markAllAsTouched();
      return;
    }

    const payload = this.buildItemPayload();
    this.isItemSubmitting.set(true);
    this.itemErrors.set({});

    const obs = this.editingItem()
      ? this.inventory.updateItem(this.editingItem()!.id, payload)
      : this.inventory.createItem(payload);

    obs.subscribe({
      next: (res) => {
        this.isItemSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Item submission failed', res.message);
          return;
        }
        const wasEditing = this.editingItem() !== null;
        this.toast.success(wasEditing ? 'Item updated' : 'Item created', `${res.data.sku} · ${res.data.name}`);
        this.closeItemForm();
        this.refreshItems();
        if (wasEditing) {
          const id = this.selectedItemId();
          if (id !== null) {
            this.loadDetail(id);
          }
        } else {
          this.selectItem(res.data.id);
        }
      },
      error: (err) => {
        this.isItemSubmitting.set(false);
        const info = mapInventoryError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.itemErrors.set(info.fieldErrors);
        } else {
          this.toast.danger('Item submission failed', info.message);
        }
      },
    });
  }

  private buildItemPayload(): ItemPayload {
    const value = this.itemForm.value;
    return {
      item_category_id: Number(value.item_category_id),
      sku: String(value.sku ?? '').trim(),
      name: String(value.name ?? '').trim(),
      unit: String(value.unit ?? '').trim(),
      description: value.description?.trim() ? value.description.trim() : null,
      minimum_stock: value.minimum_stock == null ? null : Number(value.minimum_stock),
      maximum_stock: value.maximum_stock == null ? null : Number(value.maximum_stock),
      is_active: !!value.is_active,
    };
  }

  // ─── Stock in / stock out ───────────────────────────────────────────────────

  openStockForm(item: InventoryItem, type: MovementType): void {
    this.stockTarget.set(item);
    this.stockType.set(type);
    this.stockForm.reset({ warehouse_id: null, quantity: '', notes: null });
    this.stockErrors.set({});
    this.stockServerMessage.set('');
    this.stockInsufficient.set(false);
    this.isStockFormOpen.set(true);
  }

  closeStockForm(): void {
    this.isStockFormOpen.set(false);
    this.stockTarget.set(null);
    this.stockErrors.set({});
    this.stockServerMessage.set('');
    this.stockInsufficient.set(false);
  }

  clearStockError(field: string): void {
    this.stockErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
  }

  /** Balance of the selected item in a warehouse, from the backend breakdown. */
  availableFor(stockType: MovementType, warehouseId: number): number | null {
    if (stockType !== 'STOCK_OUT' || !warehouseId) return null;
    const item = this.stockTarget() ?? this.selectedItem();
    if (!item) return null;
    return this.warehouseBalance(item, warehouseId);
  }

  submitStockForm(): void {
    if (this.isStockSubmitting()) return;

    const item = this.stockTarget();
    if (!item) return;

    const errors: Record<string, string> = {};
    if (!this.stockForm.value.warehouse_id) {
      errors['warehouse_id'] = 'Warehouse is required.';
    }
    const quantityRaw = String(this.stockForm.value.quantity ?? '');
    if (!quantityRaw) {
      errors['quantity'] = 'Quantity is required.';
    } else if (!isIntegerText(quantityRaw) || Number(quantityRaw) < 1) {
      errors['quantity'] = 'Quantity must be a whole number greater than zero.';
    }
    if (Object.keys(errors).length > 0) {
      this.stockErrors.set(errors);
      this.stockForm.markAllAsTouched();
      return;
    }

    const payload: StockMovementPayload = {
      item_id: item.id,
      warehouse_id: Number(this.stockForm.value.warehouse_id),
      type: this.stockType(),
      quantity: Number(quantityRaw),
      notes: this.stockForm.value.notes?.trim() ? this.stockForm.value.notes.trim() : null,
    };

    this.isStockSubmitting.set(true);
    this.stockErrors.set({});
    this.stockServerMessage.set('');
    this.stockInsufficient.set(false);

    this.inventory.createStockMovement(payload).subscribe({
      next: (res) => {
        this.isStockSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Stock movement failed', res.message);
          return;
        }
        const received = this.stockType() === 'STOCK_IN';
        this.toast.success(received ? 'Stock received' : 'Stock issued', `${res.data.quantity} × ${item.sku}`);
        this.closeStockForm();
        this.loadDetail(item.id);
        this.refreshItems();
        this.loadMovements(true);
      },
      error: (err) => {
        this.isStockSubmitting.set(false);
        const info = mapInventoryError(err);
        if (info.insufficientStock) {
          this.stockInsufficient.set(true);
          this.stockServerMessage.set(info.message);
        } else if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.stockErrors.set(info.fieldErrors);
        } else {
          this.toast.danger(this.stockType() === 'STOCK_OUT' ? 'Unable to complete stock out' : 'Unable to complete stock in', info.message);
        }
      },
    });
  }

  // ─── Category form ──────────────────────────────────────────────────────────

  openCreateCategoryForm(): void {
    this.editingCategory.set(null);
    this.categoryForm.reset({ name: '', code: '', description: null });
    this.categoryErrors.set({});
    this.isCategoryFormOpen.set(true);
  }

  openEditCategoryForm(category: ItemCategory): void {
    this.editingCategory.set(category);
    this.categoryForm.reset({
      name: category.name,
      code: category.code,
      description: category.description ?? null,
    });
    this.categoryErrors.set({});
    this.isCategoryFormOpen.set(true);
  }

  closeCategoryForm(): void {
    this.isCategoryFormOpen.set(false);
    this.editingCategory.set(null);
    this.categoryErrors.set({});
  }

  clearCategoryError(field: string): void {
    this.categoryErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
  }

  submitCategoryForm(): void {
    if (this.isCategorySubmitting()) return;

    const errors: Record<string, string> = {};
    if (!this.categoryForm.value.name?.trim()) errors['name'] = 'Name is required.';
    if (!this.categoryForm.value.code?.trim()) errors['code'] = 'Code is required.';
    if (Object.keys(errors).length > 0) {
      this.categoryErrors.set(errors);
      this.categoryForm.markAllAsTouched();
      return;
    }

    const payload: CategoryPayload = {
      name: String(this.categoryForm.value.name).trim(),
      code: String(this.categoryForm.value.code).trim().toUpperCase(),
      description: this.categoryForm.value.description?.trim() ? this.categoryForm.value.description.trim() : null,
    };

    this.isCategorySubmitting.set(true);
    this.categoryErrors.set({});

    const obs = this.editingCategory()
      ? this.inventory.updateCategory(this.editingCategory()!.id, payload)
      : this.inventory.createCategory(payload);

    obs.subscribe({
      next: (res) => {
        this.isCategorySubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Category submission failed', res.message);
          return;
        }
        this.toast.success(this.editingCategory() ? 'Category updated' : 'Category created', res.data.name);
        this.closeCategoryForm();
        this.loadCategories(true);
        this.loadItems(true);
      },
      error: (err) => {
        this.isCategorySubmitting.set(false);
        const info = mapInventoryError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.categoryErrors.set(info.fieldErrors);
        } else {
          this.toast.danger('Category submission failed', info.message);
        }
      },
    });
  }

  // ─── Warehouse form ─────────────────────────────────────────────────────────

  openWarehouseFormForCreate(): void {
    this.loadWarehouseLocationLookup();
    this.editingWarehouse.set(null);
    this.warehouseForm.reset({ name: '', code: '', location_id: '', description: null, is_active: true });
    this.warehouseErrors.set({});
    this.isWarehouseFormOpen.set(true);
  }

  openEditWarehouseForm(warehouse: InventoryWarehouse): void {
    this.loadWarehouseLocationLookup();
    this.editingWarehouse.set(warehouse);
    this.warehouseForm.reset({
      name: warehouse.name,
      code: warehouse.code,
      location_id: warehouse.location?.id ?? '',
      description: warehouse.description ?? null,
      is_active: warehouse.is_active,
    });
    this.warehouseErrors.set({});
    this.isWarehouseFormOpen.set(true);
  }

  closeWarehouseForm(): void {
    this.isWarehouseFormOpen.set(false);
    this.editingWarehouse.set(null);
    this.warehouseErrors.set({});
  }

  clearWarehouseError(field: string): void {
    this.warehouseErrors.update(errors => {
      const next = { ...errors };
      delete next[field];
      return next;
    });
  }

  submitWarehouseForm(): void {
    if (this.isWarehouseSubmitting()) return;

    const errors: Record<string, string> = {};
    if (!this.warehouseForm.value.name?.trim()) errors['name'] = 'Name is required.';
    if (!this.warehouseForm.value.code?.trim()) errors['code'] = 'Code is required.';
    if (Object.keys(errors).length > 0) {
      this.warehouseErrors.set(errors);
      this.warehouseForm.markAllAsTouched();
      return;
    }

    const value = this.warehouseForm.value;
    const payload: WarehousePayload = {
      name: String(value.name).trim(),
      code: String(value.code).trim().toUpperCase(),
      description: value.description?.trim() ? value.description.trim() : null,
      location_id: value.location_id ? Number(value.location_id) : null,
      is_active: !!value.is_active,
    };

    this.isWarehouseSubmitting.set(true);
    this.warehouseErrors.set({});

    const obs = this.editingWarehouse()
      ? this.inventory.updateWarehouse(this.editingWarehouse()!.id, payload)
      : this.inventory.createWarehouse(payload);

    obs.subscribe({
      next: (res) => {
        this.isWarehouseSubmitting.set(false);
        if (!res.success) {
          this.toast.danger('Warehouse submission failed', res.message);
          return;
        }
        this.toast.success(this.editingWarehouse() ? 'Warehouse updated' : 'Warehouse created', res.data.name);
        this.closeWarehouseForm();
        this.loadWarehouses(true);
      },
      error: (err) => {
        this.isWarehouseSubmitting.set(false);
        const info = mapInventoryError(err);
        if (info.fieldErrors && Object.keys(info.fieldErrors).length > 0) {
          this.warehouseErrors.set(info.fieldErrors);
        } else {
          this.toast.danger('Warehouse submission failed', info.message);
        }
      },
    });
  }

  // ─── Deletes ────────────────────────────────────────────────────────────────

  requestDeleteItem(item: InventoryItem): void {
    this.confirmRequest.set({
      title: 'Delete item?',
      message: `"${item.sku}" will be removed. Items with stock history or maintenance usage cannot be deleted.`,
      confirmLabel: 'Delete',
      action: () => this.runDeleteItem(item.id),
    });
  }

  private runDeleteItem(id: number): void {
    this.inventory.deleteItem(id).subscribe({
      next: (res) => {
        if (!res.success) {
          this.toast.danger('Delete failed', res.message);
          return;
        }
        this.toast.success('Item deleted', 'The item has been removed.');
        if (this.selectedItemId() === id) {
          this.clearSelection();
        }
        this.refreshItems();
      },
      error: (err) => {
        const info = mapInventoryError(err);
        if (err instanceof HttpErrorResponse && err.status === 409) {
          this.toast.warning('Cannot delete item', info.message);
        } else {
          this.toast.danger('Delete failed', info.message);
        }
      },
    });
  }

  requestDeleteCategory(category: ItemCategory): void {
    this.confirmRequest.set({
      title: 'Delete category?',
      message: `Delete "${category.name}"? Categories still referenced by items cannot be deleted.`,
      confirmLabel: 'Delete',
      action: () => this.runDeleteCategory(category.id),
    });
  }

  private runDeleteCategory(id: number): void {
    this.inventory.deleteCategory(id).subscribe({
      next: (res) => {
        if (!res.success) {
          this.toast.danger('Delete failed', res.message);
          return;
        }
        this.toast.success('Category deleted', 'The category has been removed.');
        this.loadCategories(true);
        this.refreshItems();
      },
      error: (err) => {
        const info = mapInventoryError(err);
        if (err instanceof HttpErrorResponse && err.status === 409) {
          this.toast.warning('Cannot delete category', info.message);
        } else {
          this.toast.danger('Delete failed', info.message);
        }
      },
    });
  }

  requestDeleteWarehouse(warehouse: InventoryWarehouse): void {
    this.confirmRequest.set({
      title: 'Delete warehouse?',
      message: `Delete "${warehouse.code}"? Warehouses referenced by stock history cannot be deleted.`,
      confirmLabel: 'Delete',
      action: () => this.runDeleteWarehouse(warehouse.id),
    });
  }

  private runDeleteWarehouse(id: number): void {
    this.inventory.deleteWarehouse(id).subscribe({
      next: (res) => {
        if (!res.success) {
          this.toast.danger('Delete failed', res.message);
          return;
        }
        this.toast.success('Warehouse deleted', 'The warehouse has been removed.');
        this.loadWarehouses(true);
      },
      error: (err) => {
        const info = mapInventoryError(err);
        if (err instanceof HttpErrorResponse && err.status === 409) {
          this.toast.warning('Cannot delete warehouse', info.message);
        } else {
          this.toast.danger('Delete failed', info.message);
        }
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
}