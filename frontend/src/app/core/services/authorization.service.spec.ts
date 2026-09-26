import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { AuthService, NexoraUser } from './auth.service';
import { AuthorizationService } from './authorization.service';

function userWithRole(slug: string | null, name = 'Test User'): NexoraUser {
  return {
    id: 1,
    name,
    email: 'test@nexora.test',
    role: slug ? { id: 1, name: slug, slug } : null,
    department: null,
    is_active: true,
    created_at: null,
  };
}

describe('AuthorizationService', () => {
  let service: AuthorizationService;
  let auth: AuthService;
  let controller: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
    service = TestBed.inject(AuthorizationService);
  });

  afterEach(() => {
    controller?.verify();
    localStorage.clear();
  });

  it('reports no role or permission while unauthenticated', () => {
    expect(service.role()).toBeNull();
    expect(service.roleSlug()).toBeNull();
    expect(service.hasRole('staff')).toBe(false);
    expect(service.hasAnyRole(['staff', 'manager'])).toBe(false);
    expect(service.isSuperAdmin()).toBe(false);
    expect(service.hasPermission('view_assets')).toBe(false);
    expect(service.hasAnyPermission(['view_assets'])).toBe(false);
    expect(service.can('view_assets')).toBe(false);
    expect(service.roleLabel()).toBe('—');
  });

  it('answers role checks from the authenticated user', () => {
    auth.user.set(userWithRole('staff'));
    expect(service.role()?.slug).toBe('staff');
    expect(service.roleSlug()).toBe('staff');
    expect(service.hasRole('staff')).toBe(true);
    expect(service.hasRole('manager')).toBe(false);
    expect(service.hasAnyRole(['manager', 'staff'])).toBe(true);
    expect(service.hasAnyRole(['manager', 'technician'])).toBe(false);
    expect(service.isSuperAdmin()).toBe(false);
  });

  it('grants permissions seeded to the role', () => {
    auth.user.set(userWithRole('manager'));
    expect(service.hasPermission('view_assets')).toBe(true);
    expect(service.hasPermission('assign_assets')).toBe(true);
    expect(service.hasPermission('view_maintenance')).toBe(true);
    expect(service.can('view_reports')).toBe(true);
  });

  it('denies permissions not seeded to the role', () => {
    auth.user.set(userWithRole('staff'));
    expect(service.hasPermission('manage_assets')).toBe(false);
    expect(service.hasPermission('view_users')).toBe(false);
    expect(service.hasPermission('manage_users')).toBe(false);
    expect(service.hasAnyPermission(['manage_assets', 'manage_users'])).toBe(false);
  });

  it('resolves any-permission when at least one is held', () => {
    auth.user.set(userWithRole('technician'));
    expect(service.hasAnyPermission(['manage_maintenance', 'manage_assets'])).toBe(true);
    expect(service.hasAnyPermission(['manage_assets', 'view_reports'])).toBe(false);
  });

  it('lets super admin bypass permission checks', () => {
    auth.user.set(userWithRole('super_admin'));
    expect(service.isSuperAdmin()).toBe(true);
    expect(service.hasPermission('manage_users')).toBe(true);
    expect(service.can('not_a_real_permission')).toBe(true);
  });

  it('falls back to the display label for the role', () => {
    const user = userWithRole('warehouse_staff');
    user.role = { id: 1, name: '', slug: 'warehouse_staff' };
    auth.user.set(user);
    expect(service.roleLabel()).toBe('Warehouse Staff');
  });

  it('prefers the backend-provided role name over the slug map', () => {
    const user = userWithRole('staff');
    user.role = { id: 1, name: 'Operator', slug: 'staff' };
    auth.user.set(user);
    expect(service.roleLabel()).toBe('Operator');
  });

  it('reacts to session changes', () => {
    auth.user.set(userWithRole('staff'));
    expect(service.hasPermission('view_assets')).toBe(true);
    expect(service.hasPermission('manage_assets')).toBe(false);

    auth.user.set(null);
    expect(service.hasPermission('view_assets')).toBe(false);
    expect(service.hasRole('staff')).toBe(false);
  });
});