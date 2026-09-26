import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { AuthService, NexoraUser } from '../../core/services/auth.service';
import { SidebarComponent } from './sidebar.component';

function userWithRole(slug: string | null): NexoraUser {
  return {
    id: 1,
    name: 'Test User',
    email: 'test@nexora.test',
    role: slug ? { id: 1, name: slug, slug } : null,
    department: null,
    is_active: true,
    created_at: null,
  };
}

describe('SidebarComponent permissions', () => {
  let fixture: ComponentFixture<SidebarComponent>;
  let component: SidebarComponent;
  let auth: AuthService;
  let controller: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [SidebarComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
    fixture = TestBed.createComponent(SidebarComponent);
    component = fixture.componentInstance;
  });

  afterEach(() => {
    controller?.verify();
    localStorage.clear();
  });

  it('shows the full workspace for a super admin', () => {
    auth.user.set(userWithRole('super_admin'));
    fixture.detectChanges();
    const labels = component.visibleNavItems().map(i => i.label);
    expect(labels).toContain('Home');
    expect(labels).toContain('Assets');
    expect(labels).toContain('Inventory');
    expect(labels).toContain('Organization');
    const org = component.visibleNavItems().find(i => i.label === 'Organization');
    expect(org?.children?.map(c => c.label)).toEqual(['People', 'Locations']);
  });

  it('hides permission-gated nav for an unauthenticated user', () => {
    fixture.detectChanges();
    const labels = component.visibleNavItems().map(i => i.label);
    expect(labels).toContain('Home');
    expect(labels).not.toContain('Assets');
    expect(labels).not.toContain('Inventory');
    expect(labels).not.toContain('Organization');
  });

  it('drops the Organization group when no child is visible', () => {
    auth.user.set(userWithRole('staff'));
    fixture.detectChanges();
    const labels = component.visibleNavItems().map(i => i.label);
    expect(labels).toContain('Assets');
    expect(labels).toContain('Inventory');
    expect(labels).not.toContain('Organization');
  });
});