import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, Router } from '@angular/router';

import { NotificationPanelComponent } from './notification-panel.component';
import { NexoraNotification } from '../../../core/services/notification.service';

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

function paginated<T>(items: T[], perPage = 6) {
  return {
    items,
    pagination: { current_page: 1, per_page: perPage, total: items.length, last_page: 1 },
  };
}

const readNotification: NexoraNotification = {
  id: 4,
  type: 'maintenance.completed',
  title: 'Maintenance completed',
  message: 'Request #88 was completed.',
  data: { maintenance_request_id: 88 },
  is_read: true,
  read_at: '2025-03-01T08:30:00Z',
  created_at: '2025-03-01T08:00:00Z',
};

const unreadNotification: NexoraNotification = {
  id: 7,
  type: 'ticket.assigned',
  title: 'New ticket assigned',
  message: 'TK-1042 was assigned to you.',
  data: { ticket_id: 42, ticket_number: 'TK-1042' },
  is_read: false,
  read_at: null,
  created_at: '2025-03-01T09:00:00Z',
};

describe('NotificationPanelComponent', () => {
  let controller: HttpTestingController;
  let router: Router;
  let fixture: ComponentFixture<NotificationPanelComponent>;

  async function createPanel(): Promise<void> {
    await TestBed.configureTestingModule({
      imports: [NotificationPanelComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    router = TestBed.inject(Router);
    fixture = TestBed.createComponent(NotificationPanelComponent);
    fixture.detectChanges();
  }

  function flushInit(items: NexoraNotification[], unread = 0): void {
    controller.match(req => req.url === '/api/v1/notifications/unread-count').forEach(req => req.flush(envelope({ count: unread })));
    controller.expectOne(r => r.url === '/api/v1/notifications' && r.method === 'GET').flush(envelope(paginated(items)));
    fixture.detectChanges();
  }

  function flushUnread(count: number): void {
    controller.expectOne('/api/v1/notifications/unread-count').flush(envelope({ count }));
    fixture.detectChanges();
  }

  afterEach(() => {
    controller?.verify();
  });

  it('renders the latest notifications and the unread badge on open', async () => {
    await createPanel();
    flushInit([unreadNotification, readNotification], 3);

    const text = fixture.nativeElement.textContent;
    expect(text).toContain('New ticket assigned');
    expect(text).toContain('Maintenance completed');
    expect(fixture.nativeElement.querySelector('.nx-panel-count')?.textContent?.trim()).toBe('3');
  });

  it('hides Mark all as read when nothing is unread', async () => {
    await createPanel();
    flushInit([readNotification], 0);

    expect(fixture.nativeElement.querySelector('.nx-panel-mark-all')).toBeNull();
  });

  it('clicking an unread row marks it read, refreshes the count and opens the record', async () => {
    await createPanel();
    flushInit([unreadNotification], 1);
    const navigateSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    const closeSpy = vi.spyOn(fixture.componentInstance.requestClose, 'emit');

    (fixture.nativeElement.querySelector('.nx-panel-item') as HTMLButtonElement).click();
    fixture.detectChanges();

    const markReq = controller.expectOne('/api/v1/notifications/7/read');
    expect(markReq.request.method).toBe('POST');
    markReq.flush(envelope({ ...unreadNotification, is_read: true, read_at: '2025-03-01T09:01:00Z' }));

    flushUnread(0);
    expect(navigateSpy).toHaveBeenCalledWith(['/requests'], { queryParams: { selected: 42 } });
    expect(closeSpy).toHaveBeenCalled();
  });

  it('clicking a read row opens the record without marking again', async () => {
    await createPanel();
    flushInit([readNotification], 0);
    const navigateSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true);

    (fixture.nativeElement.querySelector('.nx-panel-item') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(navigateSpy).toHaveBeenCalledWith(['/maintenance'], { queryParams: { selected: 88 } });
  });

  it('opens a toast and closes the panel when there is no related record', async () => {
    const noLink: NexoraNotification = {
      ...unreadNotification,
      type: 'custom.future_type',
      data: {},
    };
    await createPanel();
    flushInit([noLink], 1);
    const closeSpy = vi.spyOn(fixture.componentInstance.requestClose, 'emit');

    (fixture.nativeElement.querySelector('.nx-panel-item') as HTMLButtonElement).click();
    fixture.detectChanges();

    controller.expectOne('/api/v1/notifications/7/read').flush(envelope({ ...noLink, is_read: true, read_at: '2025-03-01T09:01:00Z' }));
    flushUnread(0);
    expect(closeSpy).toHaveBeenCalled();
  });

  it('Mark all as read posts, refreshes the count and reloads the preview', async () => {
    await createPanel();
    flushInit([unreadNotification], 2);
    fixture.detectChanges();

    (fixture.nativeElement.querySelector('.nx-panel-mark-all') as HTMLButtonElement).click();
    fixture.detectChanges();

    controller.expectOne('/api/v1/notifications/read-all').flush(envelope({ count: 2 }));
    flushUnread(0);
    controller.expectOne(r => r.url === '/api/v1/notifications' && r.method === 'GET').flush(envelope(paginated([readNotification])));
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent;
    expect(text).not.toContain('TK-1042');
    expect(text).toContain('Maintenance completed');
  });

  it('closes (unmounts) on Escape', async () => {
    await createPanel();
    flushInit([readNotification], 0);
    const closeSpy = vi.spyOn(fixture.componentInstance.requestClose, 'emit');

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(closeSpy).toHaveBeenCalled();
  });
});