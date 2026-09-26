import { Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';

import { AuthService, NexoraUser } from '../../../core/services/auth.service';
import { NxHasPermissionDirective } from './nx-has-permission.directive';

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

@Component({
  standalone: true,
  imports: [NxHasPermissionDirective],
  template: `
    <ng-template appNxHasPermission>
      <div class="card-free">free</div>
    </ng-template>
    <ng-template appNxHasPermission="view_users">
      <div class="card-users-view">view users</div>
    </ng-template>
    <ng-template appNxHasPermission="manage_users">
      <div class="card-users-manage">manage users</div>
    </ng-template>
    <ng-template appNxHasPermission="view_inventory">
      <div class="card-inventory-view">view inventory</div>
    </ng-template>
    <ng-template appNxHasPermission="manage_inventory">
      <div class="card-inventory-manage">manage inventory</div>
    </ng-template>
  `,
})
class HostComponent {}

describe('NxHasPermissionDirective', () => {
  let fixture: ComponentFixture<HostComponent>;
  let auth: AuthService;
  let controller: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [HostComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
      ],
    }).compileComponents();
    controller = TestBed.inject(HttpTestingController);
    auth = TestBed.inject(AuthService);
    fixture = TestBed.createComponent(HostComponent);
  });

  afterEach(() => {
    controller?.verify();
    localStorage.clear();
  });

  it('renders every card for an admin holding view_users and manage_users', () => {
    auth.user.set(userWithRole('admin'));
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.card-free')).toBeTruthy();
    expect(el.querySelector('.card-users-view')).toBeTruthy();
    expect(el.querySelector('.card-users-manage')).toBeTruthy();
    expect(el.querySelector('.card-inventory-view')).toBeTruthy();
    expect(el.querySelector('.card-inventory-manage')).toBeTruthy();
  });

  it('renders view but hides manage for a staff user holding view_inventory only', () => {
    auth.user.set(userWithRole('staff'));
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.card-free')).toBeTruthy();
    expect(el.querySelector('.card-inventory-view')).toBeTruthy();
    expect(el.querySelector('.card-inventory-manage')).toBeNull();
  });

  it('renders only the unrestricted card for a user holding neither permission', () => {
    auth.user.set(userWithRole('technician'));
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.card-free')).toBeTruthy();
    expect(el.querySelector('.card-users-view')).toBeNull();
    expect(el.querySelector('.card-users-manage')).toBeNull();
    expect(el.querySelector('.card-inventory-view')).toBeNull();
  });

  it('renders nothing for an unauthenticated user beyond the unrestricted card', () => {
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.card-free')).toBeTruthy();
    expect(el.querySelector('.card-users-view')).toBeNull();
    expect(el.querySelector('.card-users-manage')).toBeNull();
    expect(el.querySelector('.card-inventory-view')).toBeNull();
  });
});