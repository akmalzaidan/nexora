import { Routes } from '@angular/router';
import { ShellComponent } from './layout/shell/shell.component';
import { authGuard } from './core/guards/auth.guard';
import { guestGuard } from './core/guards/guest.guard';
import { permissionGuard } from './core/guards/permission.guard';

export const appRoutes: Routes = [
  {
    path: '',
    component: ShellComponent,
    canActivate: [authGuard],
    children: [
      { path: 'home', loadComponent: () => import('./pages/home/home.page').then(m => m.HomePage) },
      {
        path: 'assets',
        loadComponent: () => import('./pages/assets/assets.page').then(m => m.AssetsPage),
        canActivate: [permissionGuard],
        data: { permission: 'view_assets' },
      },
      {
        path: 'assets/:id',
        loadComponent: () => import('./pages/assets/assets.page').then(m => m.AssetsPage),
        canActivate: [permissionGuard],
        data: { permission: 'view_assets' },
      },
      {
        path: 'inventory',
        loadComponent: () => import('./pages/inventory/inventory.page').then(m => m.InventoryPage),
        canActivate: [permissionGuard],
        data: { permission: 'view_inventory' },
      },
      {
        path: 'requests',
        loadComponent: () => import('./pages/requests/requests.page').then(m => m.RequestsPage),
        canActivate: [permissionGuard],
        data: { permission: 'view_tickets' },
      },
      {
        path: 'maintenance',
        loadComponent: () => import('./pages/maintenance/maintenance.page').then(m => m.MaintenancePage),
        canActivate: [permissionGuard],
        data: { permission: 'view_maintenance' },
      },
      {
        path: 'people',
        loadComponent: () => import('./pages/people/people.page').then(m => m.PeoplePage),
        canActivate: [permissionGuard],
        data: { permission: 'view_users' },
      },
      {
        path: 'locations',
        loadComponent: () => import('./pages/locations/locations.page').then(m => m.LocationsPage),
        canActivate: [permissionGuard],
        data: { permission: 'view_locations' },
      },
      {
        path: 'reports',
        loadComponent: () => import('./pages/reports/reports.page').then(m => m.ReportsPage),
        canActivate: [permissionGuard],
        data: { permission: 'view_reports' },
      },
      { path: 'settings', loadComponent: () => import('./pages/settings/settings.page').then(m => m.SettingsPage) },
      { path: 'profile', loadComponent: () => import('./pages/profile/profile.page').then(m => m.ProfilePage) },
      {
        path: 'notifications',
        loadComponent: () => import('./pages/notifications/notifications.page').then(m => m.NotificationsPage),
      },
      {
        path: 'unauthorized',
        loadComponent: () => import('./shared/components/unauthorized/unauthorized.component').then(m => m.UnauthorizedComponent),
      },
      { path: '', redirectTo: 'home', pathMatch: 'full' },
    ],
  },
  {
    path: 'login',
    loadComponent: () => import('./pages/login/login.page').then(m => m.LoginPage),
    canActivate: [guestGuard],
  },
  {
    path: 'register',
    loadComponent: () => import('./pages/register/register.page').then(m => m.RegisterPage),
    canActivate: [guestGuard],
  },
  { path: '**', redirectTo: 'home' },
];