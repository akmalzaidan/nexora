import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router, UrlTree } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { IonicModule } from '@ionic/angular/lazy';

import { AuthService } from '../../core/services/auth.service';
import { StorageService } from '../../core/services/storage.service';
import { permissionGuard } from '../../core/guards/permission.guard';
import {
  AssetReport,
  InventoryReport,
  MaintenanceReport,
  ReportOverview,
  TicketReport,
} from '../../core/services/report.service';
import { ReportsPage } from './reports.page';

const routes = [{ path: 'reports', component: ReportsPage }];

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

const overview: ReportOverview = {
  generated_at: '2026-03-01T10:15:00Z',
  assets: { total: 350, by_status: [{ status: 'ACTIVE', count: 350 }] },
  inventory: { item_count: 12, warehouse_count: 2, stock_quantity: 900 },
  tickets: { total: 8, by_status: [{ status: 'OPEN', count: 8 }] },
  maintenance: { total: 4, by_status: [{ status: 'PENDING', count: 4 }] },
};

const assets: AssetReport = {
  current: {
    total: 350,
    by_status: [{ status: 'ACTIVE', count: 350 }],
    by_category: [{ category_id: 1, label: 'Laptop', count: 350 }],
    by_location: [{ location_id: 2, label: 'Jakarta HQ', count: 350 }],
    assigned_count: 120,
    unassigned_count: 230,
    without_location_count: 12,
  },
  assignments: { total: 40, by_status: [{ status: 'RETURNED', count: 40 }] },
  period: {
    from: '2026-03-01',
    to: '2026-03-31',
    assets_created: 8,
    assignments_assigned: 3,
    assignments_returned: 2,
  },
};

const inventory: InventoryReport = {
  items: { count: 12, category_count: 3 },
  warehouses: { count: 2 },
  current_stock: {
    total_quantity: 900,
    by_warehouse: [
      { warehouse_id: 1, label: 'WH Jakarta', quantity: 600 },
      { warehouse_id: 2, label: 'WH Bandung', quantity: 300 },
    ],
    by_item: { limit: 10, items: [{ item_id: 5, quantity: 600 }] },
  },
  period: {
    from: '2026-03-01',
    to: '2026-03-31',
    movement_count: 14,
    stock_in_total: 20,
    stock_out_total: 6,
  },
};

const requests: TicketReport = {
  current: {
    total: 8,
    by_status: [{ status: 'OPEN', count: 8 }],
    by_priority: [{ priority: 'HIGH', count: 8 }],
    by_category: [{ category_id: 3, label: 'Network', count: 8 }],
  },
  period: {
    from: '2026-03-01',
    to: '2026-03-31',
    created: 3,
    trend: [
      { date: '2026-03-01', count: 1 },
      { date: '2026-03-02', count: 2 },
      { date: '2026-03-03', count: 0 },
    ],
  },
};

const maintenance: MaintenanceReport = {
  requests: { total: 4, by_status: [{ status: 'PENDING', count: 4 }], by_priority: [{ priority: 'MEDIUM', count: 4 }] },
  records: { count: 2, costed_count: 0, total_cost: '0.00', average_cost: null },
  parts: {
    usage_count: 1,
    top_items: {
      limit: 10,
      items: [{ item_id: 9, sku: 'SKU-9', label: 'Bearing', quantity: 3, usage_count: 1 }],
    },
  },
  by_asset: {
    limit: 10,
    items: [{ asset_id: 3, asset_code: 'AST-003', asset_name: 'Forklift', count: 2 }],
  },
  period: {
    from: '2026-03-01',
    to: '2026-03-31',
    requested: 5,
    approved: 4,
    completed: 2,
  },
};

const REPORT_URLS = {
  overview: '/api/v1/reports/overview',
  assets: '/api/v1/reports/assets',
  inventory: '/api/v1/reports/inventory',
  requests: '/api/v1/reports/tickets',
  maintenance: '/api/v1/reports/maintenance',
} as const;

describe('ReportsPage', () => {
  let controller: HttpTestingController;
  let harness: RouterTestingHarness;

  async function createPage(): Promise<ReportsPage> {
    harness = await RouterTestingHarness.create();
    return harness.navigateByUrl('/reports', ReportsPage);
  }

  function flush(path: string, data: unknown, status = 200): void {
    controller
      .expectOne(r => r.url === path && r.method === 'GET')
      .flush(envelope(data), { status, statusText: 'OK' });
    harness.fixture.detectChanges();
  }

  function findButton(label: string | RegExp): HTMLButtonElement | null {
    const buttons = Array.from(
      harness.fixture.nativeElement.querySelectorAll('button'),
    ) as HTMLButtonElement[];
    const matches = (text: string): boolean =>
      typeof label === 'string' ? text === label : label.test(text);
    return buttons.find(button => matches(button.textContent?.trim() ?? '')) ?? null;
  }

  function findTab(label: string): HTMLButtonElement {
    const tabs = Array.from(
      harness.fixture.nativeElement.querySelectorAll('[role="tab"]'),
    ) as HTMLButtonElement[];
    const tab = tabs.find(candidate => candidate.textContent?.trim() === label);
    if (!tab) {
      throw new Error(`tab not found: ${label}`);
    }
    return tab;
  }

  function openTab(label: string): void {
    findTab(label).click();
    harness.fixture.detectChanges();
  }

  function bodyText(): string {
    return harness.fixture.nativeElement.textContent as string;
  }

  function setDate(index: 0 | 1, value: string): void {
    const inputs = harness.fixture.nativeElement.querySelectorAll(
      'input[type="date"]',
    ) as NodeListOf<HTMLInputElement>;
    const input = inputs[index];
    input.value = value;
    input.dispatchEvent(new Event('input'));
    harness.fixture.detectChanges();
  }

  /** Opens the period panel if it is closed; the toggle label changes with state. */
  function openPeriodPanel(): void {
    const existing = harness.fixture.nativeElement.querySelectorAll('input[type="date"]');
    if (existing.length > 0) {
      return;
    }
    findButton(/^Period$|^Hide period$/)?.click();
    harness.fixture.detectChanges();
  }

  function applyPeriod(from: string, to: string): void {
    openPeriodPanel();
    setDate(0, from);
    setDate(1, to);
    findButton('Apply')?.click();
    harness.fixture.detectChanges();
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [IonicModule.forRoot()],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter(routes)],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    localStorage.clear();
  });

  afterEach(() => {
    controller.verify();
    localStorage.clear();
  });

  it('requests only the overview on first render and renders it verbatim', async () => {
    await createPage();

    const req = controller.expectOne(r => r.url === REPORT_URLS.overview);
    expect(req.request.params.keys()).toEqual([]);
    req.flush(envelope(overview));
    harness.fixture.detectChanges();

    // No other report is fetched until its tab is opened.
    expect(controller.match(r => r.url.startsWith('/api/v1/reports/')).length).toBe(0);

    expect(bodyText()).toContain('Reports');
    expect(bodyText()).toContain(
      'Operational data across assets, inventory, requests, and maintenance.',
    );
    expect(bodyText()).toContain('350');
    expect(bodyText()).toContain('900');
    expect(bodyText()).toContain('Current state');
    expect(findButton('Refresh')).not.toBeNull();
  });

  it('fetches a report the first time its tab is opened and caches it afterwards', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Assets');
    const assetReq = controller.expectOne(r => r.url === REPORT_URLS.assets);
    // No period is sent in the current state; the ranking size stays explicit.
    expect(assetReq.request.params.has('from')).toBe(false);
    expect(assetReq.request.params.has('to')).toBe(false);
    expect(assetReq.request.params.get('limit')).toBe('10');
    assetReq.flush(envelope(assets));
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('Unassigned');
    expect(bodyText()).toContain('230');
    expect(bodyText()).toContain('Jakarta HQ');

    // Returning to a loaded tab must not re-issue the request.
    openTab('Overview');
    openTab('Assets');
    expect(controller.match(r => r.url === REPORT_URLS.assets).length).toBe(0);
  });

  it('keeps each tab state isolated', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Inventory');
    controller
      .expectOne(r => r.url === REPORT_URLS.inventory)
      .flush({ success: false, message: 'Server error', data: null }, { status: 500, statusText: 'Error' });
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('Inventory report unavailable');

    openTab('Maintenance');
    controller.expectOne(r => r.url === REPORT_URLS.maintenance).flush(envelope(maintenance));
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('AST-003');
    // The healthy tab renders while the failed tab keeps its own error.
    expect(bodyText()).not.toContain('Inventory report unavailable');
  });

  it('retries only the tab that failed', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Requests');
    controller
      .expectOne(r => r.url === REPORT_URLS.requests)
      .flush({ success: false, message: 'nope', data: null }, { status: 500, statusText: 'Error' });
    harness.fixture.detectChanges();

    const retry = Array.from(
      harness.fixture.nativeElement.querySelectorAll('button') as NodeListOf<HTMLButtonElement>,
    ).find(button => button.textContent?.trim() === 'Retry');
    expect(retry).toBeTruthy();
    retry?.click();
    harness.fixture.detectChanges();

    controller.expectOne(r => r.url === REPORT_URLS.requests).flush(envelope(requests));
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('Network');
  });

  it('sends the period on the report that is on screen and keeps it across tabs', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Assets');
    flush(REPORT_URLS.assets, assets);
    applyPeriod('2026-03-01', '2026-03-31');

    const periodReq = controller.expectOne(r => r.url === REPORT_URLS.assets);
    expect(periodReq.request.params.get('from')).toBe('2026-03-01');
    expect(periodReq.request.params.get('to')).toBe('2026-03-31');
    expect(periodReq.request.params.get('limit')).toBe('10');
    periodReq.flush(envelope(assets));
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('Period 01 Mar 2026 – 31 Mar 2026');
    expect(bodyText()).toContain('Period activity');

    // The period survives a tab switch and reaches the next report.
    openTab('Inventory');
    const inventoryReq = controller.expectOne(r => r.url === REPORT_URLS.inventory);
    expect(inventoryReq.request.params.get('from')).toBe('2026-03-01');
    expect(inventoryReq.request.params.get('to')).toBe('2026-03-31');
    inventoryReq.flush(envelope(inventory));
    harness.fixture.detectChanges();

    // The undated overview never claims a period.
    openTab('Overview');
    expect(bodyText()).toContain('Current state');
  });

  it('refuses an invalid period without calling the API', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Assets');
    flush(REPORT_URLS.assets, assets);

    applyPeriod('2026-03-31', '2026-03-01');
    expect(bodyText()).toContain('on or before');
    expect(controller.match(r => r.url === REPORT_URLS.assets).length).toBe(0);

    applyPeriod('2026-01-01', '');
    expect(bodyText()).toContain('both a start and an end date');
    expect(controller.match(r => r.url === REPORT_URLS.assets).length).toBe(0);

    applyPeriod('2026-01-01', '2027-01-02');
    expect(bodyText()).toContain('366');
    expect(controller.match(r => r.url === REPORT_URLS.assets).length).toBe(0);
  });

  it('clears the period and reloads the active report without parameters', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Assets');
    flush(REPORT_URLS.assets, assets);
    applyPeriod('2026-03-01', '2026-03-31');
    controller.expectOne(r => r.url === REPORT_URLS.assets).flush(envelope(assets));
    harness.fixture.detectChanges();

    openPeriodPanel();
    findButton('Clear')?.click();
    harness.fixture.detectChanges();

    const cleared = controller.expectOne(r => r.url === REPORT_URLS.assets);
    expect(cleared.request.params.has('from')).toBe(false);
    expect(cleared.request.params.has('to')).toBe(false);
    cleared.flush(envelope(assets));
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('Current state');
  });

  it('keeps the period control disabled on the undated overview', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openPeriodPanel();
    const inputs = harness.fixture.nativeElement.querySelectorAll(
      'input[type="date"]',
    ) as NodeListOf<HTMLInputElement>;
    expect(inputs[0].disabled).toBe(true);
    expect((findButton('Apply') as HTMLButtonElement).disabled).toBe(true);
    expect(bodyText()).toContain('undated snapshot');
  });

  it('never issues two overlapping refreshes for the same report', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    findButton('Refresh')?.click();
    findButton('Refresh')?.click();
    harness.fixture.detectChanges();

    const pending = controller.match(r => r.url === REPORT_URLS.overview);
    expect(pending.length).toBe(1);
    pending[0].flush(envelope(overview));
    harness.fixture.detectChanges();
  });

  it('replays a period change that was requested while a refresh was running', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Assets');
    flush(REPORT_URLS.assets, assets);

    // A refresh is in flight with no period...
    findButton('Refresh')?.click();
    harness.fixture.detectChanges();
    const claimed = controller.match(r => r.url === REPORT_URLS.assets);
    expect(claimed.length).toBe(1);
    const inFlight = claimed[0];
    expect(inFlight.request.params.has('from')).toBe(false);

    // ...and the user applies a period before it settles.
    applyPeriod('2026-03-01', '2026-03-31');

    inFlight.flush(envelope(assets));
    harness.fixture.detectChanges();

    const replay = controller.expectOne(r => r.url === REPORT_URLS.assets);
    expect(replay.request.params.get('from')).toBe('2026-03-01');
    expect(replay.request.params.get('to')).toBe('2026-03-31');
    replay.flush(envelope(assets));
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('Period 01 Mar 2026 – 31 Mar 2026');
  });

  it('shows an access restricted state for 403 instead of a generic failure', async () => {
    await createPage();
    controller
      .expectOne(r => r.url === REPORT_URLS.overview)
      .flush({ success: false, message: 'forbidden', data: null }, { status: 403, statusText: 'Forbidden' });
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('ACCESS RESTRICTED');
    expect(bodyText()).not.toContain('Overview unavailable');
  });

  it('treats a fully zero overview as an empty state, not a failure', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(
      envelope({
        generated_at: '2026-03-01T10:15:00Z',
        assets: { total: 0, by_status: [] },
        inventory: { item_count: 0, warehouse_count: 0, stock_quantity: 0 },
        tickets: { total: 0, by_status: [] },
        maintenance: { total: 0, by_status: [] },
      }),
    );
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('No operational data yet');
  });

  it('renders a null average cost as missing data, not as zero', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Maintenance');
    flush(REPORT_URLS.maintenance, maintenance);

    expect(bodyText()).toContain('0.00');
    expect(bodyText()).toContain('Average recorded cost');
    expect(bodyText()).toContain('This is missing data, not a cost of zero.');
  });

  it('renders the backend cost strings untouched', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Maintenance');
    flush(REPORT_URLS.maintenance, {
      ...maintenance,
      records: { count: 2, costed_count: 2, total_cost: '4250000.75', average_cost: '2125000.38' },
    });

    expect(bodyText()).toContain('4250000.75');
    expect(bodyText()).toContain('2125000.38');
  });

  it('presents parts as a trace and not as stock movement', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Maintenance');
    flush(REPORT_URLS.maintenance, maintenance);

    expect(bodyText()).toContain('Parts recorded');
    expect(bodyText()).toContain('SKU-9');
    expect(bodyText()).toContain('Bearing');
    expect(bodyText()).toContain('does not move stock');
  });

  it('plots the request trend the backend returned, with the values available as a table', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    openTab('Requests');
    flush(REPORT_URLS.requests, requests);

    expect(bodyText()).toContain('Daily requests created');
    expect(bodyText()).toContain('View as table');
    expect(bodyText()).toContain('2026-03-01');
    expect(bodyText()).toContain('2026-03-03');

    const plot = harness.fixture.nativeElement.querySelector('.nx-trend-plot');
    expect(plot.getAttribute('role')).toBe('img');
    expect(plot.getAttribute('aria-label')).toContain('2026-03-01');
  });

  it('moves between tabs with the keyboard', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    const overviewTab = findTab('Overview');
    overviewTab.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }));
    harness.fixture.detectChanges();

    expect(findTab('Assets').getAttribute('aria-selected')).toBe('true');
    controller.expectOne(r => r.url === REPORT_URLS.assets).flush(envelope(assets));
    harness.fixture.detectChanges();

    findTab('Assets').dispatchEvent(new KeyboardEvent('keydown', { key: 'End' }));
    harness.fixture.detectChanges();
    expect(findTab('Maintenance').getAttribute('aria-selected')).toBe('true');
    controller.expectOne(r => r.url === REPORT_URLS.maintenance).flush(envelope(maintenance));
    harness.fixture.detectChanges();
  });

  it('marks the active tab for assistive technology', async () => {
    await createPage();
    controller.expectOne(r => r.url === REPORT_URLS.overview).flush(envelope(overview));
    harness.fixture.detectChanges();

    expect(findTab('Overview').getAttribute('aria-selected')).toBe('true');
    expect(findTab('Assets').getAttribute('aria-selected')).toBe('false');
    expect(findTab('Overview').getAttribute('tabindex')).toBe('0');
    expect(findTab('Assets').getAttribute('tabindex')).toBe('-1');
  });
});

describe('Reports route authorization', () => {
  let controller: HttpTestingController;
  let auth: AuthService;

  const guardedRoutes = [
    {
      path: 'reports',
      component: ReportsPage,
      canActivate: [permissionGuard],
      data: { permission: 'view_reports' },
    },
    { path: 'unauthorized', component: ReportsPage },
    { path: 'home', component: ReportsPage },
  ];

  function signIn(role: string): Promise<unknown> {
    const storage = TestBed.inject(StorageService);
    storage.set('nx-token', 'session-token');
    storage.set('nx-user', {});
    const init = auth.ensureInitialized();
    controller.expectOne('/api/v1/auth/me').flush({
      success: true,
      message: 'Authenticated user',
      data: {
        user: {
          id: 5,
          name: 'Staff User',
          email: 'staff@nexora.test',
          role: { id: 4, name: role, slug: role },
          department: null,
          is_active: true,
          created_at: null,
        },
      },
    });
    return init;
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [IonicModule.forRoot()],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter(guardedRoutes)],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
    localStorage.clear();
  });

  afterEach(() => {
    controller?.verify();
    localStorage.clear();
  });

  it('lets a manager reach the workspace', async () => {
    const init = signIn('manager');
    const result = await TestBed.runInInjectionContext(() =>
      permissionGuard({ data: { permission: 'view_reports' } } as never, { url: '/reports' } as never),
    );
    await init;

    expect(result).toBe(true);
  });

  it('sends a role without view_reports to the unauthorized route', async () => {
    const init = signIn('staff');
    const result = await TestBed.runInInjectionContext(() =>
      permissionGuard({ data: { permission: 'view_reports' } } as never, { url: '/reports' } as never),
    );
    await init;

    expect((result as UrlTree).toString()).toBe('/unauthorized');
    expect(TestBed.inject(Router).url).not.toBe('/reports');
  });
});
