import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter, UrlTree } from '@angular/router';

import { AuthService } from '../services/auth.service';
import { StorageService } from '../services/storage.service';
import { authGuard } from './auth.guard';

describe('authGuard', () => {
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

  it('lets an authenticated visitor navigate', async () => {
    const storage = TestBed.inject(StorageService);
    storage.set('nx-token', 'session-token');
    storage.set('nx-user', {});
    const init = service.ensureInitialized();
    controller!.expectOne('/api/v1/auth/me').flush({
      success: true,
      message: 'Authenticated user',
      data: {
        user: {
          id: 1, name: 'Test', email: 'test@nexora.test', role: null,
          department: null, is_active: true, created_at: null,
        },
      },
    });
    await init;

    const result = await TestBed.runInInjectionContext(() => authGuard({} as never, {} as never));
    expect(result).toBe(true);
  });

  it('redirects an unauthenticated visitor to /login and remembers the safe return URL', async () => {
    const result = await TestBed.runInInjectionContext(() =>
      authGuard({} as never, { url: '/dashboard' } as never),
    );
    expect(result).not.toBe(true);
    expect((result as UrlTree).toString()).toBe('/login');
    expect(TestBed.inject(StorageService).get('nx-return-url')).toBe('/dashboard');
  });
});
