import { Injectable, inject, computed } from '@angular/core';

import { AuthService } from './auth.service';

/**
 * Role → permission map, mirroring backend `RolePermissionSeeder` exactly.
 * Slugs come from `PermissionSeeder`. Never invent entries here: the backend
 * remains the authority; this map only drives UX visibility.
 */
const ROLE_PERMISSIONS: Record<string, readonly string[]> = {
  super_admin: [
    'view_dashboard',
    'view_users',
    'manage_users',
    'manage_roles',
    'view_departments',
    'manage_departments',
    'view_locations',
    'manage_locations',
    'view_assets',
    'manage_assets',
    'view_asset_categories',
    'manage_asset_categories',
    'assign_assets',
    'approve_asset_assignments',
    'view_inventory',
    'manage_inventory',
    'manage_stock',
    'view_tickets',
    'manage_tickets',
    'assign_tickets',
    'view_maintenance',
    'manage_maintenance',
    'view_reports',
    'view_audit_logs',
    'view_asset_assignments',
    'manage_asset_assignments',
  ],
  admin: [
    'view_dashboard',
    'view_users',
    'manage_users',
    'manage_roles',
    'view_departments',
    'manage_departments',
    'view_locations',
    'manage_locations',
    'view_assets',
    'manage_assets',
    'view_asset_categories',
    'manage_asset_categories',
    'assign_assets',
    'approve_asset_assignments',
    'view_inventory',
    'manage_inventory',
    'manage_stock',
    'view_tickets',
    'manage_tickets',
    'assign_tickets',
    'view_maintenance',
    'manage_maintenance',
    'view_reports',
    'view_audit_logs',
    'view_asset_assignments',
    'manage_asset_assignments',
  ],
  manager: [
    'view_dashboard',
    'view_assets',
    'assign_assets',
    'view_inventory',
    'view_tickets',
    'assign_tickets',
    'view_maintenance',
    'view_reports',
    'view_asset_assignments',
  ],
  staff: [
    'view_dashboard',
    'view_assets',
    'view_inventory',
    'view_tickets',
    'view_maintenance',
  ],
  technician: [
    'view_assets',
    'view_tickets',
    'assign_tickets',
    'view_maintenance',
    'manage_maintenance',
    'view_asset_assignments',
  ],
  warehouse_staff: [
    'view_dashboard',
    'view_assets',
    'view_inventory',
    'manage_inventory',
    'manage_stock',
    'view_asset_assignments',
  ],
};

/**
 * Pure role → permission check for a role slug, mirroring the same map used
 * by AuthorizationService. Exported so lookups (assignees, technicians) can
 * filter users by capability against the single source of truth.
 */
export function roleHasPermission(roleSlug: string | null, permission: string): boolean {
  if (roleSlug === 'super_admin') {
    return true;
  }
  return roleSlug !== null && (ROLE_PERMISSIONS[roleSlug] ?? []).includes(permission);
}

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  admin: 'Admin',
  manager: 'Manager',
  staff: 'Staff',
  technician: 'Technician',
  warehouse_staff: 'Warehouse Staff',
};

/**
 * Centralized frontend authorization. Reads the authenticated user from
 * AuthService (no duplicated user state) and answers role/permission
 * questions used by route guards, navigation, and action visibility.
 *
 * Frontend authorization is UX protection only — the backend permission
 * middleware remains the security boundary.
 */
@Injectable({ providedIn: 'root' })
export class AuthorizationService {
  private readonly auth = inject(AuthService);

  readonly role = computed(() => this.auth.user()?.role ?? null);
  readonly roleSlug = computed(() => this.role()?.slug ?? null);

  hasRole(role: string): boolean {
    return this.roleSlug() === role;
  }

  hasAnyRole(roles: readonly string[]): boolean {
    const slug = this.roleSlug();
    return slug !== null && roles.includes(slug);
  }

  isSuperAdmin(): boolean {
    return this.roleSlug() === 'super_admin';
  }

  hasPermission(permission: string): boolean {
    return roleHasPermission(this.roleSlug(), permission);
  }

  hasAnyPermission(permissions: readonly string[]): boolean {
    return permissions.some(permission => this.hasPermission(permission));
  }

  can(permission: string): boolean {
    return this.hasPermission(permission);
  }

  roleLabel(): string {
    const role = this.role();
    if (role?.name) {
      return role.name;
    }
    const slug = this.roleSlug();
    if (slug) {
      return ROLE_LABELS[slug] ?? slug;
    }
    return '—';
  }
}
