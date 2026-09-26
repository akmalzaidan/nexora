import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { ShellComponent } from './shell.component';
import { AuthService, NexoraUser } from '../../core/services/auth.service';
import { NotificationInboxService } from '../../core/services/notification.service';

/** Build a minimal backend UserResource-shaped user for session tests. */
function user(id: number): NexoraUser {
  return {
    id,
    name: `User ${id}`,
    email: `user${id}@nexora.test`,
    role: { id: 1, name: 'Staff', slug: 'staff' },
    department: null,
    is_active: true,
    created_at: '2025-01-01T00:00:00Z',
  };
}

/**
 * Shell-level session-isolation tests (Phase 17B §53):
 * the unread count initializes on login and notification state never
 * survives a logout into the next session.
 */
describe('ShellComponent notification session isolation', () => {
  let controller: HttpTestingController;
  let fixture: ComponentFixture<ShellComponent>;
  let auth: AuthService;
  let inbox: NotificationInboxService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ShellComponent],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
    inbox = TestBed.inject(NotificationInboxService);
    localStorage.clear();
  });

  afterEach(() => {
    controller?.verify();
    localStorage.clear();
  });

  function flushUnreadCount(count: number): void {
    controller.expectOne('/api/v1/notifications/unread-count').flush({ success: true, message: 'ok', data: { count } });
  }

  it('initializes the unread count when a session is present on boot', async () => {
    auth.token.set('token-a');
    auth.user.set(user(1));

    fixture = TestBed.createComponent(ShellComponent);
    fixture.detectChanges();

    flushUnreadCount(4);
    expect(inbox.unreadCount()).toBe(4);
  });

  it('does not fetch an unread count when signed out', async () => {
    fixture = TestBed.createComponent(ShellComponent);
    fixture.detectChanges();

    // No request is expected; verify() in afterEach asserts this.
    expect(inbox.unreadCount()).toBe(0);
  });

  it('clears notification state on logout so nothing crosses sessions', async () => {
    auth.token.set('token-a');
    auth.user.set(user(1));

    fixture = TestBed.createComponent(ShellComponent);
    fixture.detectChanges();
    flushUnreadCount(4);
    inbox.unreadCount.set(4);

    // Session ends (the effect watches auth.isAuthenticated()).
    auth.token.set(null);
    auth.user.set(null);
    await fixture.whenStable();
    fixture.detectChanges();

    expect(inbox.unreadCount()).toBe(0);
  });

  it('a previous user\'s notifications are absent after a new login', async () => {
    auth.token.set('token-a');
    auth.user.set(user(1));

    fixture = TestBed.createComponent(ShellComponent);
    fixture.detectChanges();
    flushUnreadCount(9);

    // Log out: the shell effect clears notification state.
    auth.token.set(null);
    auth.user.set(null);
    await fixture.whenStable();
    fixture.detectChanges();
    expect(inbox.unreadCount()).toBe(0);

    // A new login mounts a fresh shell (app re-entry), which loads a fresh count.
    fixture = TestBed.createComponent(ShellComponent);
    auth.token.set('token-b');
    auth.user.set(user(2));
    fixture.detectChanges();

    // The fresh session fetches its own count — never user A's data.
    flushUnreadCount(2);
    expect(inbox.unreadCount()).toBe(2);
  });
});
