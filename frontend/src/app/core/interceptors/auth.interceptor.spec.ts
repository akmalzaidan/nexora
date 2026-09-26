import { TestBed } from '@angular/core/testing';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { AuthService } from '../services/auth.service';
import { StorageService } from '../services/storage.service';
import { authInterceptor } from './auth.interceptor';

describe('authInterceptor', () => {
  let controller: HttpTestingController | null = null;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    });
    controller = TestBed.inject(HttpTestingController);
    localStorage.clear();
  });

  afterEach(() => {
    controller?.verify();
    localStorage.clear();
  });

  async function seedSession(token: string): Promise<void> {
    const storage = TestBed.inject(StorageService);
    const auth = TestBed.inject(AuthService);
    const user = {
      id: 1, name: 'Akmal', email: 'akmal@nexora.test', role: null, department: null, is_active: true, created_at: null,
    };
    storage.set('nx-token', token);
    storage.set('nx-user', user);
    const initPromise = auth.ensureInitialized();
    controller?.expectOne('/api/v1/auth/me').flush({
      success: true, message: 'Authenticated user',
      data: { user: { id: 1, name: 'Akmal', email: 'akmal@nexora.test', role: null, department: null, is_active: true, created_at: null } },
    });
    await initPromise;
  }

  it('attaches the bearer token to API requests', async () => {
    await seedSession('session-token');
    const http = TestBed.inject(HttpClient);

    http.get<unknown>('/api/v1/categories').subscribe();

    const request = controller!.expectOne('/api/v1/categories');
    expect(request.request.headers.get('Authorization')).toBe('Bearer session-token');
    request.flush({ success: true, message: 'ok', data: null });
  });

  it('leaves requests bare when no token exists', () => {
    const http = TestBed.inject(HttpClient);

    http.get<unknown>('/api/v1/categories').subscribe();

    const request = controller!.expectOne('/api/v1/categories');
    expect(request.request.headers.has('Authorization')).toBe(false);
    request.flush({ success: true, message: 'ok', data: null });
  });

  it('does not touch non-API requests', () => {
    const http = TestBed.inject(HttpClient);

    http.get<unknown>('/templates/login.html').subscribe();

    const request = controller!.expectOne('/templates/login.html');
    expect(request.request.headers.has('Authorization')).toBe(false);
    request.flush('<html></html>');
  });

  it('keeps the session when the public login endpoint returns 401', async () => {
    await seedSession('session-token');
    const auth = TestBed.inject(AuthService);
    const http = TestBed.inject(HttpClient);

    http.post<unknown>('/api/v1/auth/login', {}).subscribe({ error: () => undefined });

    const request = controller!.expectOne('/api/v1/auth/login');
    request.flush(
      { success: false, message: 'Invalid credentials.' },
      { status: 401, statusText: 'Unauthorized' },
    );

    expect(auth.isAuthenticated()).toBe(true);
  });

  it('clears the session when a protected API endpoint returns 401', async () => {
    await seedSession('session-token');
    const auth = TestBed.inject(AuthService);
    const http = TestBed.inject(HttpClient);

    http.get<unknown>('/api/v1/assets/1').subscribe({ error: () => undefined });

    const request = controller!.expectOne('/api/v1/assets/1');
    request.flush(
      { success: false, message: 'Unauthenticated.' },
      { status: 401, statusText: 'Unauthorized' },
    );

    expect(auth.isAuthenticated()).toBe(false);
  });
});
