import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpErrorResponse } from '@angular/common/http';

import {
  NotificationService,
  NotificationInboxService,
  NexoraNotification,
  NotificationListFilters,
  mapNotificationError,
  formatNotificationTime,
  notificationTypeMeta,
  NOTIFICATION_TYPE_META,
  notificationTypeGroups,
} from './notification.service';

function envelope<T>(data: T, message = 'ok') {
  return { success: true, message, data };
}

function paginated<T>(items: T[], total = items.length, perPage = 15) {
  return {
    items,
    pagination: { current_page: 1, per_page: perPage, total, last_page: 1 },
  };
}

const notification: NexoraNotification = {
  id: 7,
  type: 'ticket.assigned',
  title: 'New ticket assigned',
  message: 'TK-1042 was assigned to you.',
  data: { ticket_id: 42, ticket_number: 'TK-1042' },
  is_read: false,
  read_at: null,
  created_at: '2025-03-01T08:00:00Z',
};

describe('NotificationService', () => {
  let service: NotificationService;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(NotificationService);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
  });

  it('lists notifications with cleaned filter params', () => {
    const filters: NotificationListFilters = {
      read: false,
      type: 'ticket.assigned',
      page: 2,
      per_page: 15,
    };
    service.listNotifications(filters).subscribe(res => {
      expect(res.data.items.length).toBe(1);
      expect(res.data.pagination.total).toBe(1);
    });

    const req = controller.expectOne(r => r.url === '/api/v1/notifications');
    expect(req.request.method).toBe('GET');
    expect(req.request.params.get('read')).toBe('false');
    expect(req.request.params.get('type')).toBe('ticket.assigned');
    expect(req.request.params.get('page')).toBe('2');
    expect(req.request.params.get('per_page')).toBe('15');

    req.flush(envelope(paginated([notification])));
  });

  it('omits empty filters entirely (All / All-types state)', () => {
    service.listNotifications().subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/notifications');
    expect(req.request.params.keys()).toEqual([]);
    req.flush(envelope(paginated([])));
  });

  it('sends read=true when filtering to read notifications', () => {
    service.listNotifications({ read: true, per_page: 15 }).subscribe();

    const req = controller.expectOne(r => r.url === '/api/v1/notifications');
    expect(req.request.params.get('read')).toBe('true');
    req.flush(envelope(paginated([])));
  });

  it('fetches a single notification', () => {
    service.getNotification(7).subscribe(res => {
      expect(res.data.id).toBe(7);
    });

    const req = controller.expectOne('/api/v1/notifications/7');
    expect(req.request.method).toBe('GET');
    req.flush(envelope(notification));
  });

  it('fetches the unread count from the dedicated endpoint', () => {
    service.getUnreadCount().subscribe(res => {
      expect(res.data.count).toBe(3);
    });

    const req = controller.expectOne('/api/v1/notifications/unread-count');
    expect(req.request.method).toBe('GET');
    req.flush(envelope({ count: 3 }));
  });

  it('marks a notification read via POST read with an empty body', () => {
    service.markAsRead(7).subscribe(res => {
      expect(res.data.is_read).toBe(true);
    });

    const req = controller.expectOne('/api/v1/notifications/7/read');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({});
    req.flush(envelope({ ...notification, is_read: true, read_at: '2025-03-01T09:00:00Z' }));
  });

  it('marks all notifications read via POST read-all', () => {
    service.markAllAsRead().subscribe(res => {
      expect(res.data.count).toBe(2);
    });

    const req = controller.expectOne('/api/v1/notifications/read-all');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({});
    req.flush(envelope({ count: 2 }));
  });
});

describe('NotificationInboxService (session-wide unread count)', () => {
  let inbox: NotificationInboxService;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    inbox = TestBed.inject(NotificationInboxService);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    controller.verify();
  });

  it('loads the unread count from the backend endpoint', () => {
    inbox.loadUnreadCount();

    const req = controller.expectOne('/api/v1/notifications/unread-count');
    req.flush(envelope({ count: 5 }));
    expect(inbox.unreadCount()).toBe(5);
    expect(inbox.unreadLoading()).toBe(false);
  });

  it('suppresses overlapping refreshes while one is in flight', () => {
    inbox.loadUnreadCount();
    inbox.refreshUnreadCount();

    const req = controller.expectOne('/api/v1/notifications/unread-count');
    req.flush(envelope({ count: 2 }));
    expect(inbox.unreadCount()).toBe(2);
  });

  it('is non-blocking on failure and keeps the previous count', () => {
    inbox.loadUnreadCount();
    controller.expectOne('/api/v1/notifications/unread-count').flush(envelope({ count: 4 }));
    expect(inbox.unreadCount()).toBe(4);

    inbox.refreshUnreadCount();
    controller.expectOne('/api/v1/notifications/unread-count').flush({ success: false, message: 'offline' }, { status: 0, statusText: 'Network' });
    expect(inbox.unreadCount()).toBe(4);
    expect(inbox.unreadLoading()).toBe(false);
  });

  it('drops all cached state on logout (session isolation)', () => {
    inbox.unreadCount.set(9);
    inbox.unreadLoading.set(true);

    inbox.reset();
    expect(inbox.unreadCount()).toBe(0);
    expect(inbox.unreadLoading()).toBe(false);
  });
});

describe('mapNotificationError', () => {
  function httpError(status: number, body: unknown): HttpErrorResponse {
    return new HttpErrorResponse({ status, statusText: 'Error', error: body });
  }

  it('returns safe defaults for non-HTTP failures', () => {
    const info = mapNotificationError(new Error('boom'));
    expect(info.status).toBeNull();
    expect(info.message).toBe('Something went wrong. Please try again.');
    expect(info.fieldErrors).toEqual({});
  });

  it('maps 422 validation errors to per-field messages', () => {
    const info = mapNotificationError(httpError(422, {
      message: 'The given data was invalid.',
      errors: { type: ['The selected type is invalid.'] },
    }));
    expect(info.message).toBe('Please check the highlighted fields.');
    expect(info.fieldErrors['type']).toBe('The selected type is invalid.');
  });

  it('maps status codes to friendly messages', () => {
    expect(mapNotificationError(httpError(401, {})).message).toBe('Your session has expired. Please sign in again.');
    expect(mapNotificationError(httpError(403, {})).message).toBe('This action is unauthorized.');
    expect(mapNotificationError(httpError(404, {})).message).toBe('The notification no longer exists.');
    expect(mapNotificationError(httpError(429, {})).message).toBe('Too many attempts. Please try again later.');
  });

  it('maps network failures and 5xx', () => {
    expect(mapNotificationError(httpError(0, {})).message).toBe('Unable to connect to the server.');
    expect(mapNotificationError(httpError(500, {})).message).toBe('Something went wrong. Please try again.');
  });

  it('surfaces other backend messages verbatim', () => {
    const info = mapNotificationError(httpError(409, { message: 'Marking failed because the record changed.' }));
    expect(info.message).toBe('Marking failed because the record changed.');
  });
});

describe('formatNotificationTime', () => {
  const NOW = new Date('2025-03-10T12:00:00Z').getTime();

  it('handles invalid timestamps gracefully', () => {
    expect(formatNotificationTime(null, NOW)).toBe('');
    expect(formatNotificationTime('', NOW)).toBe('');
    expect(formatNotificationTime('not-a-date', NOW)).toBe('');
  });

  it('renders relative units under a week', () => {
    expect(formatNotificationTime(new Date(NOW - 10 * 1000).toISOString(), NOW)).toBe('Just now');
    expect(formatNotificationTime(new Date(NOW - 5 * 60 * 1000).toISOString(), NOW)).toBe('5 mins ago');
    expect(formatNotificationTime(new Date(NOW - 60 * 1000).toISOString(), NOW)).toBe('1 min ago');
    expect(formatNotificationTime(new Date(NOW - 2 * 3600 * 1000).toISOString(), NOW)).toBe('2 hrs ago');
    expect(formatNotificationTime(new Date(NOW - 24 * 3600 * 1000).toISOString(), NOW)).toBe('1 day ago');
    expect(formatNotificationTime(new Date(NOW - 6 * 24 * 3600 * 1000).toISOString(), NOW)).toBe('6 days ago');
  });

  it('falls back to an absolute date after a week', () => {
    const text = formatNotificationTime(new Date(NOW - 8 * 24 * 3600 * 1000).toISOString(), NOW);
    expect(text).toMatch(/Mar/i);
  });
});

describe('Notification type metadata', () => {
  it('covers every known backend type', () => {
    expect(Object.keys(NOTIFICATION_TYPE_META).sort()).toEqual([
      'asset.assigned',
      'asset.returned',
      'maintenance.approved',
      'maintenance.assigned',
      'maintenance.completed',
      'ticket.assigned',
      'ticket.status_changed',
    ]);
  });

  it('degrades gracefully for unknown future types', () => {
    const meta = notificationTypeMeta('future.fancy_type');
    expect(meta.label).toBe('Alert');
    expect(meta.icon).toBeTruthy();
    expect(meta.tone).toBeTruthy();
  });

  it('groups options by domain with exact backend type values', () => {
    const groups = notificationTypeGroups();
    const flat = groups.flatMap(group => group.options.map(o => o.value));
    expect(flat).toEqual(Object.keys(NOTIFICATION_TYPE_META));
    expect(groups[0].group).toBe('Ticket');
    expect(groups[2].group).toBe('Asset');
  });
});