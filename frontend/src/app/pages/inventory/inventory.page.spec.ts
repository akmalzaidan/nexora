import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { IonicModule } from '@ionic/angular/lazy';

import { AuthService } from '../../core/services/auth.service';
import { StorageService } from '../../core/services/storage.service';
import {
  InventoryItem,
  ItemCategory,
  InventoryWarehouse,
  StockMovement,
} from '../../core/services/inventory.service';
import { InventoryPage } from './inventory.page';

const routes = [{ path: 'inventory', component: InventoryPage }];

const categoryFixture: ItemCategory = {
  id: 1,
  name: 'Office Supplies',
  code: 'OFC',
  description: null,
  items_count: 2,
  created_at: null,
  updated_at: null,
};

const warehouseFixture: InventoryWarehouse = {
  id: 2,
  name: 'Main Warehouse',
  code: 'MAIN',
  description: null,
  is_active: true,
  location: { id: 3, name: 'HQ Office', code: 'HQ' },
  created_at: null,
  updated_at: null,
};

const itemFixture: InventoryItem = {
  id: 1,
  sku: 'ITEM-001',
  name: 'Ballpoint Pen',
  description: 'Blue ink.',
  unit: 'unit',
  minimum_stock: 10,
  maximum_stock: 100,
  is_active: true,
  category: { id: 1, name: 'Office Supplies', code: 'OFC' },
  stock: { total: 12 },
  created_at: '2025-02-01T10:00:00Z',
  updated_at: '2025-02-01T10:00:00Z',
};

const itemDetailFixture: InventoryItem = {
  ...itemFixture,
  stock: {
    total: 12,
    warehouses: [
      { warehouse: { id: 2, name: 'Main Warehouse', code: 'MAIN' }, quantity: 8 },
      { warehouse: { id: 4, name: 'Overflow', code: 'OVF' }, quantity: 4 },
    ],
  },
};

const movementFixture: StockMovement = {
  id: 5,
  type: 'STOCK_IN',
  quantity: 4,
  reference_type: null,
  reference_id: null,
  notes: 'Restock',
  item: { id: 1, sku: 'ITEM-001', name: 'Ballpoint Pen' },
  warehouse: { id: 2, name: 'Main Warehouse', code: 'MAIN' },
  performer: { id: 7, name: 'Nadira', email: 'nadira@nexora.test' },
  created_at: '2025-02-10T08:00:00Z',
};

function user(roleSlug: string) {
  return {
    id: 1,
    name: 'Tester',
    email: 'tester@nexora.test',
    role: { id: 1, name: 'Role', slug: roleSlug },
    department: null,
    is_active: true,
    created_at: null,
  };
}

function paged<T>(items: T[], perPage = 15): { success: true; message: string; data: { items: T[]; pagination: { current_page: number; per_page: number; total: number; last_page: number } } } {
  return {
    success: true,
    message: 'ok',
    data: {
      items,
      pagination: { current_page: 1, per_page: perPage, total: items.length, last_page: 1 },
    },
  };
}

describe('InventoryPage', () => {
  let controller: HttpTestingController;
  let harness: RouterTestingHarness;

  async function createPage(roleSlug: string): Promise<InventoryPage> {
    const storage = TestBed.inject(StorageService);
    const auth = TestBed.inject(AuthService);
    storage.set('nx-token', 'test-token');
    const init = auth.ensureInitialized();
    controller.expectOne('/api/v1/auth/me').flush({
      success: true,
      message: 'Authenticated user',
      data: { user: user(roleSlug) },
    });
    await init;

    harness = await RouterTestingHarness.create();
    return harness.navigateByUrl('/inventory', InventoryPage);
  }

  function flushInit(
    items: InventoryItem[] = [],
    categories: ItemCategory[] = [],
    warehouses: InventoryWarehouse[] = [],
    movements: StockMovement[] = [],
  ): void {
    controller.expectOne(req => req.url === '/api/v1/items').flush(paged(items, 15));
    controller.expectOne(req => req.url === '/api/v1/item-categories').flush(paged(categories, 100));
    controller.expectOne(req => req.url === '/api/v1/warehouses').flush(paged(warehouses, 100));
    controller.expectOne(req => req.url === '/api/v1/stock-movements').flush(paged(movements, 15));
  }

  function flushDetail(): void {
    controller.expectOne('/api/v1/items/1').flush({
      success: true,
      message: 'ok',
      data: itemDetailFixture,
    });
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [IonicModule.forRoot()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter(routes),
      ],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    localStorage.clear();
  });

  afterEach(() => {
    controller.verify();
    localStorage.clear();
  });

  it('renders the header, summary strip and item rows for a super admin', async () => {
    const component = await createPage('super_admin');
    flushInit([itemFixture], [categoryFixture], [warehouseFixture], [movementFixture]);
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Inventory');
    expect(text).toContain('Office Supplies');
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-summary-item').length).toBe(4);
    const rows = harness.fixture.nativeElement.querySelectorAll('.nx-item-row');
    expect(rows.length).toBe(1);
    expect(rows[0].textContent).toContain('ITEM-001');
    expect(rows[0].textContent).toContain('Ballpoint Pen');
    expect(component.items().length).toBe(1);
    expect(component.itemsTotal()).toBe(1);
  });

  it('renders the empty state when the backend returns no items', async () => {
    await createPage('super_admin');
    flushInit();
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('NO INVENTORY ITEMS');
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-item-row').length).toBe(0);
  });

  it('renders an error state and retries the list', async () => {
    const component = await createPage('super_admin');
    controller.expectOne(req => req.url === '/api/v1/item-categories').flush(paged([], 100));
    controller.expectOne(req => req.url === '/api/v1/warehouses').flush(paged([], 100));
    controller.expectOne(req => req.url === '/api/v1/stock-movements').flush(paged([], 15));
    controller.expectOne(req => req.url === '/api/v1/items').flush(
      { success: false, message: 'Server exploded.' },
      { status: 500, statusText: 'Server Error' },
    );
    harness.fixture.detectChanges();

    expect(component.itemsError()).not.toBeNull();
    expect(harness.fixture.nativeElement.textContent).toContain('UNABLE TO LOAD ITEMS');

    const retryButton = Array.from<HTMLButtonElement>(harness.fixture.nativeElement.querySelectorAll('button')).find(
      b => b.textContent?.trim() === 'Retry',
    );
    retryButton?.dispatchEvent(new MouseEvent('click'));
    controller.expectOne(req => req.url === '/api/v1/items').flush(paged([itemFixture], 15));
    harness.fixture.detectChanges();

    expect(component.itemsError()).toBeNull();
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-item-row').length).toBe(1);
  });

  it('shows the New Item button for users with manage_inventory', async () => {
    await createPage('super_admin');
    flushInit();
    harness.fixture.detectChanges();
    expect(harness.fixture.nativeElement.querySelector('.nx-page-header').textContent).toContain('New Item');
  });

  it('hides the New Item button for view-only users', async () => {
    await createPage('staff');
    flushInit();
    harness.fixture.detectChanges();
    expect(harness.fixture.nativeElement.querySelector('.nx-page-header').textContent).not.toContain('New Item');
  });

  it('selecting an item renders its detail with stock and warehouse breakdown', async () => {
    const component = await createPage('super_admin');
    flushInit([itemFixture], [categoryFixture], [warehouseFixture]);
    harness.fixture.detectChanges();

    component.selectItem(1);
    flushDetail();
    harness.fixture.detectChanges();

    expect(component.selectedItemId()).toBe(1);
    const detail = harness.fixture.nativeElement.querySelector('.nx-detail');
    const detailText: string = detail?.textContent ?? '';
    expect(detailText).toContain('Ballpoint Pen');
    expect(detailText).toContain('ITEM-001');
    expect(detailText).toContain('12');
    expect(detailText).toContain('Main Warehouse');
    expect(detailText).toContain('OVF');
    expect(detailText).toContain('8');
  });

  it('hides the Stock In / Stock Out / Edit / Delete actions for view-only users', async () => {
    const component = await createPage('staff');
    flushInit([itemFixture]);
    harness.fixture.detectChanges();

    component.selectItem(1);
    flushDetail();
    harness.fixture.detectChanges();

    const detailText: string = harness.fixture.nativeElement.querySelector('.nx-detail')?.textContent ?? '';
    expect(detailText).not.toContain('Stock In');
    expect(detailText).not.toContain('Stock Out');
    expect(detailText).not.toContain('Delete');
  });

  it('creates an item through the reactive form and reflects it in the list', async () => {
    const component = await createPage('super_admin');
    flushInit();
    harness.fixture.detectChanges();

    component.openCreateItemForm();
    component.itemForm.patchValue({
      item_category_id: 1,
      sku: 'ITEM-002',
      name: 'Notebook',
      unit: 'unit',
      minimum_stock: 5,
    });
    component.submitItemForm();

    controller.expectOne(req => req.method === 'POST' && req.url === '/api/v1/items').flush({
      success: true,
      message: 'Item created',
      data: { ...itemFixture, id: 2, sku: 'ITEM-002', name: 'Notebook' },
    });
    controller.expectOne(req => req.url === '/api/v1/items').flush(paged([itemFixture], 15));
    controller.expectOne('/api/v1/items/2').flush({
      success: true,
      message: 'ok',
      data: { ...itemDetailFixture, id: 2, sku: 'ITEM-002', name: 'Notebook' },
    });
    harness.fixture.detectChanges();

    expect(component.isItemFormOpen()).toBe(false);
    expect(component.selectedItemId()).toBe(2);
  });

  it('blocks item submission with client-side validation errors', async () => {
    const component = await createPage('super_admin');
    flushInit();
    harness.fixture.detectChanges();

    component.openCreateItemForm();
    component.submitItemForm();

    expect(component.isItemFormOpen()).toBe(true);
    expect(component.itemErrors()['sku']).toBeTruthy();
    expect(component.itemErrors()['name']).toBeTruthy();
    expect(component.itemErrors()['unit']).toBeTruthy();
    expect(component.itemErrors()['item_category_id']).toBeTruthy();
  });

  it('updates an existing item', async () => {
    const component = await createPage('super_admin');
    flushInit([itemFixture]);
    harness.fixture.detectChanges();

    component.openEditItemForm(itemFixture);
    component.itemForm.patchValue({ name: 'Ballpoint Pen 0.7mm' });
    component.submitItemForm();

    controller.expectOne(req => req.method === 'PUT' && req.url === '/api/v1/items/1').flush({
      success: true,
      message: 'Item updated',
      data: { ...itemFixture, name: 'Ballpoint Pen 0.7mm' },
    });
    controller.expectOne(req => req.url === '/api/v1/items').flush(paged([itemFixture], 15));
    harness.fixture.detectChanges();

    expect(component.isItemFormOpen()).toBe(false);
  });

  it('creates a category and refreshes the category list and items', async () => {
    const component = await createPage('super_admin');
    flushInit();
    harness.fixture.detectChanges();

    component.openCreateCategoryForm();
    component.categoryForm.patchValue({ name: 'Stationery', code: 'STN' });
    component.submitCategoryForm();

    controller.expectOne(req => req.method === 'POST' && req.url === '/api/v1/item-categories').flush({
      success: true,
      message: 'Category created',
      data: { ...categoryFixture, id: 4, name: 'Stationery', code: 'STN' },
    });
    controller.expectOne(req => req.url === '/api/v1/item-categories').flush(paged([categoryFixture], 100));
    controller.expectOne(req => req.url === '/api/v1/items').flush(paged([], 15));
    harness.fixture.detectChanges();

    expect(component.isCategoryFormOpen()).toBe(false);
  });

  it('records stock in and reloads detail, list and movement journal', async () => {
    const component = await createPage('super_admin');
    flushInit([itemFixture], [categoryFixture], [warehouseFixture]);
    harness.fixture.detectChanges();

    component.selectItem(1);
    flushDetail();
    harness.fixture.detectChanges();

    component.openStockForm(itemDetailFixture, 'STOCK_IN');
    component.stockForm.patchValue({ warehouse_id: 2, quantity: '10' });
    component.submitStockForm();

    controller.expectOne(req => req.method === 'POST' && req.url === '/api/v1/stock-movements').flush({
      success: true,
      message: 'Stock movement recorded',
      data: { ...movementFixture, id: 6, type: 'STOCK_IN', quantity: 10 },
    });
    controller.expectOne('/api/v1/items/1').flush({ success: true, message: 'ok', data: itemDetailFixture });
    controller.expectOne(req => req.url === '/api/v1/items').flush(paged([itemFixture], 15));
    controller.expectOne(req => req.url === '/api/v1/stock-movements').flush(paged([movementFixture], 15));
    harness.fixture.detectChanges();

    expect(component.isStockFormOpen()).toBe(false);
  });

  it('surfaces insufficient stock inline without closing the stock dialog', async () => {
    const component = await createPage('super_admin');
    flushInit([itemFixture], [categoryFixture], [warehouseFixture]);
    harness.fixture.detectChanges();

    component.selectItem(1);
    flushDetail();
    harness.fixture.detectChanges();

    component.openStockForm(itemDetailFixture, 'STOCK_OUT');
    component.stockForm.patchValue({ warehouse_id: 2, quantity: '999' });
    component.submitStockForm();

    controller.expectOne(req => req.method === 'POST' && req.url === '/api/v1/stock-movements').flush(
      { success: false, message: 'Insufficient stock for this movement.' },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    harness.fixture.detectChanges();

    expect(component.stockInsufficient()).toBe(true);
    expect(component.stockServerMessage()).toContain('Insufficient stock');
    expect(component.isStockFormOpen()).toBe(true);
    expect(harness.fixture.nativeElement.textContent).toContain('Unable to complete stock out');
  });

  it('renders the stock movement journal with filters when the movements tab is opened', async () => {
    const component = await createPage('super_admin');
    flushInit([], [], [warehouseFixture], [movementFixture]);
    harness.fixture.detectChanges();

    component.setView('movements');
    controller.expectOne(req => req.url === '/api/v1/items').flush(paged([itemFixture], 100));
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Stock In');
    expect(text).toContain('ITEM-001');
    expect(text).toContain('Nadira');
    expect(harness.fixture.nativeElement.querySelectorAll('.nx-table tbody tr').length).toBe(1);
    expect(component.movementItems().length).toBe(1);
  });

  it('deletes an item after confirmation and refreshes the list', async () => {
    const component = await createPage('super_admin');
    flushInit([itemFixture]);
    harness.fixture.detectChanges();

    component.requestDeleteItem(itemFixture);
    expect(component.confirmRequest()).not.toBeNull();
    component.executeConfirm();

    controller.expectOne(req => req.method === 'DELETE' && req.url === '/api/v1/items/1').flush({ success: true, message: 'Item deleted', data: null });
    controller.expectOne(req => req.url === '/api/v1/items').flush(paged([], 15));
    harness.fixture.detectChanges();

    expect(component.confirmRequest()).toBeNull();
    expect(component.items()).toEqual([]);
  });

  it('treats a 409 delete conflict as a warning and keeps the record', async () => {
    const component = await createPage('super_admin');
    flushInit([itemFixture]);
    harness.fixture.detectChanges();

    component.requestDeleteItem(itemFixture);
    component.executeConfirm();
    controller.expectOne(req => req.method === 'DELETE' && req.url === '/api/v1/items/1').flush(
      { success: false, message: 'Item has stock history.' },
      { status: 409, statusText: 'Conflict' },
    );
    harness.fixture.detectChanges();

    expect(component.confirmRequest()).toBeNull();
    expect(component.items().length).toBe(1);
  });
});