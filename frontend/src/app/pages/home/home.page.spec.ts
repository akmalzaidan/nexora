import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting, TestRequest } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { By } from '@angular/platform-browser';
import { IonicModule } from '@ionic/angular/lazy';

import { AuthService } from '../../core/services/auth.service';
import { StorageService } from '../../core/services/storage.service';
import { ThemeService } from '../../core/services/theme.service';
import type { CommandCenterPayload } from '../../core/services/command-center.service';
import { HomePage } from './home.page';

const COMMAND_CENTER_URL = '/api/v1/command-center';

@Component({ standalone: true, template: '' })
class StubPage {}

const routes = [
  { path: 'home', component: HomePage },
  { path: 'requests', component: StubPage },
  { path: 'maintenance', component: StubPage },
];

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

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

/** Every section present: an admin with all read permissions. */
const fullPayload: CommandCenterPayload = {
  generated_at: '2026-09-26T04:15:43.000000Z',
  snapshot: {
    assets: { total: 48, active: 41, in_maintenance: 5, unassigned: 12 },
    inventory: { item_count: 30, warehouse_count: 3, stock_quantity: 812 },
    tickets: { total: 64, active: 22, unassigned: 9 },
    maintenance: { total: 27, active: 11, unassigned: 6, awaiting_approval: 4 },
    notifications: { unread_count: 3 },
  },
  queues: {
    unassigned_tickets: {
      count: 9,
      limit: 10,
      items: [
        {
          id: 91,
          type: 'ticket',
          reference: 'TCK-7F3A9K2M',
          title: 'Projector lamp flickering',
          status: 'OPEN',
          created_at: '2026-09-26T08:02:00.000000Z',
        },
        {
          id: 92,
          type: 'ticket',
          reference: null,
          title: 'Replacement request',
          status: 'PENDING',
          created_at: '2026-09-26T07:40:00.000000Z',
        },
      ],
    },
    unassigned_maintenance_requests: {
      count: 1,
      limit: 10,
      items: [
        {
          id: 18,
          type: 'maintenance_request',
          reference: 'MNT-000218',
          title: 'Air filter replacement',
          status: 'SUBMITTED',
          created_at: '2026-09-25T16:20:00.000000Z',
        },
      ],
    },
    pending_asset_assignments: {
      count: 1,
      limit: 10,
      items: [
        {
          id: 300,
          type: 'asset_assignment',
          reference: 'AST-000112',
          title: 'Hand over laptop to Rina',
          status: 'PENDING',
          created_at: '2026-09-25T12:00:00.000000Z',
        },
      ],
    },
  },
  recent_activity: {
    limit: 10,
    items: [
      {
        type: 'maintenance',
        action: 'WORK_COMPLETED',
        occurred_at: '2026-09-26T08:44:00.000000Z',
        record_id: 18,
        reference: 'AST-000112',
        label: 'Replace projector lamp',
        status: 'COMPLETED',
        quantity: null,
        old_status: null,
        new_status: null,
      },
      {
        type: 'stock_movement',
        action: 'STOCK_OUT',
        occurred_at: '2026-09-26T08:10:00.000000Z',
        record_id: 77,
        reference: 'STK-000451',
        label: 'USB-C dock',
        status: null,
        quantity: 4,
        old_status: 'APPROVED',
        new_status: 'ISSUED',
      },
      {
        type: 'asset',
        action: 'SOMETHING_NEW',
        occurred_at: '2026-09-26T07:00:00.000000Z',
        record_id: 5,
        reference: null,
        label: null,
        status: null,
        quantity: null,
        old_status: null,
        new_status: null,
      },
    ],
  },
};

/** A warehouse_staff payload: the API omits every unreadable section. */
const scopedPayload: CommandCenterPayload = {
  generated_at: '2026-09-26T04:15:43.000000Z',
  snapshot: {
    inventory: { item_count: 30, warehouse_count: 3, stock_quantity: 812 },
    notifications: { unread_count: 0 },
  },
  queues: {},
  recent_activity: { limit: 10, items: [] },
};

/** A payload where nothing at all is recorded. */
const emptyPayload: CommandCenterPayload = {
  generated_at: '2026-09-26T04:15:43.000000Z',
  snapshot: {
    assets: { total: 0, active: 0, in_maintenance: 0, unassigned: 0 },
    inventory: { item_count: 0, warehouse_count: 0, stock_quantity: 0 },
    tickets: { total: 0, active: 0, unassigned: 0 },
    maintenance: { total: 0, active: 0, unassigned: 0, awaiting_approval: 0 },
    notifications: { unread_count: 0 },
  },
  queues: {},
  recent_activity: { limit: 10, items: [] },
};

describe('HomePage (Command Center)', () => {
  let controller: HttpTestingController;
  let harness: RouterTestingHarness;

  async function createPage(roleSlug = 'admin'): Promise<HomePage> {
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
    return harness.navigateByUrl('/home', HomePage);
  }

  function flushSnapshot(payload: CommandCenterPayload = fullPayload): TestRequest {
    // `expectOne` removes the matched request from the backend's open list, so
    // callers needing the request afterwards use the returned handle instead
    // of matching a second time and failing with "found none".
    const request = controller.expectOne(r => r.url === COMMAND_CENTER_URL);
    request.flush(envelope(payload));
    harness.fixture.detectChanges();
    return request;
  }

  function bodyText(): string {
    return (harness.fixture.nativeElement as HTMLElement).textContent ?? '';
  }

  function rowButtons(): HTMLButtonElement[] {
    return harness.fixture.debugElement
      .queryAll(By.css('button.nx-cc-row-link'))
      .map(de => de.nativeElement as HTMLButtonElement);
  }

  beforeEach(async () => {
    localStorage.clear();
    await TestBed.configureTestingModule({
      imports: [IonicModule.forRoot()],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter(routes)],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
    localStorage.clear();
  });

  // ─── Data fidelity ─────────────────────────────────────────────────────────

  it('renders the snapshot from a single request and no other endpoint', async () => {
    const page = await createPage();

    const req = flushSnapshot();

    // No reports call, no domain list calls, no second dashboard request.
    expect(controller.match(() => true).length).toBe(0);
    expect(req.request.method).toBe('GET');
    expect(req.request.params.keys()).toEqual([]);
    expect(page.data()!.snapshot.assets!.total).toBe(48);
    expect(page.snapshotGroups().map(group => group.key)).toEqual([
      'assets',
      'inventory',
      'tickets',
      'maintenance',
      'notifications',
    ]);
  });

  it('displays each metric exactly as the API returned it', async () => {
    await createPage();
    flushSnapshot();

    const text = bodyText();
    expect(text).toContain('Assets');
    expect(text).toContain('Stock quantity');
    expect(text).toContain('Awaiting approval');
    expect(text).toContain('48');
    expect(text).toContain('812');
  });

  it('omits sections the API omitted instead of showing them as zero', async () => {
    const page = await createPage('warehouse_staff');
    flushSnapshot(scopedPayload);

    expect(page.snapshotGroups().map(group => group.key)).toEqual(['inventory', 'notifications']);
    expect(page.queueGroups()).toEqual([]);

    const text = bodyText();
    expect(text).toContain('Inventory');
    expect(text).not.toContain('Requests');
    expect(text).not.toContain('Awaiting approval');
    expect(text).toContain('No queue is available to your role.');
  });

  it('shows the snapshot timestamp returned by the API', async () => {
    await createPage();
    flushSnapshot();

    expect(bodyText()).toContain('As of');
  });

  it('shows a genuine empty workspace instead of a page of zeros', async () => {
    const page = await createPage();
    flushSnapshot(emptyPayload);

    expect(page.isEmpty()).toBe(true);
    expect(bodyText()).toContain('Nothing to coordinate');
  });

  // ─── Queues ────────────────────────────────────────────────────────────────

  it('renders queue counts and the honest truncation of a bounded list', async () => {
    const page = await createPage();
    flushSnapshot({
      ...fullPayload,
      queues: {
        unassigned_tickets: {
          count: 12,
          limit: 2,
          items: fullPayload.queues.unassigned_tickets!.items,
        },
      },
    });

    expect(page.queueGroups()[0].queue.count).toBe(12);
    expect(bodyText()).toContain('Showing 2 of 12');
  });

  it('marks a queue that is waiting but returned no items', async () => {
    await createPage();
    flushSnapshot({
      ...fullPayload,
      queues: { unassigned_tickets: { count: 4, limit: 10, items: [] } },
    });

    expect(bodyText()).toContain('Waiting, but no details are loaded for this queue.');
  });

  it('opens a ticket row in Requests using the record id', async () => {
    const page = await createPage();
    flushSnapshot();

    const buttons = rowButtons();
    expect(buttons.length).toBe(3); // two tickets + one maintenance request
    page.openQueueRow(page.queueGroups()[0].rows[0]);
    await harness.fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/requests?selected=91');
  });

  it('opens a maintenance-request row in Maintenance using the record id', async () => {
    const page = await createPage();
    flushSnapshot();

    page.openQueueRow(page.queueGroups()[1].rows[0]);
    await harness.fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/maintenance?selected=18');
  });

  it('activates a navigable row with a real button, not a clickable div', async () => {
    await createPage();
    flushSnapshot();

    const buttons = rowButtons();
    expect(buttons[0].tagName).toBe('BUTTON');
    expect(buttons[0].getAttribute('type')).toBe('button');
    expect(buttons[0].getAttribute('aria-label')).toContain('TCK-7F3A9K2M');
  });

  it('keeps an asset-assignment row read-only because its id is not an asset id', async () => {
    const page = await createPage();
    flushSnapshot();

    const assignmentGroup = page.queueGroups().find(group => group.key === 'pending_asset_assignments')!;
    expect(assignmentGroup.rows[0].target).toBeNull();
    expect(rowButtons().length).toBe(3); // 2 tickets + 1 maintenance request only

    // The row is still displayed, just not as a link.
    expect(bodyText()).toContain('Hand over laptop to Rina');
  });

  it('does nothing when a read-only row is activated programmatically', async () => {
    const page = await createPage();
    flushSnapshot();

    const assignmentGroup = page.queueGroups().find(group => group.key === 'pending_asset_assignments')!;
    page.openQueueRow(assignmentGroup.rows[0]);
    await harness.fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/home');
  });

  it('survives a ticket reference being null', async () => {
    await createPage();
    flushSnapshot();

    expect(bodyText()).toContain('Replacement request');
  });

  // ─── Activity ──────────────────────────────────────────────────────────────

  it('renders activity in the order the API returned it, without re-sorting', async () => {
    await createPage();
    flushSnapshot();

    const events = harness.fixture.debugElement
      .queryAll(By.css('.nx-cc-event'))
      .map(de => de.nativeElement as HTMLElement);
    expect(events.length).toBe(3);
    expect(events[0].textContent).toContain('Maintenance completed');
    expect(events[1].textContent).toContain('Stock issued');
  });

  it('humanizes known actions and keeps the source value on the element', async () => {
    await createPage();
    flushSnapshot();

    const events = harness.fixture.debugElement
      .queryAll(By.css('.nx-cc-event'))
      .map(de => de.nativeElement as HTMLElement);

    expect(events[0].getAttribute('data-action')).toBe('WORK_COMPLETED');
    expect(events[1].getAttribute('data-type')).toBe('stock_movement');
  });

  it('shows an unknown action as itself rather than hiding or guessing it', async () => {
    await createPage();
    flushSnapshot();

    expect(bodyText()).toContain('SOMETHING_NEW');
  });

  it('shows a recorded status transition and a quantity as stored', async () => {
    await createPage();
    flushSnapshot();

    const stockEvent = harness.fixture.debugElement
      .queryAll(By.css('.nx-cc-event'))[1].nativeElement as HTMLElement;

    expect(stockEvent.textContent).toContain('APPROVED');
    expect(stockEvent.textContent).toContain('ISSUED');
    expect(stockEvent.textContent).toContain('4');
  });

  it('never makes an activity row a link, because record_id is an event row id', async () => {
    const page = await createPage();
    flushSnapshot();

    const events = harness.fixture.debugElement.queryAll(By.css('.nx-cc-event'));
    events.forEach(event => {
      expect((event.nativeElement as HTMLElement).tagName).toBe('LI');
      expect(event.nativeElement.querySelector('button')).toBeNull();
      expect(event.nativeElement.querySelector('a')).toBeNull();
    });
    expect(page.activity().length).toBe(3);
  });

  it('reports an activity list that is empty without inventing events', async () => {
    await createPage();
    flushSnapshot({ ...scopedPayload, snapshot: fullPayload.snapshot });

    expect(bodyText()).toContain('No events recorded yet.');
  });

  // ─── Loading, refresh, and failure ─────────────────────────────────────────

  it('shows a skeleton on the first load and no snapshot controls data', async () => {
    const page = await createPage();
    harness.fixture.detectChanges();

    expect(page.loading()).toBe(true);
    expect(harness.fixture.debugElement.query(By.css('.nx-cc-skeleton'))).toBeTruthy();
    expect(bodyText()).toContain('Loading the current operational state.');

    flushSnapshot();
    expect(harness.fixture.debugElement.query(By.css('.nx-cc-skeleton'))).toBeNull();
  });

  it('refreshes manually, keeps the current snapshot visible, and does not poll', async () => {
    const page = await createPage();
    flushSnapshot();

    page.refresh();
    harness.fixture.detectChanges();

    expect(page.refreshing()).toBe(true);
    expect(page.hasSnapshot()).toBe(true);
    expect(bodyText()).toContain('Refreshing the current operational state.');
    // Content stays on screen during a refresh rather than collapsing.
    expect(bodyText()).toContain('48');

    flushSnapshot();

    // Nothing refetches on its own once the snapshot is loaded.
    await new Promise(resolve => setTimeout(resolve, 60));
    expect(controller.match(r => r.url === COMMAND_CENTER_URL).length).toBe(0);
  });

  it('disables refresh while a request is in flight and ignores a second click', async () => {
    const page = await createPage();
    flushSnapshot();

    page.refresh();
    page.refresh();
    page.refresh();

    expect(page.busy()).toBe(true);
    flushSnapshot();

    // Three clicks, one request: the extra clicks were dropped, not queued.
    expect(page.busy()).toBe(false);
    expect(controller.match(r => r.url === COMMAND_CENTER_URL).length).toBe(0);
  });

  it('shows an error state with a retry when the first load fails', async () => {
    const page = await createPage();
    harness.fixture.detectChanges();

    controller
      .expectOne(r => r.url === COMMAND_CENTER_URL)
      .flush({ success: false, message: 'Server error.' }, { status: 500, statusText: 'Error' });
    harness.fixture.detectChanges();

    expect(page.hasSnapshot()).toBe(false);
    expect(bodyText()).toContain('Command Center unavailable');
    expect(bodyText()).toContain('The server could not build the snapshot. Please try again.');
    expect(harness.fixture.debugElement.query(By.css('app-nx-error-state'))).toBeTruthy();

    page.refresh();
    flushSnapshot();
    expect(page.hasSnapshot()).toBe(true);
  });

  it('reports a 401 as an expired session rather than a generic failure', async () => {
    await createPage();
    controller
      .expectOne(r => r.url === COMMAND_CENTER_URL)
      .flush(null, { status: 401, statusText: 'Unauthorized' });
    harness.fixture.detectChanges();

    expect(bodyText()).toContain('Your session has expired. Please sign in again.');
  });

  it('reports a 403 as restricted and offers no retry that cannot succeed', async () => {
    const page = await createPage();
    harness.fixture.detectChanges();

    controller
      .expectOne(r => r.url === COMMAND_CENTER_URL)
      .flush({ success: false, message: 'Forbidden.' }, { status: 403, statusText: 'Forbidden' });
    harness.fixture.detectChanges();

    expect(page.error()!.accessDenied).toBe(true);
    expect(bodyText()).toContain('Access restricted');
    expect(bodyText()).toContain('You do not have access to the Command Center.');
    expect(controller.match(r => r.url === COMMAND_CENTER_URL).length).toBe(0);
  });

  it('keeps the last snapshot on screen when a refresh fails', async () => {
    const page = await createPage();
    flushSnapshot();

    page.refresh();
    controller
      .expectOne(r => r.url === COMMAND_CENTER_URL)
      .flush({ success: false, message: 'Server error.' }, { status: 500, statusText: 'Error' });
    harness.fixture.detectChanges();

    expect(page.hasSnapshot()).toBe(true);
    expect(bodyText()).toContain('The figures below are the last ones loaded.');
    expect(bodyText()).toContain('48');
    expect(harness.fixture.debugElement.query(By.css('app-nx-error-state'))).toBeNull();
  });

  // ─── Access ────────────────────────────────────────────────────────────────

  it('requests nothing for a role without view_dashboard and stays usable', async () => {
    const page = await createPage('technician');
    harness.fixture.detectChanges();

    expect(page.canView()).toBe(false);
    expect(controller.match(r => r.url === COMMAND_CENTER_URL).length).toBe(0);
    expect(bodyText()).toContain('ACCESS RESTRICTED');
    expect(harness.fixture.debugElement.query(By.css('app-nx-error-state'))).toBeNull();
  });

  it('offers a technician the workspaces they can still open', async () => {
    const page = await createPage('technician');
    harness.fixture.detectChanges();

    // technician holds view_tickets and view_assets, but not view_inventory.
    expect(page.escapeRoutes().map(link => link.label)).toEqual(['Requests', 'Assets']);

    page.go('/requests');
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/requests');
  });

  it('keeps refresh inert when the caller cannot open the workspace', async () => {
    const page = await createPage('technician');
    harness.fixture.detectChanges();

    const button = harness.fixture.debugElement.query(By.css('.nx-cc-header-actions app-nx-button'));
    expect(button.componentInstance.disabled()).toBe(true);

    page.refresh();
    expect(controller.match(r => r.url === COMMAND_CENTER_URL).length).toBe(0);
  });

  // ─── Quick actions ─────────────────────────────────────────────────────────

  it('gates mutating quick actions on the capability that performs them', async () => {
    const page = await createPage('staff');
    flushSnapshot();

    // staff: no manage_assets, no assign_assets, no manage_tickets, no view_reports.
    const labels = page.visibleQuickActions().map(action => action.label);
    expect(labels).not.toContain('Create asset');
    expect(labels).not.toContain('Assign asset');
    expect(labels).not.toContain('Create request');
    expect(labels).not.toContain('Reports');
    expect(labels).toContain('Assets');
    expect(labels).toContain('Requests');
    expect(labels).toContain('Maintenance');
    expect(labels).toContain('Inventory');
  });

  it('offers every preserved action to a super admin', async () => {
    const page = await createPage('super_admin');
    flushSnapshot();

    const labels = page.visibleQuickActions().map(action => action.label);
    ['Create asset', 'Scan asset', 'Assign asset', 'Create request', 'Reports', 'People', 'Locations', 'Notifications', 'Toggle theme']
      .forEach(label => expect(labels).toContain(label));
  });

  it('navigates a quick action instead of only logging it', async () => {
    const page = await createPage('super_admin');
    flushSnapshot();

    const requests = page.visibleQuickActions().find(action => action.label === 'Requests')!;
    page.onQuickAction(requests);
    await harness.fixture.whenStable();

    expect(TestBed.inject(Router).url).toBe('/requests');
  });

  it('toggles the theme from the quick action', async () => {
    const theme = TestBed.inject(ThemeService);
    theme.setMode('dark');
    const page = await createPage('staff');
    flushSnapshot();

    const themeAction = page.visibleQuickActions().find(action => action.action === 'theme')!;
    page.onQuickAction(themeAction);

    expect(theme.mode()).toBe('light');
  });

  it('renders every quick action as a real button with an accessible name', async () => {
    const page = await createPage('staff');
    flushSnapshot();

    const buttons = harness.fixture.debugElement
      .queryAll(By.css('.nx-cc-action'))
      .map(de => de.nativeElement as HTMLButtonElement);

    expect(buttons.length).toBe(page.visibleQuickActions().length);
    buttons.forEach(button => {
      expect(button.tagName).toBe('BUTTON');
      expect(button.getAttribute('type')).toBe('button');
      expect(button.getAttribute('aria-label')).toBeTruthy();
    });
  });

  // ─── Accessibility and structure ───────────────────────────────────────────

  it('exposes one atomic live region describing the page state', async () => {
    const page = await createPage();
    harness.fixture.detectChanges();

    const live = harness.fixture.debugElement.queryAll(By.css('[role="status"]'));
    expect(live.length).toBe(1);
    expect(live[0].nativeElement.getAttribute('aria-atomic')).toBe('true');

    flushSnapshot();
    expect(bodyText()).toContain('Current operational state as of');
  });

  it('does not render its own global toolbar, leaving the shell header alone', async () => {
    await createPage();
    flushSnapshot();

    expect(harness.fixture.debugElement.query(By.css('ion-header'))).toBeNull();
    expect(harness.fixture.debugElement.query(By.css('ion-toolbar'))).toBeNull();
    expect(bodyText()).toContain('Command Center');
  });

  it('labels its regions for screen readers', async () => {
    await createPage();
    flushSnapshot();

    expect(
      harness.fixture.debugElement.query(By.css('[aria-label="Current operational state"]')),
    ).toBeTruthy();
    expect(harness.fixture.debugElement.query(By.css('[aria-label="Quick actions"]'))).toBeTruthy();

    const panelTitles = harness.fixture.debugElement
      .queryAll(By.css('.nx-panel-title'))
      .map(de => (de.nativeElement as HTMLElement).textContent?.trim());
    expect(panelTitles).toEqual(['Operational queues', 'Recent activity']);
  });

  it('gives every activity event a machine-readable datetime', async () => {
    await createPage();
    flushSnapshot();

    const times = harness.fixture.debugElement
      .queryAll(By.css('.nx-cc-event-time'))
      .map(de => de.nativeElement as HTMLTimeElement);

    expect(times.length).toBe(3);
    times.forEach(time => expect(time.getAttribute('datetime')).toMatch(/^\d{4}-\d{2}-\d{2}T/));
  });
});
