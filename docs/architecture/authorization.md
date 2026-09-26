# Authorization & Role-Based UX (NEXORA)

## Overview
Phase 12 adds the client-side half of NEXORA's RBAC model. The backend remains
the security boundary (Laravel `permission:`/`role:` middleware, centralized on
`User::hasPermission`). The frontend uses the same role/permission map purely to
shape the UX: which routes a user may open, which nav entries are shown, and
which actions are visible.

This document is a decision record for how the frontend treats roles and
permissions.

## Backend contract (read-only)
- `GET /auth/me` returns `user.role { id, name, slug }` and `user.department`
  — **no permissions array**. The frontend derives permissions from the role
  slug, so the `role` object must always be returned by the backend.
- The authoritative role → permission map is
  `backend/database/seeders/RolePermissionSeeder.php`; slugs come from
  `backend/database/seeders/PermissionSeeder.php` (`26` slugs).
- `super_admin` bypasses permission checks on the backend
  (`User::hasPermission`). The frontend mirrors this exactly.

### Role → permission map (mirror of RolePermissionSeeder)
| Role             | Permissions |
| ---------------- | ----------- |
| `super_admin`    | all 26 |
| `admin`          | all 26 |
| `manager`        | view_dashboard, view_assets, assign_assets, view_inventory, view_tickets, assign_tickets, view_maintenance, view_reports, view_asset_assignments |
| `staff`          | view_dashboard, view_assets, view_inventory, view_tickets, view_maintenance |
| `technician`     | view_assets, view_tickets, assign_tickets, view_maintenance, manage_maintenance, view_asset_assignments |
| `warehouse_staff`| view_dashboard, view_assets, view_inventory, manage_inventory, manage_stock, view_asset_assignments |

> Never invent a permission here: the frontend map exists only so UX matches the
> backend; the backend remains authoritative.

## Frontend abstraction

### `AuthorizationService` — `core/services/authorization.service.ts`
A root, signal-backed service. Single source of permission questions; reads the
authenticated user from `AuthService.user()` (no duplicated user state).

API:
- `role()` / `roleSlug()` — current role or `null`.
- `hasRole(slug)` / `hasAnyRole(slugs)` / `isSuperAdmin()`.
- `hasPermission(slug)` / `hasAnyPermission(slugs)` / `can(slug)` — all
  delegate to a single decision that returns `true` for `super_admin`.
- `roleLabel()` — human-readable role name (prefers backend-provided
  `role.name`, falls back to the local label map, then `—`).

### `permissionGuard` — `core/guards/permission.guard.ts`
Route-level guard for authenticated-but-possibly-not-authorized navigation.

- First delegates the authentication decision to the existing `authGuard`
  (unauthenticated visitors get exactly the Phase 11 behaviour, including
  return-URL handling).
- Then reads `route.data.permission`. If the user lacks it, the guard returns a
  redirect `UrlTree` to `/unauthorized`. Otherwise `true`.

Used per-route (never on the shell) so `authGuard` remains the single
authentication gate and `permissionGuard` adds only the permission layer.

### Route wiring — `app.routes.ts`
The shell keeps `canActivate: [authGuard]`. Each protected child adds
`canActivate: [permissionGuard]` + `data: { permission: 'view_*' }`:

| Route            | Permission          |
| ---------------- | ------------------- |
| `/assets`, `/assets/:id` | `view_assets` |
| `/inventory`     | `view_inventory`    |
| `/requests`      | `view_tickets`      |
| `/maintenance`   | `view_maintenance`  |
| `/people`        | `view_users`        |
| `/locations`     | `view_locations`    |
| `/home`, `/settings`, `/profile` | (none — shell guard only) |
| `/unauthorized`  | (none — reachable by any authenticated user) |

**Convention:** `view_*` gates *route access*; `manage_*` gates *mutation
actions* only (e.g. `*appNxHasPermission="'manage_users'"` on a create/edit
button). This mirrors the backend route middleware split.

### `NxHasPermissionDirective` — `shared/components/nx-has-permission/`
Structural directive for action-level visibility:

```html
<button *appNxHasPermission="'manage_assets'">Create asset</button>
```

Renders its host template only while the authenticated user holds the
permission (or when no permission is required). Backed by `AuthorizationService`
and reactive to session changes.

### Unauthorized state — `shared/components/unauthorized/`
`ACCESS RESTRICTED` + "You don't have permission to access this operation." +
a "Return to Command" secondary button that navigates back to `/home`.
Quiet and operational: existing design tokens only, no gradients, no heavy
animation, `role="alert"` for screen readers.

### Nav/UX permission filtering
- Sidebar (`layout/sidebar`) — `NavItem.permission?`; a computed
  `visibleNavItems` filters items and drops a group whose children are all
  hidden.
- Mobile nav (`layout/mobile-nav`) — same idea via `visibleItems`.
- Command palette (`core/services/command-palette.service.ts`) — each command
  carries an optional `permission`; `allowedCommands` filters nav + operation
  commands by it. Search still filters within the allowed set.
- Home quick actions (`pages/home/home.page.ts`) — each quick action has an
  optional `permission`; `visibleQuickActions` shows only those the user can
  run (route actions use `view_*`, mutation actions use `manage_*` /
  `assign_*`).

## Security stance
- Frontend authorization is **UX only**. Every real decision still gets
  enforced server-side; nothing sensitive is hidden by "not rendering it".
- No permission arrays are fetched, stored, or invented — the role slug is the
  only client-side input, and the local map mirrors the seeder verbatim.
- `super_admin` bypass lives in exactly one decision point
  (`authorization.service.ts`), mirroring `User::hasPermission`.

## Trade-offs
- The local map is duplicated knowledge; if the backend seeder changes, the
  frontend map must be updated in the same change. Kept as one file with a
  comment pointing at the seeder to make drift obvious.
- Unauthorized routes land on `/unauthorized` inside the authenticated shell
  (the sidebar remains available) rather than a hard logout or a 403 page with
  exit links. Simpler, and consistent with a single-shell workspace.