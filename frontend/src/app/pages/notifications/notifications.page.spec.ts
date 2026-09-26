import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { IonicModule } from '@ionic/angular/lazy';

import { NotificationInboxService, NexoraNotification } from '../../core/services/notification.service';
import { NotificationsPage } from './notifications.page';

const routes = [{ path: 'notifications', component: NotificationsPage }];

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

function paged<T>(
  items: T[],
  opts: { page?: number; perPage?: number; total?: number; lastPage?: number } = {},
) {
  const total = opts.total ?? items.length;
  const perPage = opts.perPage ?? 15;
  const lastPage = opts.lastPage ?? Math.max(1, Math.ceil(total / perPage));
  return envelope({
    items,
    pagination: { current_page: opts.page ?? 1, per_page: perPage, total, last_page: lastPage },
  });
}

const readItem: NexoraNotification = {
  id: 4,
  type: 'maintenance.completed',
  title: 'Maintenance completed',
  message: 'Request #88 was completed.',
  data: { maintenance_request_id: 88 },
  is_read: true,
  read_at: '2025-03-01T08:30:00Z',
  created_at: '2025-03-01T08:00:00Z',
};

const unreadItem: NexoraNotification = {
  id: 7,
  type: 'ticket.assigned',
  title: 'New ticket assigned',
  message: 'TK-1042 was assigned to you.',
  data: { ticket_id: 42, ticket_number: 'TK-1042' },
  is_read: false,
  read_at: null,
  created_at: '2025-03-01T09:00:00Z',
};

describe('NotificationsPage', () => {
  let controller: HttpTestingController;
  let harness: RouterTestingHarness;

  async function createPage(): Promise<NotificationsPage> {
    harness = await RouterTestingHarness.create();
    return harness.navigateByUrl('/notifications', NotificationsPage);
  }

  /** Flush the list request; optional `assertParams` runs against the request first. */
  function flushList(items: NexoraNotification[], opts: { total?: number; lastPage?: number } = {}): void {
    const req = controller.expectOne(r => r.url === '/api/v1/notifications' && r.method === 'GET');
    req.flush(paged(items, opts));
    harness.fixture.detectChanges();
  }

  function flushUnread(count: number): void {
    controller.expectOne('/api/v1/notifications/unread-count').flush(envelope({ count }));
    harness.fixture.detectChanges();
  }

  function flushDetail(item: NexoraNotification): void {
    controller.expectOne(`/api/v1/notifications/${item.id}`).flush(envelope(item));
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
    localStorage.clear();
  });

  afterEach(() => {
    controller.verify();
    localStorage.clear();
  });

  it('renders the header, summary strip and notification rows', async () => {
    await createPage();
    flushList([unreadItem, readItem]);

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('Notifications');
    expect(text).toContain('New ticket assigned');
    expect(text).toContain('Maintenance completed');
    expect(text).toContain('2 notifications');
    expect(findButton('Mark all as read')).toBeNull();
  });

  it('marks all as read (header action) and refreshes the backend count', async () => {
    const inbox = TestBed.inject(NotificationInboxService);
    inbox.unreadCount.set(2);
    await createPage();
    flushList([unreadItem, readItem]);

    findButton('Mark all as read')!.click();
    harness.fixture.detectChanges();

    controller.expectOne('/api/v1/notifications/read-all').flush(envelope({ count: 2 }));
    flushUnread(0);
    flushList([{ ...readItem }, { ...unreadItem, is_read: true, read_at: '2025-03-01T09:02:00Z' }]);

    expect(inbox.unreadCount()).toBe(0);
    // With nothing unread, the Mark all as read action is hidden again.
    expect(findButton('Mark all as read')).toBeNull();
  });

  it('clicking an unread row marks it read, refreshes the count and opens its detail', async () => {
    await createPage();
    flushList([unreadItem, readItem]);

    const rows = Array.from(harness.fixture.nativeElement.querySelectorAll('.nx-item-row')) as HTMLButtonElement[];
    const row = rows.find(el => el.textContent?.includes('New ticket assigned'))!;
    row.click();
    harness.fixture.detectChanges();

    controller.expectOne('/api/v1/notifications/7/read').flush(envelope({ ...unreadItem, is_read: true, read_at: '2025-03-01T09:01:00Z' }));
    flushUnread(1);
    flushDetail({ ...unreadItem, is_read: true, read_at: '2025-03-01T09:01:00Z' });

    expect(harness.fixture.nativeElement.querySelector('.nx-detail-name')?.textContent).toContain('New ticket assigned');
  });

  it('filters by read state and sends the backend read param', async () => {
    await createPage();
    flushList([unreadItem, readItem]);

    const options = Array.from(harness.fixture.nativeElement.querySelectorAll('.nx-read-toggle-option')) as HTMLButtonElement[];
    const unreadButton = options.find(el => el.textContent?.trim() === 'Unread')!;
    unreadButton.click();
    harness.fixture.detectChanges();

    const unreadReq = controller.expectOne(r => r.url === '/api/v1/notifications');
    expect(unreadReq.request.params.get('read')).toBe('false');
    unreadReq.flush(paged([unreadItem]));
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('1 notification');
  });

  it('sends the exact backend type for the type filter selection', async () => {
    await createPage();
    flushList([unreadItem, readItem]);

    const select = harness.fixture.nativeElement.querySelector('#nx-notif-type') as HTMLSelectElement;
    select.value = 'maintenance.approved';
    select.dispatchEvent(new Event('change'));
    harness.fixture.detectChanges();

    const req = controller.expectOne(r => r.url === '/api/v1/notifications');
    expect(req.request.params.get('type')).toBe('maintenance.approved');
    expect(req.request.params.get('read')).toBeNull();
    req.flush(paged([readItem]));
    harness.fixture.detectChanges();
  });

  it('paginates with the backend page param', async () => {
    const many = Array.from({ length: 15 }, (_, i) => ({ ...readItem, id: i + 1 }));
    await createPage();
    flushList(many, { total: 45 });

    (harness.fixture.nativeElement.querySelector('.nx-page-button:last-of-type') as HTMLButtonElement).click();
    harness.fixture.detectChanges();

    const pageReq = controller.expectOne(r => r.url === '/api/v1/notifications');
    expect(pageReq.request.params.get('page')).toBe('2');
    pageReq.flush(paged(many, { page: 2, total: 45 }));
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('Page 2 of 3');
  });

  it('shows an error state with retry on failure', async () => {
    await createPage();
    controller.expectOne(r => r.url === '/api/v1/notifications').flush({ success: false, message: 'boom', data: null }, { status: 500, statusText: 'Error' });
    harness.fixture.detectChanges();

    expect(harness.fixture.nativeElement.textContent).toContain('Unable to load notifications');

    findButton('Retry')!.click();
    harness.fixture.detectChanges();

    flushList([unreadItem]);
    expect(harness.fixture.nativeElement.textContent).toContain('New ticket assigned');
  });

  it('opens a detail directly when the URL carries ?selected=', async () => {
    await createPage();
    flushList([unreadItem, readItem]);

    await harness.navigateByUrl('/notifications?selected=7');
    harness.fixture.detectChanges();
    flushDetail(unreadItem);

    expect(harness.fixture.nativeElement.querySelector('.nx-detail-name')?.textContent).toContain('New ticket assigned');
    expect(harness.fixture.nativeElement.querySelector('.nx-item-row.selected')).toBeTruthy();
  });

  it('renders a friendly message and Mark as read for an unread detail', async () => {
    await createPage();
    flushList([unreadItem, readItem]);

    (harness.fixture.nativeElement.querySelector('.nx-item-row') as HTMLButtonElement).click();
    harness.fixture.detectChanges();
    controller.expectOne('/api/v1/notifications/7/read').flush(envelope({ ...unreadItem, is_read: true, read_at: '2025-03-01T09:01:00Z' }));
    flushUnread(1);
    flushDetail(unreadItem);

    const text = harness.fixture.nativeElement.textContent;
    expect(text).toContain('TK-1042 was assigned to you.');
    expect(text).toContain('Mark as read');
  });
});