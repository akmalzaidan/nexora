# Architecture

NEXORA is a **modular monolith** application. The goal is one deployable unit
with clear internal module boundaries so that modules could later be extracted
into services if ever required. During this phase it remains a single Laravel
backend.

## Design Overview

```text
Angular + Ionic (frontend)
        │  HTTPS / JSON / Sanctum token
        ▼
Laravel REST API (single codebase)
        │
        ├── Modules (Authentication, Users, Organization, Assets, Inventory,
        │            Helpdesk, Maintenance, Notifications, Audit, Reports)
        │
        └── PostgreSQL
```

Each business domain lives inside a module. Modules are not packages yet; they
are organized as directories under `backend/app/Modules/*` once the first
business phase begins.

## Request Flow

Every request follows the same path:

```text
HTTP Request
     ↓
Route (routes/api.php, prefixed /api/v1)
     ↓
Controller (App\Http\Controllers\Api — thin, no business logic)
     ↓
Request (App\Http\Requests — FormRequest validation)
     ↓
Service / Action (App\Services — domain logic)
     ↓
Model (App\Models)
     ↓
PostgreSQL
     ↑
Resource (App\Http\Resources — JSON serialization) on the way back out
```

Responses pass through `App\Support\ApiResponse` (consistent JSON envelope) and
exceptions through `App\Support\Exceptions\ApiExceptionRenderer`
(bootstrap/app.php → `withExceptions`).

## Decision Record 001 — Modular Monolith over Microservices

- **Status:** Accepted
- **Reason:** A microservice split adds operational cost and complexity that
  does not fit a portfolio/demo project. A modular monolith keeps the stack
  simple, keeps every phase runnable, and still preserves clean boundaries so a
  future extract-and-split is mechanically possible.
- **Trade-off:** No independent scaling or per-module deployments. Acceptable
  for NEXORA's scope.

## Decision Record 002 — Zero-Cost Policy

- **Status:** Accepted
- **Reason:** NEXORA is a demo/portfolio project. Every dependency is chosen
  from open-source or permanently-free options (PostgreSQL, Laravel, Angular,
  Ionic, Capacitor, Docker, OpenAPI/Swagger, OpenStreetMap if ever needed).
- **Trade-off:** No managed production services; deployments must be
  self-hosted on free infrastructure.

## Decision Record 003 — Append-Only History and FK Cascade Policy

- **Status:** Accepted
- **Reason:** NEXORA's domains are record-oriented: audits, asset history, stock
  movements, and ticket history must never be lost. History tables therefore
  carry no `updated_at` and their foreign keys default to `NO ACTION`/`RESTRICT`
  so a parent row cannot silently wipe its history.
- **Trade-off:** Deleting a parent with history requires an explicit,
  domain-aware soft-delete or archival step in a later phase (only
  `assets`/`items` are soft-deleted today).

## Decision Record 004 — Sanctum Personal Access Tokens + Centralized RBAC

- **Status:** Accepted
- **Reason:** A token-based API authenticated with **Laravel Sanctum personal
  access tokens** (no JWT package) keeps the stack zero-cost and simple.
  Authorization is centralized on the `User` model (`hasPermission`,
  `hasRole`, `isSuperAdmin`) so gate checks are uniform, and enforcement at
  route level goes through two middleware: `role:slug1,slug2` and
  `permission:slug1,slug2`.
- **Security stance:**
  - Login errors never leak *why* auth failed — unknown email, wrong password,
    and inactive accounts all return `401 Invalid credentials`.
  - `super_admin` bypasses **permission** checks but never **authentication**,
    and the bypass lives in one place (`User::hasPermission`).
  - The role given at registration is always `staff`; client-supplied role
    values are ignored.
  - Logout revokes only the token in use; the auth guard drops its cached user
    (`Auth::forgetGuards`) so revocation holds even when the application
    instance lives across requests (tests, queue workers, Octane).
  - Rate limiting: login `5/min` per email+IP, register `3/min` per IP.
- **Trade-off:** Personal access tokens are long-lived with no built-in
  expiry. Acceptable for NEXORA; a token-expiry policy can be layered on the
  same mechanism later if needed.

## Decision Record 005 — Organization Module First: Simple SaaS CRUD with Conflict Guards

- **Status:** Accepted
- **Reason:** Building the first business module is a test of the architectural
  conventions. Departments and locations use the established
  Controller → FormRequest → Service → Resource → `ApiResponse` path with no
  new abstractions (no generic `CrudService`), a whitelisted sort column
  (`name | code | created_at`), and case-insensitive `whereLike` search that
  maps to `ILIKE` on PostgreSQL.
- **Authorization:** two granular permissions per resource (view/manage),
  enforced by the Phase 4 `permission:` middleware. Appointing a department
  `manager_id` deliberately does **not** change the user's role — an
  organizational position and a role are distinct concepts.
- **Delete policy:** delete never cascades operational data. An in-use
  department or location returns `409 Conflict` with a clear message instead of
  a raw foreign-key failure.
- **Trade-off:** only super_admin/admin hold organization permissions today;
  other roles cannot consume these endpoints yet. Permissions can be
  re-assigned later via role-management without code changes.

## Decision Record 006 — User Management: Thin Admin CRUD with Role-Escalation Guard and Self-Protection

- **Status:** Accepted
- **Reason:** Phase 06 delivers the administrative user-management API that
  super_admins and admins need to onboard, offboard, and govern platform users.
  It reuses the established Controller → FormRequest → Service → Resource →
  `ApiResponse` path and the Phase 4 `permission:` middleware; no new
  abstractions are introduced.
- **Authorization model:**
  - `view_users` guards `GET /users` and `GET /users/{id}`.
  - `manage_users` guards `POST /users`, `PUT /users/{id}`, and
    `DELETE /users/{id}`.
  - Both permissions are seeded to `super_admin` and `admin`; other roles get
    neither. Route middleware is `permission:...`, never a role-name check.
  - Role assignment is guarded: a non-super-admin cannot assign the
    `super_admin` role (returns `403 Only a super admin can assign the
    super_admin role`). This is enforced by role *slug* lookup, not a numeric
    ID, so it survives seed-order changes.
- **Self-protection:**
  - A user cannot delete their own account (`409 You cannot delete your own
    account`).
  - A user cannot change their own role or deactivate their own account
    (`403`).
  - The last active super admin cannot be deactivated, demoted, or deleted —
    the system must always retain a privileged account.
- **Delete policy:** hard delete is used where safe. Blocking references
  (`ticket.requester_id`, `ticket_comment.user_id`, `stock_movement.performed_by`,
  `maintenance_request.requested_by`, `asset_assignment.user_id /
  requested_by`) return `409 User cannot be deleted because it is still in use`.
  Non-blocking references that the schema nulls out (department `manager_id`)
  do **not** block deletion — the department survives with a cleared manager.
  No speculative business logic for modules not yet implemented is added.
- **Password handling:** create requires `password` + `password_confirmation`
  (min 8, confirmed). Update accepts an optional `password` + `password_confirmation`;
  it is only changed when present and is always hashed. Passwords are never
  returned, logged, or exposed in API responses or resources.
- **Active/inactive:** `is_active = false` blocks login (enforced by the auth
  service). Admins toggle it via `PUT /users/{id}`; the effect is verified by
  attempting login after deactivation.
- **Response shape:** `UserManagementResource` returns a flat user object with
  nested `role` and `department` as flat objects (when loaded) — never
  recursive. `password`, `remember_token`, and Sanctum tokens are never exposed.
- **Trade-off:** admins can assign other admins and managers freely (no
  segregation between "create admin" and "create staff"). If a stricter policy
  is needed later it can be layered on the same service without a route change.

## Decision Record 007 — Asset Management Core: Thin CRUD with Category/Location Relations and Delete-Conflict Guards

- **Status:** Accepted
- **Reason:** Phase 07 delivers the asset category and asset CRUD APIs that
  inventory and maintenance depend on. It follows the established
  Controller → FormRequest → Service → Resource → `ApiResponse` path with no
  new abstractions. Asset categories and assets are linked to existing
  `Location` and `AssetCategory` models; delete conflicts are guarded by
  explicit existence checks (no cascade, no `withoutForeignKeyConstraints()`).
- **Authorization model:**
  - `view_asset_categories` and `manage_asset_categories` are seeded to
    `super_admin` and `admin` only.
  - `view_assets` is seeded to all roles (super_admin, admin, manager, staff,
    technician, warehouse_staff) so every role can browse assets.
  - `manage_assets` is seeded to `super_admin` and `admin` only.
  - Route middleware is `permission:...`, never a role-name check.
- **Schema fidelity:** Phase 07 follows the existing Phase 03 migrations
  exactly — no new columns, no new tables. `asset_categories` has no
  `is_active` column; `assets` uses `status` (default `DRAFT`) instead of a
  separate `is_active` flag. Status values are constrained to the documented
  set; condition values are constrained to `GOOD|FAIR|POOR|DAMAGED|FAILED`.
- **Delete policy:**
  - Asset category: hard-delete succeeds only when zero assets reference it;
    otherwise `409 Asset category cannot be deleted because it is still in use`.
  - Asset: soft-delete succeeds only when zero `asset_assignments` and zero
    `asset_histories` reference it; otherwise `409 Asset cannot be deleted
    because it is still in use` / `...has historical records`.
  - No cascade deletion of operational data; history tables are respected.
- **No automated history/audit in this phase:** `asset_histories` exists and is
  honored as a blocking reference, but no observer or automatic history
  recording is added. Full history behavior is deferred.
- **No assignment workflow in this phase:** `asset_assignments` exists and is
  honored as a blocking reference for deletes, but no assignment create/read/
  update endpoints are exposed. Asset Assignment becomes Phase 08.
- **No QR in this phase:** Asset identifier (`asset_code`) is unique and
  validated, but no QR generation or scanning is implemented. QR becomes a
  later phase.
- **Trade-off:** `current_user_id` on `assets` is preserved and validated as a
  reference, but no custodian/assignment semantics are exposed. This keeps the
  phase focused on CRUD while leaving the field available for the assignment
  workflow.

## Decision Record 008 — Asset Assignment Workflow: Status-Based Assignment Guard, `current_user_id` Synchronization, and Explicit Return Transition

- **Status:** Accepted
- **Reason:** Phase 08 delivers the asset assignment lifecycle API that Phase 07's
  asset CRUD depends on. It introduces `asset_assignments` as the operational
  table for tracking who holds which asset, with `assets.current_user_id`
  synchronized to stay a single source of truth for the current holder.
  Assignment and return are explicit workflow transitions, not generic CRUD
  updates, because the domain has a clear lifecycle (available → active →
  returned → available).
- **Authorization model:**
  - `view_asset_assignments` is seeded to all 6 roles (super_admin, admin,
    manager, staff, technician, warehouse_staff) — every role can browse
    assignments.
  - `manage_asset_assignments` is seeded to `super_admin`, `admin`, and
    `manager` only. Staff, technician, and warehouse_staff can view but not
    create or return assignments.
  - Route middleware is `permission:...`, never a role-name check.
- **Assignment guard:**
  - An asset can only be assigned if its status is `DRAFT`, `ACTIVE`, or
    `INACTIVE`. `MAINTENANCE`, `RETIRED`, `LOST`, and `DISPOSED` are
    non-assignable.
  - A single asset must not have multiple active assignments simultaneously.
    The check is performed inside a database transaction with `lockForUpdate()`
    on the asset row to prevent concurrent assignment races.
  - Returns `409 Conflict` if the asset is already actively assigned.
- **`current_user_id` synchronization:**
  - On assignment: `assets.current_user_id` = assigned user's ID.
  - On return: `assets.current_user_id` = null.
  - This keeps `assets.current_user_id` as the authoritative "who currently
    holds this asset" field, with `asset_assignments` as the historical record.
  - No second competing source of truth is introduced.
- **Transaction strategy:**
  - Assignment and return operations run inside `DB::transaction()`.
  - On assignment: validate state → create assignment → update
    `current_user_id` → create history → commit.
  - On return: validate assignment is active → close assignment →
    clear `current_user_id` → create history → commit.
  - Any failure rolls back the entire transaction; no partial state.
- **Asset history behavior:**
  - `ASSIGNED` history record on assignment creation.
  - `RETURNED` history record on return.
  - History records are created in the same transaction as the lifecycle change.
  - No observer or automatic audit system; explicit service-driven creation.
- **Return as explicit workflow endpoint:**
  - `POST /asset-assignments/{id}/return` is a dedicated transition endpoint,
    not a generic `PUT` update. This prevents invalid state changes (e.g.
    changing status to an arbitrary value).
  - Already-returned assignments return `409 Conflict`.
- **No approval workflow in this phase:** `approved_by` and `requested_by`
  columns exist on `asset_assignments` but no approval endpoints are exposed.
  Assignments are created directly by `manage_asset_assignments`-enabled users.
- **No QR in this phase:** Asset identifier (`asset_code`) remains unique and
  validated, but no QR generation or scanning is implemented.
- **Trade-off:** Assignment `status` uses `ACTIVE`/`RETURNED` string values
  rather than a separate `is_active` boolean, matching the existing migration
  default of `PENDING` and the pattern established by other lifecycle tables.

## Decision Record 009 — QR Asset Identification: Reusing `asset_code` as the Stable, Immutable QR Identity with No New Schema or Packages

- **Status:** Accepted
- **Reason:** Phase 09 delivers QR-based asset identification so a future mobile
  scanner can resolve a scanned code to an asset. The existing `asset_code`
  column is already unique, stable, non-sensitive, and independent from
  assignment/location/status. Reusing it avoids a new column, a new package,
  and a new identity mechanism. The backend only stores and exposes the payload
  string (`NEXORA:ASSET:<asset_code>`); no QR image generation package is
  needed because the frontend can render the QR from the payload in a later
  phase.
- **QR identity strategy:**
  - Identifier = existing `asset_code` (e.g. `AST-000123`).
  - Payload = `NEXORA:ASSET:<asset_code>`.
  - No new database column. No migration. The unique constraint on
    `asset_code` provides uniqueness.
  - The identity is immutable for the asset lifetime. It does not change on
    assignment, return, relocation, or status change.
- **Why `asset_code` and not a new column:**
  - It is already unique (unique constraint in the migration).
  - It is already stable (assigned once at creation, never mutated by normal
    asset updates).
  - It is non-sensitive (no credentials, tokens, or internal IDs exposed).
  - It is independent from `current_user_id` — assignment does not affect it.
  - Introducing a separate `qr_identifier` would create a second identity
    mechanism that must be kept in sync with `asset_code`.
- **Storage strategy:** No QR images are stored. The backend exposes the
  payload string. The frontend renders the QR code from the payload when the
  scanner feature is implemented.
- **No QR generation package:** The Phase 09 service does not generate PNG/SVG.
  It only builds the payload string. No Composer dependency is added. This
  follows the "simpler implementation" guidance when the backend only needs to
  expose the payload.
- **Lookup behavior:**
  - `GET /assets/{asset}/qr` returns metadata (identifier + payload) for a
    known asset.
  - `GET /assets/qr/{identifier}` resolves a scanned payload back to the
    asset. Accepts both the full payload (`NEXORA:ASSET:AST-000123`) and the
    bare asset code (`AST-000123`).
  - Invalid or nonexistent identifiers return `404`, never `500`.
- **Security model:**
  - Authenticated only (`auth:sanctum`).
  - `view_assets` permission (reused, no new permission).
  - No public endpoint — internal enterprise asset identification, not public
    disclosure.
  - `AssetResource` excludes `password`, `remember_token`, and tokens.
- **Deleted asset behavior:** Assets use `SoftDeletes`. QR lookup uses
  `withoutTrashed()`, so soft-deleted assets do not resolve.
- **Why no new permission:** QR lookup is read-only asset identification.
  `view_assets` already grants this to all 6 roles. Introducing `view_qr` or
  `manage_qr` would add complexity without a clear RBAC benefit.
- **Trade-off:** The payload format is plaintext and human-readable. This is
  intentional — the QR is an internal identifier, not a secret. The payload
  does not expose assignment, location, or status. If a more compact or
  encoded payload is needed later, the service can be updated without a route
  change.

## Decision Record 014 — Inventory Stock Journal: Derived Balances, Serialized Movements, and an Immutable Ledger

- **Status:** Accepted
- **Reason:** Phase 14A delivers the Inventory Management REST API (item
  categories, items, warehouses, stock movements) on top of the Phase 03
  schema. Stock is treated as a financial ledger, not a mutable counter:
  current balance is always derived from the append-only `stock_movements`
  journal, so it can never drift from the record of what actually happened.
- **Movement type contract:**
  - Exactly two official types: `STOCK_IN` (adds) and `STOCK_OUT` (subtracts),
    defined as constants on `App\Models\StockMovement`.
  - `quantity` is always a **positive** integer; direction comes from `type`
    (the Phase 03 PostgreSQL check `quantity > 0` plus an `integer|min:1` rule
    enforce this on both databases).
  - Transfers between warehouses are expressed as a STOCK_OUT + STOCK_IN pair;
    a dedicated transfer workflow is deferred.
- **Derived balance, no denormalized column:**
  - `balance(item, warehouse) = SUM(STOCK_IN) − SUM(STOCK_OUT)`.
  - Computed with portable SQL (`COALESCE(SUM(CASE WHEN type = ? THEN quantity
    ELSE -quantity END), 0)`), which runs unchanged on SQLite (tests) and
    PostgreSQL (production).
  - Item list responses show `stock.total` via `withSum` aggregates (two extra
    correlated subqueries per page, **not** N+1). Item detail adds a
    per-warehouse breakdown in one grouped aggregate.
- **Concurrency:**
  - Every movement creation runs inside `DB::transaction()` with
    `lockForUpdate()` on the item row, so concurrent STOCK_IN/STOCK_OUT for
    the same item serialize — the balance read and the journal insert are
    atomic.
  - A STOCK_OUT that would drive balance below zero aborts with
    `422 Insufficient stock for this movement.`; the transaction rolls back
    and nothing is written.
- **Immutable journal:**
  - There is no `PUT`/`DELETE` for stock movements (they return `405`).
  - Corrections are compensating movements, never edits — matches the Phase 03
    "append-only history" principle in DR-003.
  - `performed_by` is always resolved server-side from the authenticated user;
    client-supplied actor values are ignored.
  - Soft-deleted items cannot receive new movements.
- **Authorization:** reuses the existing `view_inventory` /
  `manage_inventory` / `manage_stock` permissions (seeded in Phase 04). No new
  permissions. `technician` has neither inventory permission; `manager` and
  `staff` can view; `warehouse_staff` manages inventory and stock.
- **Schema fidelity:** no migrations were changed. `maintenance_parts` is
  honored as a blocking reference when deleting an item.
- **Trade-off:** balance queries scan the journal per item/warehouse. For
  NEXORA's scale this is trivial; a materialized balance or indexed projection
  can be layered on later without changing the contract.

## Decision Record 015 — Helpdesk Ticket Lifecycle: Explicit Transitions, Server-Side Numbers, and an Append-Only Timeline

- **Status:** Accepted
- **Reason:** Phase 15A delivers the Helpdesk REST API (ticket categories,
  tickets, comments, history) on the Phase 03 schema. Tickets are workflow
  records, not generic CRUD rows: the status lifecycle and assignment are
  explicit transitions that write an append-only timeline in the same
  transaction as the state change.
- **Lifecycle contract:**
  - `OPEN → IN_PROGRESS → RESOLVED → CLOSED`, with `CLOSED → OPEN` as the only
    way back (reopen). No skipping states; every other change returns
    `422 Invalid status transition from X to Y` and leaves state + history
    untouched.
  - `closed_at` is set when a ticket becomes `CLOSED` and cleared on reopen.
  - Priorities are `LOW | MEDIUM | HIGH | URGENT`; a ticket is created `OPEN`,
    `MEDIUM`, unassigned.
- **Identity and authority:**
  - `ticket_number` is always generated server-side (`TCK-` + random suffix,
    unique-constrained); clients can never supply it or the requester ID —
    requester is always the authenticated user, so a client cannot spoof
    ownership or shadow-raise tickets.
  - Assignment requires `assign_tickets`; the assignee must exist, be active,
    and hold `assign_tickets`. Assignment, reassignment, and unassignment all
    append `ASSIGNMENT_CHANGED` history.
- **Concurrency:** updates run inside `DB::transaction()` with
  `lockForUpdate()` on the ticket row (the same serialize pattern as
  `StockMovementService`), and the history row for a change is inserted in the
  same transaction — the timeline can never diverge from the ticket state.
- **History:** `ticket_histories` is append-only (no `updated_at`) per DR-003.
  Actions are `CREATED`, `UPDATED`, `STATUS_CHANGED`, `ASSIGNMENT_CHANGED`.
  Comments live in `ticket_comments` and are deliberately **not** duplicated as
  history rows. There are no update/delete history endpoints (`405`).
- **Comments and access control:**
  - Comment author = authenticated user, always. `is_internal` is honored only
    for agents; staff-supplied `is_internal` becomes public, so a requester can
    never hide a comment. Staff list only public comments.
  - Visibility: agents (`manage_tickets` or `assign_tickets`) see every ticket;
    requesters (`view_tickets` only, i.e. staff) see only their own. Cross-user
    access returns `403`.
- **No deletion of tickets:** tickets are operational records and are never
  hard-deleted (no `DELETE /tickets`; the route returns `405`). Deleting a
  ticket category backed by tickets returns `409` — historical data is not
  cascade-deleted.
- **Authorization change (permission seeding):** the Phase 04 seed gave
  manager/technician only `view_tickets` + `assign_tickets`, which would have
  let them handle tickets but never change status. Phase 15A adds
  `manage_tickets` to **manager** and **technician** (the roles that actually
  process/resolve tickets) so the workflow is usable end-to-end. It is the
  only role-permission change; no new permission slugs were invented.
- **Schema fidelity:** no migrations were changed; no new columns or
  permissions were added. `ticket_categories` stays without `is_active`;
  `tickets` has no `deleted_at`.
- **Trade-off:** a ticket's assignee is validated against permission at change
  time but silo/team scoping is deferred: any agent can work any ticket. That
  matches NEXORA's single-tenant demo scope and can be layered on later without
  a route change.

## Decision Record 016 — Maintenance Workflow: Explicit Transitions, Work-Order History, and Inventory as Source of Truth

- **Status:** Accepted
- **Reason:** Phase 16A delivers the Maintenance REST API (requests, records,
  parts) on the Phase 03 schema. Maintenance is a two-level workflow: a
  maintenance **request** is the backlog item, a maintenance **record** is the
  executed work order — with an append-only parts list. The schema defaults
  (`status` `REQUESTED`, nullable `approved_at`/`completed_at`) pin the
  lifecycle, and inventory stays the single source of truth for stock.
- **Lifecycle contract:**
  - `REQUESTED → APPROVED → IN_PROGRESS → COMPLETED`, with `CANCELLED` reachable
    from `REQUESTED` or `APPROVED` only. No skipping moves; every other change
    returns `422 Invalid maintenance status transition from X to Y` inside a
    transaction that also rolls back any concurrently-edited fields.
  - `approved_at` is stamped on `APPROVED`, `completed_at` on `COMPLETED`.
    Priorities are `LOW | MEDIUM | HIGH | URGENT`; a request is created
    `REQUESTED`, `MEDIUM`, unassigned.
  - Creating a record auto-transitions an `APPROVED` request to `IN_PROGRESS`
    in the same transaction (the record is how work starts). Records are only
    started on `APPROVED`/`IN_PROGRESS` requests; parts are only attachable
    while the request is `APPROVED`/`IN_PROGRESS`. Completed/cancelled work is
    immutable input-wise.
- **Authority and identity:**
  - Reused existing permissions — no new slugs: `view_maintenance` (read) and
    `manage_maintenance` (transition, assign, create/close records and parts).
    Technicians hold both; staff/manager hold `view_maintenance` only and are
    read-scoped to requests they raised (`requested_by`); agents see all rows.
  - The requester is always the authenticated user; `status`, `requested_at`,
    and `assigned_to` are server-controlled on create and ignored if spoofed.
  - Assignees/technicians must exist, be active, and hold `manage_maintenance`
    (missing → `422` validation; inactive/unqualified → `422`). Technician
    resolution falls back to request assignee, then the acting user.
- **Concurrency:** updates run inside `DB::transaction()` with
  `lockForUpdate()` on the request row — the status transition and any edited
  fields commit atomically or not at all (same serialize pattern as DR-014/015).
  Records lock their request to re-validate state under contention; FKs
  (`maintenance_request_id`, `asset_id`) are immutable after creation.
- **Eligibility and status boundaries:** an asset that is soft-deleted, lost,
  retired, or disposed cannot be scheduled (`422`). Maintenance deliberately
  does **not** mutate the asset's own status — asset status remains owned by
  the Asset domain (Phase 07/08) even though `MAINTENANCE` is valid vocabulary.
- **Inventory is the source of truth (non-consumption):**
  - Maintenance parts reference an item but never decrement stock. The schema
    has no warehouse/journal link on `maintenance_parts`, and no
    `MaintenanceStockService` exists. Stock changes are still recorded through
    the existing `POST /stock-movements` with `reference_type` `maintenance` —
    the ledger owns balances (DR-014).
  - Parts/records/requests have no delete endpoints (`405`) — records are the
    permanent history, so no separate audit table is written (matching the
    existing modules, which do not consume `audit_logs`).
- **Trade-off:** a record's technician is validated against
  `manage_maintenance` at write time but schedule/skill matching is deferred,
  and parts logging is material-cost traceability without automatic stock
  mutation — auto-consumption and machinery availability are Phase 16B+.

## Decision Record 017 — Notifications: Server-Side Inbox with Ownership-Enforced Reads and Change-Detection Idempotence

- **Status:** Accepted
- **Reason:** Phase 17A delivers the notification inbox API on the Phase 03
  `notifications` row (user_id, type, title, message, data, read_at). The API
  exists because workflow events — assignments, approvals, completions — must be
  surfaced to the people who did not perform them. Notifications are **not** a
  client feature: rows are written only by the domain services.
- **Delivery contract (server-side, no fake API):**
  - There is **no create endpoint** (`POST /notifications` → `405`). Recipients
    are computed from domain state inside the domain services; a request can
    never target an arbitrary `user_id`, and `user_id` in the query string is
    ignored.
  - Event types are limited to workflow-backed transitions: `ticket.assigned`,
    `ticket.status_changed`, `maintenance.assigned`, `maintenance.approved`,
    `maintenance.completed`, `asset.assigned`, `asset.returned`. There is no
    `inventory.stock_low` because no low-stock contract exists (DR-014 balances
    carry no threshold).
  - The acting user is never notified about their own action.
- **Transactional integrity and idempotence:**
  - Notifications are created **inside the existing `DB::transaction`** of each
    service mutation, so a failed/rolled-back update produces no notification
    and no misleading alert.
  - Duplicate prevention is **change-detection idempotence**: the domain
    transition guards already treat an unchanged status/assignee as a no-op, so
    re-processing an identical event (or a concurrent successful update) cannot
    create a second row. No schema change, no queue, no event bus was needed —
    the modular-monolith services call the notification service directly.
- **Ownership and read state:**
  - Every read path (list, show, mark-read) is scoped to `user_id = actor.id`.
    Another user's notification is indistinguishable from a missing one (**404**),
    so the API never leaks existence (same stance as DR-006 self-protection).
  - Read state is derived from the nullable `read_at` column: `is_read =
    read_at !== null`. `read_at` is only ever stamped server-side; mark-read and
    mark-all are idempotent.
- **Schema:** the existing `notifications` migration is authoritative and was not
  modified; a **new** migration only adds the `notifications_user_id_index` to
  support the per-user inbox queries on PostgreSQL.
- **No new permissions:** the inbox is gated by authentication only
  (`auth:sanctum`), not RBAC — consistent with per-user private data.
- **Trade-offs:** delivery is synchronous and in-process (no queue/redis), which
  is acceptable at this scale and keeps the ZERO-COST policy (DR-002); there is
  no retention/cleanup sweep yet (old rows persist — deferred), and the client
  UI consuming the inbox is a later phase.

## Decision Record 018 — Read-Only Reporting and Aggregate Queries

- **Status:** Accepted
- **Reason:** Phase 18A delivers five read-only report endpoints (overview,
  assets, inventory, tickets, maintenance) over the existing domain tables. A
  report is a **typed aggregate payload**, not a serialized model, so it gets no
  Eloquent resource; the domain service computes the metrics and the controller
  only wires request → service → envelope.
- **Read-only surface:** every route is `GET` behind `auth:sanctum` +
  `permission:view_reports`. There is no write verb on a report path (any other
  verb returns `405`), and nothing in a report mutates business state.
- **New permission:** `view_reports`, seeded in Phase 04 and mapped to
  `super_admin`, `admin`, `manager`. `staff`, `technician` and `warehouse_staff`
  are denied, which is what makes the unfiltered global aggregates safe — the
  roles that can read a report already have organization-wide visibility, so no
  report needs a department or warehouse scope of its own.
- **Current state vs period, never mixed:**
  - Detail reports separate the two explicitly: `current` sections are snapshots
    over the whole table and are **never** date filtered; the `period` object is
    `null` unless both bounds are supplied, and holds only period activity.
  - `overview` is a snapshot by definition and takes no period at all.
  - Omitting a period therefore never silently reports an empty or wrong window.
- **Period contract:** `from` and `to` are inclusive `Y-m-d` calendar days in
  `config('app.timezone')` (UTC), are all-or-nothing, must run forwards, and may
  span at most **366 inclusive days** (keeps the daily series bounded). Anything
  else is `422` with the standard `errors` envelope.
- **Date basis is documented per metric, not assumed:**
  - assets → `assets.created_at`, `asset_assignments.assigned_at` / `returned_at`
  - inventory → `stock_movements.created_at`; `current_stock` is the full journal
  - tickets → `tickets.created_at`
  - maintenance → `requested_at`, `approved_at`, `completed_at`
- **Stock is never recomputed in the report layer.** Every quantity comes from
  `StockMovementService` (DR-014), which owns the single balance rule, so a
  report can never drift from the inventory module. `current_stock` is
  undated; the period reports in/out activity on the journal.
- **Bounded vs complete output:** breakdowns over reference dimensions (status,
  priority, category, location, warehouse) are always complete. Only rankings
  that scale with row count are limited — `inventory.current_stock.by_item`,
  `maintenance.by_asset`, `maintenance.parts.top_items` — via `limit`
  (default 10, max 100), and the applied limit is echoed back.
- **Interpretive metrics are refused.** No health, risk, score, trend direction,
  or "needs attention" label is derived. Parts usage is a **trace** of what a work
  order recorded, never presented as inventory consumption (DR-016). An empty
  report is `200` with zeroes and empty arrays, never `404`.
- **Portability:** the only driver-specific SQL is the daily bucket expression
  (`column::date` on PostgreSQL, `date(column)` elsewhere), isolated in
  `ReportDayBucket`. Money is aggregated in SQL at decimal precision and only
  formatted to the 2-decimal string the API already uses elsewhere
  (`MaintenanceRecordResource`); `average_cost` is `null` when no record carries
  a cost. No schema change and no new package.
- **Deferred:** an `/reports/activity` timeline, because the four history tables
  have incompatible event vocabularies and merging them would invent semantics
  the domain does not define. Frontend report screens are Phase 18B. The timeline
  itself was reconsidered in DR-019: the merge is safe as a *presentation* of the
  newest events, provided each source keeps its own action vocabulary.

## Decision Record 019 — Command Center: Scoped Current-State Snapshot, Source-Native Activity, and Aggregate-Only Queues

- **Status:** Accepted
- **Reason:** Phase 19A adds one operational read surface, `GET /api/v1/command-center`,
  answering three separate questions in one call: what is true **now**, what is
  **waiting** for a person, and what **just happened**. It is deliberately a
  different surface from Reports (DR-018), not a thinner copy of it: reports are
  organization-wide and period-scoped for an analyst role, while the Command
  Center is a current-state view scoped to the caller for an operator role.
- **One endpoint, not three.** Snapshot, queues and activity are served by a
  single `GET`. Three round trips to build one dashboard is a client-side
  orchestration problem, and each surface re-derives the same visibility rules.
  `CommandCenterService` is the only façade; it composes three query classes
  (`CommandCenterSnapshotQuery`, `CommandCenterQueueQuery`,
  `CommandCenterActivityQuery`) plus a pure `CommandCenterScope` decision object.
- **Read-only surface:** the route is `GET` behind `auth:sanctum` +
  `permission:view_dashboard`; any other verb is `405`, and nothing in the path
  mutates state, creates a notification, or caches a result. A dashboard shows
  live state, so caching is deliberately absent.
- **No new permission, and a weak one at that.** `view_dashboard` already exists
  and maps to `super_admin`, `admin`, `manager`, `staff`, `warehouse_staff`. It
  is a *weak* permission — unlike `view_reports` it does not imply one shared
  data visibility, so the endpoint cannot be an unfiltered global aggregate. A
  `warehouse_staff` user has no `view_tickets` at all, and a `staff` user may
  read only their own tickets and maintenance requests.
- **Authorization is re-used, never re-invented.** A section is gated by that
  domain's existing view permission and is **omitted entirely** when the caller
  may not read it, so an absent key can only ever mean "not visible to you",
  never "no data". Row scope reuses the rules the list endpoints already apply:
  organization-wide tickets for `manage_tickets`/`assign_tickets` and otherwise
  the caller's own `requester_id` (mirroring `TicketService::isAgent()`),
  organization-wide maintenance for `manage_maintenance` and otherwise the
  caller's own `requested_by` (mirroring `MaintenanceRequestService::assertCanView()`),
  and notifications always filtered to the caller's own `user_id` (DR-017).
  `CommandCenterScope` performs no database access — it is a pure decision object
  built from the authenticated user, so the authorization contract is testable
  without data.
- **Current state and dated history are never mixed.** The snapshot is
  undated; `recent_activity` is dated event history; the two sit side by side and
  are never summed, averaged or filtered together. There is no
  `stock_movement_today` in a current-state snapshot, and `active` is a literal
  status predicate (`OPEN`/`IN_PROGRESS` for tickets, `REQUESTED`/`APPROVED`/
  `IN_PROGRESS` for maintenance) rather than a derived workload score.
- **DR-018's deferral is answered, not reversed.** The four event tables still do
  not share a vocabulary, and none is invented: every activity item keeps its
  `type` discriminator and the `action` value **exactly as that source stores it**
  (`ASSIGNED`, `STOCK_IN`, the stored ticket action, …). The only synthesized
  action is maintenance's, because `maintenance_records` has no `action` column —
  `WORK_STARTED`/`WORK_COMPLETED` are read off the real `completed_at`/
  `started_at` lifecycle columns, a statement about columns rather than a
  judgement about the work. Merging is therefore presentation, not semantics.
- **The merge is exact and bounded.** Each source is read with its own `limit`
  and the merged list is truncated to `limit`. This cannot miss a row: anything
  that would appear in the global newest `limit` must be among its own source's
  newest `limit`. Rows read stay bounded at `sources × limit`, ordering is
  `occurred_at DESC` with a `(type, record_id)` tiebreak so equal timestamps are
  stable across drivers, and notifications are excluded as a source because an
  inbox is not an event stream.
- **Queues are defined by stored columns only** — "waiting for a person" means
  `assigned_to IS NULL` in an unfinished status, or a `PENDING` assignment
  handover (DR-008). No severity, score or "needs attention" label exists. Each
  queue reports the complete `count` alongside a bounded item list, so a
  dashboard can show "37 waiting" without transferring 37 records, and the
  applied `limit` is echoed back.
- **Bounded vs complete, consistently:** `limit` (default 10, max 50) bounds
  only the lists that scale with backlog; every count is complete. `from`/`to`
  are **prohibited** rather than ignored — silently dropping a period argument on
  a current-state surface would imply a filtered answer that does not exist.
- **Stock is never recomputed here.** `snapshot.inventory.stock_quantity` comes
  from `StockMovementService` (DR-014), so the dashboard can never drift from
  inventory. As in DR-018, money and decimal quantities stay in their owning
  services.
- **Interpretive metrics are refused.** No health, risk, score, trend direction,
  ranking or prediction. An empty operation is `200` with zeroes and empty lists,
  never `404`.
- **Performance is a design constraint, not a follow-up.** Every snapshot number
  is a `COUNT`/`SUM`; queues are one `COUNT` plus one bounded `SELECT`; activity
  is one bounded joined `SELECT` per permitted source. No collection is hydrated
  to be counted and no per-row relation is loaded, so query count is independent
  of data volume — asserted in the test suite by a hard budget (17 for a
  fully-permitted caller) plus a test that the count does not change when the
  queues grow. Resolving permissions once per request instead of issuing one
  lookup per check is what keeps that budget reachable; `User::hasPermission()`
  now answers from the loaded role relation, which is a behavior-preserving
  change that benefits every read path in the app.
- **Indexing is minimal and justified:** `created_at` indexes were added to
  `asset_histories`, `ticket_histories` and `maintenance_records` for the
  newest-first ordering; `stock_movements` already had one. No index was added
  for the assignment or unassigned predicates — the new activity index does not
  justify one there, and the existing `status` indexes already serve them.
- **Portability and cost:** standard SQL only (aggregates, `LIMIT`, joins,
  Eloquent), timestamps normalized in PHP rather than a driver-specific SQL
  expression, no schema change beyond three indexes, and no new package
  (DR-002). PostgreSQL runtime verification was not possible in this environment
  (`pdo_pgsql` unavailable, no Docker), so the portability claim rests on the
  absence of driver-specific SQL, not on an executed PostgreSQL run.
- **Deferred:** client dashboard screens and chart rendering (Phase 19B), and any
  predictive or scored prioritization — that would be an inference layer, not a
  read of current state.

## Decision Record 020 — Immutable Audit Governance Trail

- **Status:** Accepted
- **Reason:** Phase 20A turns the Phase 03 `audit_logs` foundation into a
  production-ready, read-only governance API. Audit logs answer four questions
  — WHO performed WHAT action on WHICH resource, WHEN — with safe context, and
  are deliberately distinct from the operational histories (tickets, assets,
  stock movements, maintenance records, notifications): those describe domain
  workflow; the audit trail describes governance of the platform itself. No
  domain history row is duplicated into `audit_logs`.
- **Append-only by construction:** the table has no `updated_at` and the API
  exposes exactly two GET endpoints; every write verb is `405` because no route
  exists. The only application writer is `AuditLogService` — no client path can
  create, edit, or remove a row. Immutability is structural, not filtered.
- **Event selection is deliberate, not blanket:** only governance-relevant
  mutations are audited — auth (register/login/logout), user create/update/
  delete, asset/assignment lifecycle, inventory master data and stock
  movements, ticket create/status/assignment, maintenance request/record
  writes. Reads, comments, notification reads, reports, and command-center
  views are never audited, so the trail cannot become self-noisy. Actions and
  resource types live in controlled vocabularies (`App\Support\Audit\AuditAction`,
  `AuditResourceType`); no PHP class names are ever stored or exposed.
- **Actor resolution is server-side only:** the actor comes from the
  authenticated request context passed by the service layer; client-supplied
  identity fields are never trusted. The schema's nullable `user_id` FK
  (`nullOnDelete`) supports system actors without inventing a fake user, and
  audit rows survive user deletion — the API renders `actor: null` gracefully.
- **Sensitive-data policy:** nothing secret is ever written. Old/new values are
  allowlisted per domain and scrubbed by `SensitiveValueFilter` (password,
  token, secret, cookie, authorization keys — matched case-insensitively at
  any depth) before insert. A password change is auditable as a fact, never as
  a value. Metadata is compact; request bodies and headers are never dumped.
  The schema has no correlation-ID column, so none was invented (no tracing
  subsystem).
- **Transaction strategy:** audit rows are written by the same service that
  owns the mutation, after the business write succeeded and — where the service
  already runs a `DB::transaction` (asset assignment, stock movements, tickets,
  maintenance) — inside it. A failed or rolled-back mutation therefore leaves
  no misleading "success" row, and notifications/audit describe the same
  committed state. Controllers never write audit rows, so exactly one row per
  user action exists and duplication by layering is impossible.
- **Query model:** `AuditLogQuery` is strictly read-only with whitelisted
  filters (`actor_id`, `action`, `resource_type`, `resource_id`, inclusive
  `from`/`to` up to 366 days — the Reports convention), a whitelisted sort
  (`created_at | action | resource_type`, `created_at DESC` default with an `id`
  tiebreak), and pagination default 25 / max 100. `resource_type` and `action`
  are validated against their vocabularies; unknown values are ignored, never
  injected. Permission (`view_audit_logs`, already seeded to super_admin/admin
  in Phase 04) is the access control — the `actor_id` filter is a query aid,
  not a scope, and authorized governance users see the global trail.
- **Performance:** each audit event is a single direct insert with a known
  actor id — no observers, no event bus, no redundant user lookups. The list
  eager-loads the actor (`with('user')`) so N+1 cannot occur; the Phase 03
  migration already carries indexes on `user_id`, `entity_type`, `entity_id`,
  `action`, and `created_at`, so no new migration was needed.
- **Seed idempotency:** seeders do not write audit rows — seeded domain data
  (created via direct model writes, the same path as before) leaves
  `audit_logs` empty after `migrate:fresh --seed`, and re-seeding adds zero
  rows. Audit growth is therefore deterministic (zero) in development.
- **Schema fidelity:** no migration was changed and no field was added; the
  Phase 03 table design already matched every requirement (jsonb old/new
  values, 45-char IP, `created_at` without `updated_at`). PostgreSQL
  compatibility rests on portable Eloquent/query-builder usage
  (`whereDate`, jsonb casts) with no driver-specific SQL.
- **Trade-offs:** audit rows carry no correlation ID (deferred until real
  tracing exists), failed logins are not audited (no misleading-failure rows;
  security-event separation deferred), and there is no retention/archival
  policy yet. The governance frontend is Phase 20B.

## Roadmap / Modules

```text
Identity        users, roles, permissions, role_permissions
Organization    departments, locations
Assets          asset_categories, assets, asset_assignments, asset_histories
Inventory       item_categories, items, warehouses, stock_movements
Helpdesk        ticket_categories, tickets, ticket_comments, ticket_histories
Maintenance     maintenance_requests, maintenance_records, maintenance_parts
System          notifications, audit_logs
```

All tables above are implemented as migrations, and every module has a matching
Eloquent model, in the database phase (see `docs/database/README.md`). The
**Organization API** (departments & locations), **Users API**, **Asset
Management API** (categories, assets, assignments, QR), **Inventory API**
(item categories, items, warehouses, stock movements), **Helpdesk API**
(ticket categories, tickets, comments, history), **Maintenance API**
(requests, records, parts), **Notifications API** (per-user inbox with
server-side delivery), **Reports API** (read-only operational aggregates), and
**Command Center API** (read-only scoped current-state snapshot, operational
queues, and recent activity), and the **Audit Logs API** (read-only, append-only
governance trail written by the domain services — DR-020) are live.

## Backend Layer Conventions

| Layer | Location | Purpose |
| ----- | -------- | ------- |
| API Controllers | `app/Http/Controllers/Api/` | Thin request wiring. Extend `Api\Controller`. |
| FormRequests | `app/Http/Requests/` | Field validation lives here, never in controllers. |
| API Resources | `app/Http/Resources/` | JSON serialization for module payloads. |
| Services | `app/Services/` | Domain logic and business workflows. |
| Middleware | `app/Http/Middleware/` | Guard/route policy (`role`, `permission`) plus the API-wide `auth` middleware (no web-login redirect). |
| Support | `app/Support/` | Cross-cutting helpers (`ApiResponse`, exception renderer). |

Cross-cutting infrastructure is applied as follows: authentication & RBAC
(Phase 4) live under `app/Http/Controllers/Api/AuthController.php`,
`app/Services/AuthService.php`, `app/Http/Middleware/*`, and
`app/Http/Requests/Auth/*`, wiring routes under `/api/v1/auth`. The
**Organization module** (Phase 5) is implemented — `DepartmentController`,
`LocationController`, `DepartmentService`, `LocationService`,
`app/Http/Requests/Organization/*`, and `DepartmentResource`/`LocationResource`
under `/api/v1/departments` and `/api/v1/locations`. The **Helpdesk module**
(Phase 15A) is implemented — `TicketCategoryController`, `TicketController`,
`TicketCategoryService`, `TicketService`, `app/Http/Requests/Ticket/*`,
`TicketCategoryResource`/`TicketResource`/`TicketCommentResource`/
`TicketHistoryResource`, and factories/seeders for the ticket tables — under
`/api/v1/tickets*` and `/api/v1/ticket-categories`. The **Reports module**
(Phase 18A) is implemented — `ReportController`,
`app/Http/Requests/Report/ReportRequest`, and the read-only aggregate layer in
`app/Services/Reports/` (`ReportService` plus one query class per module, with
`ReportPeriod`, `ReportDayBucket` and `ReportBreakdown` as shared helpers) —
under `/api/v1/reports*`, gated by `permission:view_reports` (DR-018).The **Command Center module** (Phase 19A) is implemented — `CommandCenterController`,
`app/Http/Requests/CommandCenter/CommandCenterRequest`, and the read-only
current-state layer in `app/Services/CommandCenter/` (`CommandCenterService` plus
`CommandCenterSnapshotQuery`, `CommandCenterQueueQuery`,
`CommandCenterActivityQuery` and the pure `CommandCenterScope` decision object) —
under `/api/v1/command-center`, gated by `permission:view_dashboard` (DR-019).
The **Audit module** (Phase 20A) is implemented — `AuditLogController`,
`app/Http/Requests/Audit/AuditLogIndexRequest`, `AuditLogResource`, the
write-side `AuditLogService` (the only application writer, called by the domain
services) and the read-side `AuditLogQuery`, with the shared vocabularies and
scrubber under `app/Support/Audit/` (`AuditAction`, `AuditResourceType`,
`SensitiveValueFilter`) — under `/api/v1/audit-logs`, gated by
`permission:view_audit_logs` (DR-020).
API controllers are versioned under `/api/v1`.