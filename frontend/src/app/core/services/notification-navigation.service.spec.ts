import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';

import { NotificationNavigationService } from './notification-navigation.service';
import { NexoraNotification } from './notification.service';

function notification(partial: Partial<NexoraNotification>): NexoraNotification {
  return {
    id: 1,
    type: 'ticket.assigned',
    title: 'Title',
    message: 'Message',
    data: {},
    is_read: false,
    read_at: null,
    created_at: '2025-03-01T08:00:00Z',
    ...partial,
  };
}

describe('NotificationNavigationService', () => {
  let service: NotificationNavigationService;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideRouter([])],
    });
    service = TestBed.inject(NotificationNavigationService);
    router = TestBed.inject(Router);
  });

  it('maps ticket notifications to /requests?selected=<ticket_id>', () => {
    const target = service.resolveTarget(notification({ type: 'ticket.assigned', data: { ticket_id: 42, ticket_number: 'TK-1042' } }));
    expect(target).toEqual({ path: '/requests', queryParams: { selected: 42 } });
  });

  it('maps ticket.status_changed the same way', () => {
    const target = service.resolveTarget(notification({ type: 'ticket.status_changed', data: { ticket_id: 7 } }));
    expect(target?.path).toBe('/requests');
    expect(target?.queryParams.selected).toBe(7);
  });

  it('maps maintenance notifications to /maintenance?selected=<request_id>', () => {
    for (const type of ['maintenance.assigned', 'maintenance.approved', 'maintenance.completed']) {
      const target = service.resolveTarget(notification({ type, data: { maintenance_request_id: 88 } }));
      expect(target).toEqual({ path: '/maintenance', queryParams: { selected: 88 } });
    }
  });

  it('maps asset notifications to /assets?selected=<asset_id>', () => {
    for (const type of ['asset.assigned', 'asset.returned']) {
      const target = service.resolveTarget(notification({ type, data: { asset_id: 12 } }));
      expect(target).toEqual({ path: '/assets', queryParams: { selected: 12 } });
    }
  });

  it('accepts numeric-string payload ids', () => {
    const target = service.resolveTarget(notification({ type: 'asset.returned', data: { asset_id: '12' } }));
    expect(target?.queryParams.selected).toBe(12);
  });

  it('returns null for unknown future types even with matching keys', () => {
    expect(service.resolveTarget(notification({ type: 'inventory.stock_low', data: { ticket_id: 42 } }))).toBeNull();
    expect(service.resolveTarget(notification({ type: 'custom.alert', data: { asset_id: 12 } }))).toBeNull();
  });

  it('returns null when the payload is missing or holds an invalid id', () => {
    expect(service.resolveTarget(notification({ type: 'ticket.assigned', data: {} }))).toBeNull();
    expect(service.resolveTarget(notification({ type: 'maintenance.approved', data: { maintenance_request_id: 0 } }))).toBeNull();
    expect(service.resolveTarget(notification({ type: 'asset.assigned', data: { asset_id: -3 } }))).toBeNull();
    expect(service.resolveTarget(notification({ type: 'asset.assigned', data: { asset_id: 'nope' } }))).toBeNull();
  });

  it('openRelated navigates and returns true for a resolvable target', () => {
    const navigateSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true);
    const opened = service.openRelated(notification({ type: 'ticket.assigned', data: { ticket_id: 42 } }));

    expect(opened).toBe(true);
    expect(navigateSpy).toHaveBeenCalledWith(['/requests'], { queryParams: { selected: 42 } });
  });

  it('openRelated returns false and does not navigate without a target', () => {
    const navigateSpy = vi.spyOn(router, 'navigate');
    const opened = service.openRelated(notification({ type: 'inventory.stock_low', data: {} }));

    expect(opened).toBe(false);
    expect(navigateSpy).not.toHaveBeenCalled();
  });
});