import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, UrlTree } from '@angular/router';

import { AuthService } from '../services/auth.service';
import { StorageService } from '../services/storage.service';
import { permissionGuard } from './permission.guard';

function routeData(permission: string | null): { data: { permission?: string } } {
  return { data: permission ? { permission } : {} };
}

function asManagerUser() {
  return {
    id: 2, name: 'Manager', email: 'manager@nexora.test',
    role: { id: 1, name: 'Manager', slug: 'manager' },
    department: null, is_active: true, created_at: null,
  };
}

describe('permissionGuard', () => {
  let controller: HttpTestingController;
  let service: AuthService;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    service = TestBed.inject(AuthService);
  });

  afterEach(() => {
    controller?.verify();
    localStorage.clear();
  });

  it('redirects an unauthenticated visitor to /login (authGuard delegation)', async () => {
    const result = await TestBed.runInInjectionContext(() =>
      permissionGuard(routeData('view_assets') as never, { url: '/assets' } as never),
    );
    expect((result as UrlTree).toString()).toBe('/login');
  });

  it('allows a user who holds the required permission', async () => {
    const storage = TestBed.inject(StorageService);
    storage.set('nx-token', 'session-token');
    storage.set('nx-user', {});
    const init = service.ensureInitialized();
    controller!.expectOne('/api/v1/auth/me').flush({
      success: true,
      message: 'Authenticated user',
      data: { user: asManagerUser() },
    });
    await init;

    const result = await TestBed.runInInjectionContext(() =>
      permissionGuard(routeData('view_assets') as never, { url: '/assets' } as never),
    );
    expect(result).toBe(true);
  });

  it('redirects a user who lacks the required permission to /unauthorized', async () => {
    const storage = TestBed.inject(StorageService);
    storage.set('nx-token', 'session-token');
    storage.set('nx-user', {});
    const init = service.ensureInitialized();
    controller!.expectOne('/api/v1/auth/me').flush({
      success: true,
      message: 'Authenticated user',
      data: { user: asManagerUser() },
    });
    await init;

    const result = await TestBed.runInInjectionContext(() =>
      permissionGuard(routeData('manage_assets') as never, { url: '/assets' } as never),
    );
    expect((result as UrlTree).toString()).toBe('/unauthorized');
  });

  it('allows a route with no permission requirement', async () => {
    const storage = TestBed.inject(StorageService);
    storage.set('nx-token', 'session-token');
    storage.set('nx-user', {});
    const init = service.ensureInitialized();
    controller!.expectOne('/api/v1/auth/me').flush({
      success: true,
      message: 'Authenticated user',
      data: { user: asManagerUser() },
    });
    await init;

    const result = await TestBed.runInInjectionContext(() =>
      permissionGuard(routeData(null) as never, { url: '/home' } as never),
    );
    expect(result).toBe(true);
  });
});