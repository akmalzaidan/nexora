import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpClientTestingModule, HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';

import { AuthService, isSafeInternalUrl, mapAuthError } from './auth.service';
import { StorageService } from './storage.service';

function flushSession(controller: HttpTestingController, token: string, user: Record<string, unknown>): void {
  const req = controller.expectOne('/api/v1/auth/login');
  expect(req.request.method).toBe('POST');
  req.flush({ success: true, message: 'Login successful', data: { token, user } });
}

describe('AuthService', () => {
  let service: AuthService;
  let controller: HttpTestingController;
  let storage: StorageService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [HttpClientTestingModule],
      providers: [
        AuthService,
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    });
    service = TestBed.inject(AuthService);
    controller = TestBed.inject(HttpTestingController);
    storage = TestBed.inject(StorageService);
    localStorage.clear();
  });

  afterEach(() => {
    controller.verify();
    localStorage.clear();
  });

  it('starts unauthenticated with no token or user', () => {
    expect(service.isAuthenticated()).toBe(false);
    expect(service.token()).toBeNull();
    expect(service.user()).toBeNull();
    expect(service.authError()).toBeNull();
  });

  it('leave the session empty when no token exists at startup', async () => {
    await service.ensureInitialized();
    expect(service.isAuthenticated()).toBe(false);
    expect(service.isInitialized()).toBe(true);
  });

  it('restores a persisted token + user at startup', async () => {
    const user = { id: 1, name: 'Akmal', email: 'akmal@nexora.test', role: null, department: null, is_active: true, created_at: null };
    storage.set('nx-token', 'restored-token');
    storage.set('nx-user', userObj(user));

    const restorePromise = service.ensureInitialized();
    const req = controller.expectOne('/api/v1/auth/me');
    expect(req.request.method).toBe('GET');
    req.flush({ success: true, message: 'Authenticated user', data: { user } });

    await restorePromise;
    expect(service.isAuthenticated()).toBe(true);
    expect(service.token()).toBe('restored-token');
    expect(service.user()?.name).toBe('Akmal');
  });

  it('clears an invalid persisted session when /me fails', async () => {
    storage.set('nx-token', 'stale-token');
    const restorePromise = service.ensureInitialized();
    controller.expectOne('/api/v1/auth/me').flush(
      { success: false, message: 'Unauthenticated.' },
      { status: 401, statusText: 'Unauthorized' },
    );

    await restorePromise;
    expect(service.isAuthenticated()).toBe(false);
    expect(service.token()).toBeNull();
    expect(storage.get('nx-token')).toBeNull();
  });

  it('authenticates on a successful login and persists the session', async () => {
    const user = { id: 1, name: 'Akmal', email: 'akmal@nexora.test', role: null, department: null, is_active: true, created_at: null };
    const okPromise = service.login({ email: 'akmal@nexora.test', password: 'secret123' });
    flushSession(controller, 'token-1', user);

    expect(await okPromise).toBe(true);
    expect(service.isAuthenticated()).toBe(true);
    expect(service.token()).toBe('token-1');
    expect(service.user()?.name).toBe('Akmal');
    expect(storage.get('nx-token')).toBe('token-1');
    expect(storage.get('nx-user')).toEqual(user);
  });

  it('maps a 401 login to a user-friendly auth error', async () => {
    const okPromise = service.login({ email: 'wrong@nexora.test', password: 'nope' });
    controller.expectOne('/api/v1/auth/login').flush(
      { success: false, message: 'Invalid credentials' },
      { status: 401, statusText: 'Unauthorized' },
    );

    expect(await okPromise).toBe(false);
    expect(service.isAuthenticated()).toBe(false);
    expect(service.authError()).toBe('Invalid email or password.');
  });

  it('exposes server-side field validation errors', async () => {
    const okPromise = service.login({ email: 'bad', password: 'x' });
    controller.expectOne('/api/v1/auth/login').flush(
      { success: false, message: 'Validation failed.', errors: { email: ['The email must be a valid email address.'] } },
      { status: 422, statusText: 'Unprocessable Entity' },
    );

    expect(await okPromise).toBe(false);
    expect(service.fieldErrors()['email']).toContain('valid email');
  });

  it('logs out, clearing the session and POSTing to the API', async () => {
    const user = { id: 1, name: 'Akmal', email: 'a@b.test', role: null, department: null, is_active: true, created_at: null };
    const loginResult = service.login({ email: 'a@b.test', password: 'secret123' });
    flushSession(controller, 'token-1', user);
    await loginResult;
    controller.verify();

    const logoutPromise = service.logout();
    const req = controller.expectOne('/api/v1/auth/logout');
    expect(req.request.method).toBe('POST');
    req.flush({ success: true, message: 'Logout successful', data: null });

    await logoutPromise;
    expect(service.isAuthenticated()).toBe(false);
    expect(service.token()).toBeNull();
    expect(service.user()).toBeNull();
    expect(storage.get('nx-token')).toBeNull();
    expect(storage.get('nx-user')).toBeNull();
  });

  it('clears the local session even when the server logout fails', async () => {
    const user = { id: 1, name: 'Akmal', email: 'a@b.test', role: null, department: null, is_active: true, created_at: null };
    const loginResult = service.login({ email: 'a@b.test', password: 'secret123' });
    flushSession(controller, 'token-1', user);
    await loginResult;
    controller.verify();

    const logoutPromise = service.logout();
    controller.expectOne('/api/v1/auth/logout').flush(
      { success: false, message: 'Something went wrong.' },
      { status: 500, statusText: 'Server Error' },
    );

    await logoutPromise;
    expect(service.isAuthenticated()).toBe(false);
    expect(storage.get('nx-token')).toBeNull();
  });
});

describe('isSafeInternalUrl', () => {
  it('accepts safe internal paths', () => {
    expect(isSafeInternalUrl('/home')).toBe(true);
    expect(isSafeInternalUrl('/assets/12')).toBe(true);
    expect(isSafeInternalUrl('/')).toBe(true);
  });

  it('rejects external and protocol-relative URLs', () => {
    expect(isSafeInternalUrl('https://evil.example')).toBe(false);
    expect(isSafeInternalUrl('//evil.example')).toBe(false);
    expect(isSafeInternalUrl('http://x')).toBe(false);
    expect(isSafeInternalUrl(null)).toBe(false);
    expect(isSafeInternalUrl(undefined)).toBe(false);
    expect(isSafeInternalUrl('')).toBe(false);
  });
});

describe('mapAuthError', () => {
  it('maps common HTTP statuses to friendly messages', () => {
    expect(mapAuthError(new HttpErrorResponse({ status: 401 })).message).toContain('email or password');
    expect(mapAuthError(new HttpErrorResponse({ status: 429 })).message).toContain('Too many attempts');
    expect(mapAuthError(new HttpErrorResponse({ status: 500 })).message).toContain('Something went wrong');
  });

  it('extracts 422 field errors', () => {
    const result = mapAuthError(
      new HttpErrorResponse({
        status: 422,
        error: { message: 'Validation failed.', errors: { email: ['The email field is required.'] } },
      }),
    );
    expect(result.fieldErrors['email']).toContain('required');
  });
});

function userObj(u: Record<string, unknown>): unknown {
  return u;
}
