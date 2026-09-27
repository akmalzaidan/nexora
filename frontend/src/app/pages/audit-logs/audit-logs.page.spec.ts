import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router, UrlTree } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { IonicModule } from '@ionic/angular/lazy';

import { AuthService } from '../../core/services/auth.service';
import { StorageService } from '../../core/services/storage.service';
import { AuthorizationService } from '../../core/services/authorization.service';
import { permissionGuard } from '../../core/guards/permission.guard';
import { AuditLog } from '../../core/services/audit-log.service';
import { AuditLogsPage } from './audit-logs.page';

const routes = [{ path: 'audit-logs', component: AuditLogsPage }];

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

function paged<T>(
  items: T[],
  opts: { page?: number; perPage?: number; total?: number; lastPage?: number } = {},
) {
  const total = opts.total ?? items.length;
  const perPage = opts.perPage ?? 25;
  const lastPage = opts.lastPage ?? Math.max(1, Math.ceil(total / perPage));
  return envelope({
    items,
    pagination: { current_page: opts.page ?? 1, per_page: perPage, total, last_page: lastPage },
  });
}

function makeLog(overrides: Partial<AuditLog> = {}): AuditLog {
  return {
    id: 42,
    actor: { id: 12, name: 'Admin' },
    action: 'updated',
    resource: { type: 'ticket', id: 7 },
    description: 'Ticket TCK-1 updated (Title)',
    old_values: { status: 'OPEN' },
    new_values: { status: 'IN_PROGRESS' },
    ip_address: '127.0.0.1',
    user_agent: 'Test Agent',
    created_at: '2026-09-26T10:15:00.000000Z',
    ...overrides,
  };
}

describe('AuditLogsPage', () => {
  let controller: HttpTestingController;
  let harness: RouterTestingHarness;
  let auth: AuthService;

  async function createPage(url = '/audit-logs'): Promise<AuditLogsPage> {
    harness = await RouterTestingHarness.create();
    return harness.navigateByUrl(url, AuditLogsPage);
  }

  function flushList(items: AuditLog[], opts: { total?: number; lastPage?: number } = {}): void {
    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs' && r.method === 'GET');
    req.flush(paged(items, opts));
    harness.fixture.detectChanges();
  }

  function flushDetail(log: AuditLog): void {
    controller.expectOne(`/api/v1/audit-logs/${log.id}`).flush(envelope(log));
    harness.fixture.detectChanges();
  }

  function flushUsers(): void {
    const req = controller.expectOne(r => r.url === '/api/v1/users');
    req.flush(envelope({
      items: [{ id: 12, name: 'Admin', email: 'a@nexora.test', is_active: true, role: null, department: null, created_at: null, updated_at: null }],
      pagination: { current_page: 1, per_page: 100, total: 1, last_page: 1 },
    }));
    harness.fixture.detectChanges();
  }

  function findButton(label: string): HTMLButtonElement | null {
    const buttons = Array.from(harness.fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    return buttons.find(b => b.textContent?.includes(label)) ?? null;
  }

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [IonicModule.forRoot()],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter(routes)],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
    // Sign in directly through the session signal (no /auth/me round trip):
    // the page under test is the workspace, not the auth flow.
    auth.user.set({
      id: 1,
      name: 'Tester',
      email: 'tester@nexora.test',
      role: { id: 2, name: 'Admin', slug: 'admin' },
      department: null,
      is_active: true,
      created_at: null,
    });
    TestBed.inject(AuthorizationService); // warm the computed signals
  });

  afterEach(() => {
    controller.verify();
    localStorage.clear();
    auth.user.set(null);
  });

  it('renders the governance workspace header and rows', async () => {
    await createPage();
    flushUsers();
    flushList([
      makeLog(),
      makeLog({ id: 41, action: 'created', resource: { type: 'user', id: 9 }, old_values: null, new_values: { name: 'New' } }),
    ]);

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Audit Logs');
    expect(text).toContain('Governance trail for changes across NEXORA');
    expect(text).toContain('Read-only governance history');
    expect(text).toContain('Updated');
    expect(text).toContain('Admin');
    expect(text).toContain('Page 1 of 1');
  });

  it('shows ACCESS RESTRICTED when the list request returns 403', async () => {
    await createPage();
    flushUsers();

    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    req.flush({ success: false, message: 'Access denied.' }, { status: 403, statusText: 'Forbidden' });
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('ACCESS RESTRICTED');
    expect(text).toContain('You do not have access to governance audit logs.');
  });

  it('selects an event and renders the detail with diff and metadata', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog()]);

    (harness.fixture.nativeElement.querySelector('.nx-item-row') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    flushDetail(makeLog());

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Actor');
    expect(text).toContain('Admin');
    expect(text).toContain('Changed fields');
    expect(text).toContain('Status');
    expect(text).toContain('OPEN');
    expect(text).toContain('IN_PROGRESS');
    expect(text).toContain('Metadata');
    expect(text).toContain('127.0.0.1');
    expect(text).toContain('Open related record');
  });

  it('shows the empty state when there are no audit events', async () => {
    await createPage();
    flushUsers();
    flushList([]);

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('No audit events yet');
    expect(text).toContain('Governance activity will appear here as system changes occur.');
  });

  it('shows the filtered empty state when filters exclude everything', async () => {
    await createPage();
    flushUsers();
    flushList([]);

    const select = harness.fixture.nativeElement.querySelector('#nx-audit-action') as HTMLSelectElement;
    select.value = 'deleted';
    select.dispatchEvent(new Event('change'));
    harness.fixture.detectChanges();

    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(req.request.params.get('action')).toBe('deleted');
    expect(req.request.params.get('page')).toBe('1');
    req.flush(paged([]));
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('No audit events match these filters.');
  });

  it('sends action, resource type and resource id filters and resets to page 1', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog()], { total: 50, lastPage: 2 });

    const typeSelect = harness.fixture.nativeElement.querySelector('#nx-audit-type') as HTMLSelectElement;
    typeSelect.value = 'ticket';
    typeSelect.dispatchEvent(new Event('change'));
    harness.fixture.detectChanges();

    const typeReq = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(typeReq.request.params.get('resource_type')).toBe('ticket');
    expect(typeReq.request.params.get('page')).toBe('1');
    typeReq.flush(paged([makeLog()], { total: 50, lastPage: 2 }));
    harness.fixture.detectChanges();

    const rid = harness.fixture.nativeElement.querySelector('#nx-audit-rid') as HTMLInputElement;
    rid.value = '7';
    rid.dispatchEvent(new Event('change'));
    harness.fixture.detectChanges();

    const ridReq = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(ridReq.request.params.get('resource_id')).toBe('7');
    ridReq.flush(paged([makeLog()]));
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('Filters · 2');
    expect(findButton('Clear')).toBeTruthy();
  });

  it('paginates with the backend page param', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog()], { total: 50, lastPage: 2 });

    const buttons = Array.from(harness.fixture.nativeElement.querySelectorAll('.nx-page-button')) as HTMLButtonElement[];
    buttons[buttons.length - 1]!.click();
    harness.fixture.detectChanges();

    const pageReq = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(pageReq.request.params.get('page')).toBe('2');
    pageReq.flush(paged([makeLog()], { page: 2, total: 50, lastPage: 2 }));
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('Page 2 of 2');
  });

  it('applies an inclusive date range only when both bounds are set', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog()]);

    const dates = Array.from(harness.fixture.nativeElement.querySelectorAll('input[type="date"]')) as HTMLInputElement[];
    dates[0]!.value = '2026-01-01';
    dates[0]!.dispatchEvent(new Event('change'));
    dates[1]!.value = '2026-01-31';
    dates[1]!.dispatchEvent(new Event('change'));
    harness.fixture.detectChanges();

    findButton('Apply')!.click();
    harness.fixture.detectChanges();

    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(req.request.params.get('from')).toBe('2026-01-01');
    expect(req.request.params.get('to')).toBe('2026-01-31');
    req.flush(paged([makeLog()]));
    harness.fixture.detectChanges();
  });

  it('shows a date range validation error and does not fire the request', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog()]);

    const dates = Array.from(harness.fixture.nativeElement.querySelectorAll('input[type="date"]')) as HTMLInputElement[];
    dates[0]!.value = '2026-02-01';
    dates[0]!.dispatchEvent(new Event('change'));
    dates[1]!.value = '2026-01-01';
    dates[1]!.dispatchEvent(new Event('change'));
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('From must be on or before To.');

    findButton('Apply')!.click();
    harness.fixture.detectChanges();
    controller.expectNone(r => r.url === '/api/v1/audit-logs');
  });

  it('shows an error state with retry on initial load failure', async () => {
    await createPage();
    flushUsers();

    controller.expectOne(r => r.url === '/api/v1/audit-logs')
      .flush({ success: false, message: 'boom', data: null }, { status: 500, statusText: 'Error' });
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('Unable to load audit logs');

    findButton('Retry')!.click();
    harness.fixture.detectChanges();
    flushList([makeLog()]);
    expect(harness.fixture.nativeElement.textContent).toContain('Ticket · #7');
  });

  it('preserves the list and shows the stale banner when a refresh fails', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog()]);

    findButton('Refresh')!.click();
    harness.fixture.detectChanges();

    controller.expectOne(r => r.url === '/api/v1/audit-logs')
      .flush({ success: false, message: 'offline' }, { status: 0, statusText: 'Network' });
    harness.fixture.detectChanges();

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Showing previously loaded events');
    expect(text).toContain('Ticket · #7'); // last list preserved
  });

  it('renders a deleted actor without inventing a user', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog({ actor: null })]);

    (harness.fixture.nativeElement.querySelector('.nx-item-row') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    flushDetail(makeLog({ actor: null }));

    expect(harness.fixture.nativeElement.textContent).toContain('Deleted user');
  });

  it('shows created values for one-sided created events', async () => {
    await createPage();
    flushUsers();
    const created = makeLog({ action: 'created', old_values: null, new_values: { name: 'New Manager' } });
    flushList([created]);

    (harness.fixture.nativeElement.querySelector('.nx-item-row') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    flushDetail(created);

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Created values');
    expect(text).toContain('New Manager');
  });

  it('shows previous values for one-sided deleted events', async () => {
    await createPage();
    flushUsers();
    const deleted = makeLog({ action: 'deleted', new_values: null });
    flushList([deleted]);

    (harness.fixture.nativeElement.querySelector('.nx-item-row') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    flushDetail(deleted);

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Previous values');
    expect(text).not.toContain('Open related record');
  });

  it('renders unknown future actions and resource types with safe fallbacks', async () => {
    await createPage();
    flushUsers();
    const unknown = makeLog({ action: 'policy_changed', resource: { type: 'policy', id: 3 } });
    flushList([unknown]);

    (harness.fixture.nativeElement.querySelector('.nx-item-row') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    flushDetail(unknown);

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Policy changed');
    expect(text).toContain('Policy · #3');
    expect(findButton('Open related record')).toBeNull();
  });

  it('navigates to the requests workspace for a ticket resource', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog({ resource: { type: 'ticket', id: 7 } })]);

    (harness.fixture.nativeElement.querySelector('.nx-item-row') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    flushDetail(makeLog({ resource: { type: 'ticket', id: 7 } }));

    expect(findButton('Open related record')).toBeTruthy();
  });

  it('never renders mutation UI on the immutable governance workspace', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog()]);

    const text = harness.fixture.nativeElement.textContent;
    expect(findButton('Create')).toBeNull();
    expect(findButton('Edit')).toBeNull();
    expect(findButton('Delete')).toBeNull();
    expect(text).toContain('Read-only governance history');
  });

  it('clears all filters and returns to page 1', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog()], { total: 50, lastPage: 2 });

    const select = harness.fixture.nativeElement.querySelector('#nx-audit-action') as HTMLSelectElement;
    select.value = 'created';
    select.dispatchEvent(new Event('change'));
    harness.fixture.detectChanges();
    controller.expectOne(r => r.url === '/api/v1/audit-logs').flush(paged([makeLog()]));
    harness.fixture.detectChanges();

    findButton('Clear')!.click();
    harness.fixture.detectChanges();

    const req = controller.expectOne(r => r.url === '/api/v1/audit-logs');
    expect(req.request.params.get('action')).toBeNull();
    expect(req.request.params.get('page')).toBe('1');
    req.flush(paged([makeLog()]));
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).not.toContain('Filters ·');
  });

  it('opens the detail directly when the URL carries ?selected=', async () => {
    await createPage();
    flushUsers();
    flushList([makeLog()]);

    await harness.navigateByUrl('/audit-logs?selected=42');
    harness.fixture.detectChanges();
    flushDetail(makeLog());

    expect(harness.fixture.nativeElement.querySelector('.nx-item-row.selected')).toBeTruthy();
    expect(harness.fixture.nativeElement.textContent).toContain('Changed fields');
  });
});

describe('Audit route authorization', () => {
  let controller: HttpTestingController;
  let auth: AuthService;

  const guardedRoutes = [
    {
      path: 'audit-logs',
      component: AuditLogsPage,
      canActivate: [permissionGuard],
      data: { permission: 'view_audit_logs' },
    },
    { path: 'unauthorized', component: AuditLogsPage },
    { path: 'home', component: AuditLogsPage },
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
          name: 'Governance User',
          email: 'gov@nexora.test',
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

  it('lets an admin reach the workspace', async () => {
    const init = signIn('admin');
    const result = await TestBed.runInInjectionContext(() =>
      permissionGuard({ data: { permission: 'view_audit_logs' } } as never, { url: '/audit-logs' } as never),
    );
    await init;

    expect(result).toBe(true);
  });

  it('sends a role without view_audit_logs to the unauthorized route', async () => {
    const init = signIn('manager');
    const result = await TestBed.runInInjectionContext(() =>
      permissionGuard({ data: { permission: 'view_audit_logs' } } as never, { url: '/audit-logs' } as never),
    );
    await init;

    expect((result as UrlTree).toString()).toBe('/unauthorized');
    expect(TestBed.inject(Router).url).not.toBe('/audit-logs');
  });
});
