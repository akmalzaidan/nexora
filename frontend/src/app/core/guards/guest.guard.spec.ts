import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { AuthService } from '../services/auth.service';
import { StorageService } from '../services/storage.service';
import { guestGuard } from './guest.guard';

describe('guestGuard', () => {
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

  it('lets an unauthenticated visitor onto public routes', async () => {
    const result = await TestBed.runInInjectionContext(() => guestGuard({} as never, {} as never));
    expect(result).toBe(true);
  });

  it('redirects an authenticated visitor away from public routes', async () => {
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

    const result = await TestBed.runInInjectionContext(() => guestGuard({} as never, {} as never));
    expect(result).not.toBe(true);
  });
});
