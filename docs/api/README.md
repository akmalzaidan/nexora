# API

The NEXORA backend exposes a versioned REST API. The contract is kept here and
will be generated with OpenAPI / Swagger.

## Base URL

```text
http://localhost:8000/api/v1
```

Authentication uses **Laravel Sanctum** personal access tokens
(`Authorization: Bearer <token>`). The `sanctum/csrf-cookie` endpoint is also
available — it is only needed for web/session clients, not for the token flow.

## Versioning

All endpoints live under `/api/v1`. No `/api/v2` exists; a future major version
would introduce it deliberately.

## Health Endpoint

```http
GET /api/v1/health
```

Response (200):

```json
{
  "success": true,
  "message": "NEXORA API is healthy",
  "data": {
    "application": "NEXORA",
    "version": "1.0.0",
    "environment": "local"
  }
}
```

`application` and `version` come from `config/nexora.php` (env-driven, see
`.env.example`: `NEXORA_NAME`, `APP_VERSION`). No secrets, paths, or stack
traces are exposed.

## Response Contract

All endpoints follow the same envelope, produced by `App\Support\ApiResponse`.

Success:

```json
{
  "success": true,
  "message": "Operation successful",
  "data": {}
}
```

Validation / error:

```json
{
  "success": false,
  "message": "Validation failed.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

## Authentication

Sanctum **personal access tokens**. Registration and login both issue a long
lived bearer token (`nexora-api`); the client stores it and sends it as:

```http
Authorization: Bearer <token>
```

Flow:

1. `POST /api/v1/auth/register` (or `/login`) → `200/201` with
   `data.token` + `data.user`.
2. Client calls protected endpoints with the bearer token.
3. `POST /api/v1/auth/logout` revokes **only the token in use** — other tokens
   for the same user stay valid.
4. `GET /api/v1/auth/me` returns the current user with their role and
   department (may be `null`).

Protected routes use the `auth:sanctum` middleware. Unauthenticated requests
get a `401` with `{ "success": false, "message": "Unauthenticated." }` regardless
of whether the client sent `Accept: application/json`.

### Endpoints

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| POST | `/api/v1/auth/register` | No | Create account (always role `staff`) |
| POST | `/api/v1/auth/login` | No | Login, returns token |
| POST | `/api/v1/auth/logout` | Yes | Revoke current token |
| GET | `/api/v1/auth/me` | Yes | Current user with role/department |

Register body: `name`, `email`, `password`, `password_confirmation`.
Password rules: min 8 chars, must be confirmed.

Login body: `email`, `password`.

### Error semantics

- **401 `Invalid credentials`** — unknown email, wrong password, **or** inactive
  account. The same message is used for all three so the API never reveals which
  failed.
- **429 `Too Many Requests`** — rate limits: login `5` per minute/per
  email+IP; register `3` per minute/per IP.
- The token is only ever returned once, at register/login. `/me` never exposes
  tokens or password hashes.

## Role-Based Access Control (RBAC)

- Roles and permissions are database-driven (`roles`, `permissions`,
  `role_permissions`) and seeded by `DatabaseSeeder` (see
  `docs/database/README.md`).
- Permission checks are centralized on `App\Models\User`
  (`hasPermission`, `hasAnyPermission`, `hasRole`, `hasAnyRole`,
  `isSuperAdmin`). The `super_admin` role bypasses permission checks but never
  authentication.
- Route middleware protects routes by slug:
  - `role:slug1,slug2` — user must have **any** listed role, else `403
    Access denied.`
  - `permission:slug1,slug2` — user must have **any** listed permission, else
    `403 Access denied.`
- Roles cannot be chosen at registration; registration always assigns `staff`.

## Organization — Departments & Locations

Implemented as the first business module. Every endpoint requires
`auth:sanctum` (there are no public Organization endpoints).

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_departments` | super_admin, admin | `GET /departments`, `GET /departments/{id}` |
| `manage_departments` | super_admin, admin | `POST/PUT/DELETE /departments*` |
| `view_locations` | super_admin, admin | `GET /locations`, `GET /locations/{id}` |
| `manage_locations` | super_admin, admin | `POST/PUT/DELETE /locations*` |

Users without the matching permission (e.g. `staff`) receive `403 Access
denied.` — checked by permission slug, never by role ID.

### Departments

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/departments` | `view_departments` | Paginated list |
| POST | `/api/v1/departments` | `manage_departments` | Create |
| GET | `/api/v1/departments/{department}` | `view_departments` | Detail with manager + users_count |
| PUT | `/api/v1/departments/{department}` | `manage_departments` | Update |
| DELETE | `/api/v1/departments/{department}` | `manage_departments` | Delete (409 if in use) |

Create/update body: `name` (required), `code` (required, unique, max 20),
`description` (nullable), `manager_id` (nullable — must reference an **active**
user), `is_active` (boolean).

Detail response includes `manager` (basic user `id`/`name`/`email`, or `null`)
and `users_count` (via `withCount`, never a per-row loop).

### Locations

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/locations` | `view_locations` | Paginated list |
| POST | `/api/v1/locations` | `manage_locations` | Create |
| GET | `/api/v1/locations/{location}` | `view_locations` | Detail |
| PUT | `/api/v1/locations/{location}` | `manage_locations` | Update |
| DELETE | `/api/v1/locations/{location}` | `manage_locations` | Delete (409 if in use) |

Create/update body: `name` (required), `code` (required, unique, max 20),
`description` (nullable), `address` (nullable), `is_active` (boolean).

### List query parameters (both modules)

```text
?page=1        current page
?per_page=15   page size (default 15, clamped to 1..100)
?search=IT     case-insensitive substring match on name or code
?is_active=true  filter active records
?sort=name     whitelisted column: name | code | created_at
?direction=asc asc | desc (default asc)
```

Case-insensitive search uses `whereLike` (lowered comparison / `ILIKE` on
PostgreSQL) — no raw SQL and no injection risk.

### List response

```json
{
  "success": true,
  "message": "Departments retrieved successfully",
  "data": {
    "items": [],
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 50,
      "last_page": 4
    }
  }
}
```

### Conflict behavior

- **Department in use** (has users): `DELETE` returns `409 Conflict` with
  `"Department cannot be deleted because it is still in use"`.
- **Location in use** (referenced by assets, warehouses, or asset
  assignments): `DELETE` returns `409 Conflict` with
  `"Location cannot be deleted because it is still in use"`.
- Operational data is never cascade-deleted.

### Department manager rules

- `manager_id` must reference an existing **active** user.
- Appointing a manager **never changes that user's role** — an organizational
  position (`manager_id`) is distinct from the `manager` role.
- `manager_id: null` clears the manager on update.

## Development accounts

Seeded by `UserSeeder` (all password `password`, dev only):

| Email | Role |
| ----- | ---- |
| `superadmin@nexora.test` | super_admin |
| `admin@nexora.test` | admin |
| `manager@nexora.test` | manager |
| `staff@nexora.test` | staff |
| `technician@nexora.test` | technician |
| `warehouse@nexora.test` | warehouse_staff |

## Error Handling

API failures are rendered as JSON via
`App\Support\Exceptions\ApiExceptionRenderer` (registered in
`bootstrap/app.php`). Non-API (web) requests keep Laravel's default handling.

| Status | Meaning |
| ------ | ------- |
| 400 | Bad request |
| 401 | Unauthenticated |
| 403 | Forbidden / unauthorized action |
| 404 | Route or resource not found |
| 409 | Resource conflict (e.g. deleting a department/location still in use) |
| 422 | Validation failed (`errors` = field map) |
| 429 | Too many requests |
| 500 | Server error (generic message; debug message only when `APP_DEBUG=true`) |

## Conventions

- Nouns over verbs in URLs (`/users`, `/assets/{id}`).
- Consistent terminology for record statuses and list/detail responses.
- Validation rules live in FormRequest classes
  (`app/Http/Requests/`), never as large inline arrays in controllers.
- List/detail JSON is produced with Laravel API Resources
  (`app/Http/Resources/`) where serialization is non-trivial.
- Every mutation is idempotent or documented as non-idempotent.
- The external envelope above stays stable even as internals follow Laravel
  conventions.

## Endpoints

| Method | Path | Status |
| ------ | ---- | ------ |
| POST | `/api/v1/auth/register` | Implemented |
| POST | `/api/v1/auth/login` | Implemented |
| POST | `/api/v1/auth/logout` | Implemented |
| GET | `/api/v1/auth/me` | Implemented |
| GET | `/api/v1/departments` | Implemented |
| POST | `/api/v1/departments` | Implemented |
| GET | `/api/v1/departments/{department}` | Implemented |
| PUT | `/api/v1/departments/{department}` | Implemented |
| DELETE | `/api/v1/departments/{department}` | Implemented |
| GET | `/api/v1/locations` | Implemented |
| POST | `/api/v1/locations` | Implemented |
|| GET | `/api/v1/locations/{location}` | Implemented |
|| PUT | `/api/v1/locations/{location}` | Implemented |
|| DELETE | `/api/v1/locations/{location}` | Implemented |
|| GET | `/api/v1/users` | Implemented |
|| POST | `/api/v1/users` | Implemented |
|| GET | `/api/v1/users/{user}` | Implemented |
|| PUT | `/api/v1/users/{user}` | Implemented |
|| DELETE | `/api/v1/users/{user}` | Implemented |
||| GET | `/api/v1/health` | Implemented |
|| GET | `/api/v1/item-categories` | Implemented |
|| POST | `/api/v1/item-categories` | Implemented |
|| GET | `/api/v1/item-categories/{itemCategory}` | Implemented |
|| PUT | `/api/v1/item-categories/{itemCategory}` | Implemented |
|| DELETE | `/api/v1/item-categories/{itemCategory}` | Implemented |
|| GET | `/api/v1/items` | Implemented |
|| POST | `/api/v1/items` | Implemented |
|| GET | `/api/v1/items/{item}` | Implemented |
|| PUT | `/api/v1/items/{item}` | Implemented |
|| DELETE | `/api/v1/items/{item}` | Implemented |
|| GET | `/api/v1/warehouses` | Implemented |
|| POST | `/api/v1/warehouses` | Implemented |
|| GET | `/api/v1/warehouses/{warehouse}` | Implemented |
|| PUT | `/api/v1/warehouses/{warehouse}` | Implemented |
|| DELETE | `/api/v1/warehouses/{warehouse}` | Implemented |
|| GET | `/api/v1/stock-movements` | Implemented |
|| POST | `/api/v1/stock-movements` | Implemented |
|| GET | `/api/v1/stock-movements/{stockMovement}` | Implemented |
|| GET | `/api/v1/ticket-categories` | Implemented |
|| POST | `/api/v1/ticket-categories` | Implemented |
|| GET | `/api/v1/ticket-categories/{ticketCategory}` | Implemented |
|| PUT | `/api/v1/ticket-categories/{ticketCategory}` | Implemented |
|| DELETE | `/api/v1/ticket-categories/{ticketCategory}` | Implemented |
|| GET | `/api/v1/tickets` | Implemented |
|| POST | `/api/v1/tickets` | Implemented |
|| GET | `/api/v1/tickets/{ticket}` | Implemented |
|| PUT | `/api/v1/tickets/{ticket}` | Implemented |
|| GET | `/api/v1/tickets/{ticket}/comments` | Implemented |
|| POST | `/api/v1/tickets/{ticket}/comments` | Implemented |
|| GET | `/api/v1/tickets/{ticket}/history` | Implemented |
|| GET | `/api/v1/reports/overview` | Implemented |
|| GET | `/api/v1/reports/assets` | Implemented |
|| GET | `/api/v1/reports/inventory` | Implemented |
|| GET | `/api/v1/reports/tickets` | Implemented |
|| GET | `/api/v1/reports/maintenance` | Implemented |
|| GET | `/api/v1/command-center` | Implemented |
|| GET | `/api/v1/audit-logs` | Implemented |
|| GET | `/api/v1/audit-logs/{auditLog}` | Implemented |

## Inventory (Phase 14A)

Inventory master data (item categories, items, warehouses) plus the
**append-only stock movement journal**. Stock balance is never stored — it is
always derived from movements.

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_inventory` | super_admin, admin, manager, staff, warehouse_staff | `GET /item-categories`, `GET /items`, `GET /warehouses`, `GET /stock-movements` |
| `manage_inventory` | super_admin, admin, warehouse_staff | `POST/PUT/DELETE /item-categories*`, `POST/PUT/DELETE /items*`, `POST/PUT/DELETE /warehouses*` |
| `manage_stock` | super_admin, admin, warehouse_staff | `POST /stock-movements` |

`view_inventory` is available to 5 roles (technician has neither inventory
permission). `manage_inventory` and `manage_stock` are restricted to
super_admin, admin, and warehouse_staff — the operational model where warehouse
staff record stock but only admins manage master data. No new permissions are
introduced.

### Endpoints

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/item-categories` | `view_inventory` | Paginated list with items_count |
| POST | `/api/v1/item-categories` | `manage_inventory` | Create |
| GET | `/api/v1/item-categories/{itemCategory}` | `view_inventory` | Detail with items_count |
| PUT | `/api/v1/item-categories/{itemCategory}` | `manage_inventory` | Update |
| DELETE | `/api/v1/item-categories/{itemCategory}` | `manage_inventory` | Delete (409 if items reference it) |
| GET | `/api/v1/items` | `view_inventory` | Paginated list with category + stock total |
| POST | `/api/v1/items` | `manage_inventory` | Create |
| GET | `/api/v1/items/{item}` | `view_inventory` | Detail with category + stock total + warehouse breakdown |
| PUT | `/api/v1/items/{item}` | `manage_inventory` | Update |
| DELETE | `/api/v1/items/{item}` | `manage_inventory` | Soft delete (409 if stock movements or maintenance parts reference it) |
| GET | `/api/v1/warehouses` | `view_inventory` | Paginated list with location |
| POST | `/api/v1/warehouses` | `manage_inventory` | Create |
| GET | `/api/v1/warehouses/{warehouse}` | `view_inventory` | Detail with location |
| PUT | `/api/v1/warehouses/{warehouse}` | `manage_inventory` | Update |
| DELETE | `/api/v1/warehouses/{warehouse}` | `manage_inventory` | Delete (409 if stock movements reference it) |
| GET | `/api/v1/stock-movements` | `view_inventory` | Paginated journal list (item/warehouse/type filters) |
| POST | `/api/v1/stock-movements` | `manage_stock` | Record a STOCK_IN / STOCK_OUT movement |
| GET | `/api/v1/stock-movements/{stockMovement}` | `view_inventory` | Movement detail with item, warehouse, performer |

### Movement type contract

The two official movement types are `STOCK_IN` and `STOCK_OUT` — constants on
`App\Models\StockMovement`. A transfer between warehouses is expressed as a
STOCK_OUT from the source plus a STOCK_IN to the destination (a dedicated
transfer workflow is a later phase).

- `quantity` is always a positive integer; direction is determined by `type`.
- The PostgreSQL check `quantity > 0` and the request rule `min:1` both
  enforce this.

### Stock balance derivation

`stock_movements` is an append-only journal with no `updated_at` and no
mutable balance column. Balance for an item in a warehouse is:

```text
balance(item, warehouse) = SUM(STOCK_IN.quantity) − SUM(STOCK_OUT.quantity)
```

`GET /items/{item}` exposes `stock.total` (sum across all warehouses) and
`stock.warehouses[]` (per-warehouse breakdown, excluding zero balances). The
list response exposes only `stock.total` — computed with `withSum` aggregates,
never a per-row query.

### Create (`POST /api/v1/stock-movements`)

Validation:

| Field | Rule |
| ----- | ---- |
| item_id | required \| exists:items,id (non-deleted) |
| warehouse_id | required \| exists:warehouses,id |
| type | required \| in:STOCK_IN,STOCK_OUT |
| quantity | required \| integer \| min:1 |
| reference_type | nullable \| string \| max:50 |
| reference_id | nullable \| integer |
| notes | nullable \| string |

Business rules enforced server-side:

- The whole operation runs in a `DB::transaction()` with `lockForUpdate()` on
  the item row, so concurrent movements for the same item serialize.
- A `STOCK_OUT` is rejected with **422 `Insufficient stock for this
  movement.`** when it would drive the warehouse balance below zero. Nothing
  is written.
- `performed_by` is always the authenticated user — never taken from the
  request body (client-supplied `performed_by` is ignored).
- Soft-deleted items cannot receive movements (validation rejects them).
- On success: `201` with the movement, including nested `item`, `warehouse`,
  and `performer`.

Request body:

```json
{
    "item_id": 1,
    "warehouse_id": 1,
    "type": "STOCK_IN",
    "quantity": 25,
    "notes": "Delivery received"
}
```

### List (`GET /api/v1/stock-movements`)

Query parameters:

```text
?page=1              current page
?per_page=15         page size (default 15, clamped to 1..100)
?item_id=1           filter by item
?warehouse_id=1      filter by warehouse
?type=STOCK_IN       filter by movement type
?sort=created_at     whitelisted: created_at | quantity (default created_at)
?direction=desc      asc | desc (default desc — newest first)
```

### Immutability

The journal is append-only. There is **no** `PUT /stock-movements/{id}` and
**no** `DELETE /stock-movements/{id}` — those methods return `405`. A mistake
is corrected by posting a compensating STOCK_IN or STOCK_OUT movement, never
by editing history.

### Conflict behavior

- **Insufficient stock (STOCK_OUT)**: `422` — `"Insufficient stock for this movement."`
- **Category in use (has items)**: `409` — `"Item category cannot be deleted because it is still in use"`
- **Item with stock movements**: `409` — `"Item cannot be deleted because it has stock movements"`
- **Item in maintenance parts**: `409` — `"Item cannot be deleted because it is used in maintenance records"`
- **Warehouse with stock movements**: `409` — `"Warehouse cannot be deleted because it has stock movements"`
- **Soft-deleted item on movement create**: `422` (validation) on `item_id`.

### Development seed data

`WarehouseSeeder` (3 warehouses) and `InventorySeeder` (5 items + opening
STOCK_IN balances) are idempotent — re-running never double-counts stock.
Opening balances are attributed to `admin@nexora.test`.

### Deferred — Phase 14B+

- Frontend inventory workspace (items table, warehouse view, movement form).
- Warehouse-to-warehouse transfer as a first-class workflow.
- Low-stock / out-of-stock alerts and threshold monitoring.

## Helpdesk — Ticket Management (Phase 15A)

Ticket categories, tickets, comments, and an append-only ticket timeline. The
ticket lifecycle is explicit and every state/assignment change is recorded next
to the change itself.

### Permissions

The Phase 04 seeder ships exactly three ticket permissions. No new permission
is introduced; `manage_tickets` is added to **manager** and **technician** in
Phase 15A so the operational roles can actually process tickets.

| Permission | Granted to (before 15A → after) | Protects |
| ---------- | ------------------------------ | -------- |
| `view_tickets` | super_admin, admin, manager, technician, staff (unchanged) | `GET/POST /tickets*`, `GET /tickets/{id}/comments`, `POST /tickets/{id}/comments`, `GET /tickets/{id}/history`, `GET /ticket-categories` |
| `manage_tickets` | super_admin, admin → + manager, + technician | `PUT /tickets/{id}`, `POST/PUT/DELETE /ticket-categories*` |
| `assign_tickets` | super_admin, admin, manager, technician (unchanged) | assignment inside `PUT /tickets/{id}` |

**Visibility model (service level, applied on top of route permissions):**

- **Agents** = users holding `manage_tickets` **or** `assign_tickets`
  (super_admin, admin, manager, technician) — they can see and handle *every*
  ticket.
- **Requesters** = users holding only `view_tickets` (staff) — they only see,
  read, and comment on the tickets they raised (`requester_id = auth user`).
  Other users' tickets return `403 You do not have access to this ticket`.

### Ticket Categories

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/ticket-categories` | `view_tickets` | Paginated list with tickets_count |
| POST | `/api/v1/ticket-categories` | `manage_tickets` | Create |
| GET | `/api/v1/ticket-categories/{ticketCategory}` | `view_tickets` | Detail with tickets_count |
| PUT | `/api/v1/ticket-categories/{ticketCategory}` | `manage_tickets` | Update |
| DELETE | `/api/v1/ticket-categories/{ticketCategory}` | `manage_tickets` | Delete (409 if any ticket references it) |

Create/update body: `name` (required, max 255), `code` (required, unique,
max 50), `description` (nullable, max 1000). Deleting a category backed by
tickets returns `409 "Ticket category cannot be deleted because it is still in
use"` — historical tickets are never cascade-deleted.

### Tickets

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/tickets` | `view_tickets` | Paginated list (staff → own tickets only) |
| POST | `/api/v1/tickets` | `view_tickets` | Create a ticket as the authenticated user |
| GET | `/api/v1/tickets/{ticket}` | `view_tickets` | Detail (staff → own tickets only) |
| PUT | `/api/v1/tickets/{ticket}` | `manage_tickets` | Update fields, status, assignee |

There is **no** `DELETE /tickets` — tickets are operational records and are
never hard-deleted (a `DELETE` request returns `405`).

Create body:

- `title` (required, max 255), `description` (required).
- `category_id`, `department_id`, `location_id` (nullable nullable references).
- `priority` (nullable, `LOW | MEDIUM | HIGH | URGENT`, default `MEDIUM`).
- `requester_id`, `assigned_to`, `status`, `ticket_number` are **never
  accepted** — the requester is always the authenticated user, the number is
  generated server-side (`TCK-……`), and a ticket always starts `OPEN` and
  unassigned. Client-supplied values are ignored (test
  `test_requester_identity_always_comes_from_authenticated_user`).

List query filters:

```
?search=…             matches ticket_number, title, description
?status=OPEN…         OPEN | IN_PROGRESS | RESOLVED | CLOSED
?priority=…           LOW | MEDIUM | HIGH | URGENT
?category_id=…         FK to ticket_categories
?requester_id=…        FK to users (staff are still scoped to their own)
?assigned_to=…         FK to users
?department_id=…       FK to departments
?location_id=…         FK to locations
?sort=…                whitelisted: ticket_number | title | priority | status | created_at | updated_at (default created_at)
?direction=asc|desc    default desc (newest first)
?per_page=…            default 15, max 100
```

### Status workflow

The accepted lifecycle (single-step, no skipping):

```text
OPEN ──► IN_PROGRESS ──► RESOLVED ──► CLOSED
  ▲                                      │
  └───────────────── reopen ◄────────────┘   (CLOSED → OPEN re-opens)
```

- Changing status is done with `PUT /tickets/{id}` + `{"status":"…"}`.
- A same-status update is a no-op (no history row).
- Anything outside the map — e.g. `OPEN → RESOLVED`, `OPEN → CLOSED` — returns
  `422 "Invalid status transition from X to Y"` and leaves the ticket and its
  history untouched.
- Reaching `CLOSED` sets `closed_at`; leaving `CLOSED` (reopen) clears it.
- Status changes run inside a **transaction with the ticket row locked**, and
  write a `STATUS_CHANGED` row (old/new status, actor) to `ticket_histories` at
  the same time — the timeline can never diverge from the ticket state.

### Assignment

- Only users with `assign_tickets` may change the assignee (all ticket agents);
  everyone else gets `403`.
- The assignee must exist, be **active**, and hold `assign_tickets`;
  otherwise `422` (`…does not exist` / `…inactive user` / `…is not allowed to
  handle tickets`).
- `{"assigned_to": null}` unassigns. Every change writes an
  `ASSIGNMENT_CHANGED` history row with a human-readable note.
- Assignment is decided server-side only; `POST /tickets` never accepts an
  assignee.

### Comments

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/tickets/{ticket}/comments` | `view_tickets` | Paginated list (staff see public comments on own tickets) |
| POST | `/api/v1/tickets/{ticket}/comments` | `view_tickets` | Create (author = authenticated user) |

Body: `comment` (required, max 5000), `is_internal` (boolean, optional).

- The author is always the authenticated user; there is no field to spoof it.
- `is_internal` is honored only for agents — staff sending `is_internal: true`
  gets a **public** comment anyway, so a requester can never hide a comment
  from the agent working the ticket.
- Staff listing comments on their own ticket only see public comments; internal
  notes are agent-only.
- Comments live in their own table and do **not** generate `ticket_histories`
  rows.

### History (timeline)

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/tickets/{ticket}/history` | `view_tickets` | Append-only timeline, oldest first |

`ticket_histories` is **append-only** (no `updated_at`, per DR-003): there are
no update/delete endpoints (`405`), so replaying the rows reproduces every
change in order. Actions: `CREATED`, `UPDATED`, `STATUS_CHANGED`,
`ASSIGNMENT_CHANGED`. Each row records the acting user, old/new status where
relevant, and free-text notes. Comments are intentionally not duplicated as
history rows.

### Example

```http
POST /api/v1/tickets
Authorization: Bearer <token>

{ "title": "Laptop won't boot", "description": "Blue screen at login.",
  "category_id": 1, "priority": "HIGH" }
```

```json
{
  "success": true,
  "message": "Ticket created successfully",
  "data": {
    "id": 1, "ticket_number": "TCK-9F2A1B7C",
    "title": "Laptop won't boot", "description": "Blue screen at login.",
    "category": { "id": 1, "name": "Hardware", "code": "HARDWARE" },
    "requester": { "id": 5, "name": "Staff", "email": "staff@nexora.test",
                   "role": { "id": 4, "name": "Staff", "slug": "staff" },
                   "department": null, "is_active": true, "created_at": "…" },
    "assignee": null, "department": null, "location": null,
    "priority": "HIGH", "status": "OPEN",
    "closed_at": null, "created_at": "…", "updated_at": "…"
  }
}
```

```http
PUT /api/v1/tickets/1
Authorization: Bearer <token>

{ "status": "IN_PROGRESS", "assigned_to": 6 }
```

### Conflict and error behavior

- `401` unauthenticated; `403` missing permission or cross-user access.
- `404` unknown ticket/category; `405` unsupported method (e.g.
  `DELETE /tickets`, `PUT /history`).
- `409` category delete while tickets reference it.
- `422` invalid status transition, invalid priority/status value, or
  invalid assignee (nonexistent / inactive / not allowed to handle tickets).

### Development seed data

`TicketCategorySeeder` (5 categories: HARDWARE, SOFTWARE, NETWORK, ACCOUNT,
GENERAL) and `TicketSeeder` (4 demo tickets across all statuses plus comments
and a consistent history chain) are idempotent — re-running never duplicates
tickets, comments, or timeline rows.

### Deferred — Phase 15B+

- Frontend helpdesk workspace (request form, ticket board, comment thread).
- SLA / escalation timers and notifications.
- Bulk reassignment and export.

## Maintenance Management (Phase 16A)

Maintenance Requests (backlog) and Maintenance Records (executed work orders)
with an append-only parts list. Backend-only REST API.

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_maintenance` | super_admin, admin, manager, staff, technician | `GET /maintenance-requests`, `GET /maintenance-records`, `GET /maintenance-records/{record}/parts` |
| `manage_maintenance` | super_admin, admin, technician | `POST/PUT /maintenance-requests*`, `POST/PUT /maintenance-records*`, `POST /maintenance-records/{record}/parts` |

Technicians may raise requests because they hold `view_maintenance` +
`manage_maintenance`; staff and managers are read-scoped to requests they raised.

### Status contract

```
REQUESTED → APPROVED → IN_PROGRESS → COMPLETED
REQUESTED / APPROVED → CANCELLED
```

- Creating a request starts at `REQUESTED`; `approved_at` is stamped when a
  request transitions to `APPROVED`; `completed_at` when it reaches `COMPLETED`.
- Skipping steps or mutating a terminal (`COMPLETED`, `CANCELLED`) request is
  `422`. Re-sending the current status is a no-op (`200`).
- Starting a record auto-transitions an `APPROVED` request to `IN_PROGRESS`.
- Priority: `LOW | MEDIUM | HIGH | URGENT` (default `MEDIUM`).

### Endpoints

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/maintenance-requests` | `view_maintenance` | Paginated list |
| POST | `/api/v1/maintenance-requests` | `view_maintenance` | Create (actor is requester) |
| GET | `/api/v1/maintenance-requests/{maintenanceRequest}` | `view_maintenance` | Detail incl. asset, requester, assignee, records |
| PUT | `/api/v1/maintenance-requests/{maintenanceRequest}` | `manage_maintenance` | Status transition, assignment, edit fields |
| GET | `/api/v1/maintenance-records` | `view_maintenance` | Paginated work orders |
| POST | `/api/v1/maintenance-records` | `manage_maintenance` | Start work on an approved request |
| GET | `/api/v1/maintenance-records/{maintenanceRecord}` | `view_maintenance` | Detail incl. technician and parts |
| PUT | `/api/v1/maintenance-records/{maintenanceRecord}` | `manage_maintenance` | Update work fields only |
| GET | `/api/v1/maintenance-records/{maintenanceRecord}/parts` | `view_maintenance` | Parts for a record |
| POST | `/api/v1/maintenance-records/{maintenanceRecord}/parts` | `manage_maintenance` | Log a part used |

### Create request (`POST /api/v1/maintenance-requests`)

```json
{ "asset_id": 12, "title": "VFD drive fan noise", "description": "High pitch whirring at 60m run. Optional.", "priority": "HIGH" }
```

- `asset_id`, `title`, `priority` required; `description` optional.
- `requested_by` always the authenticated actor; `status`/`requested_at`/`assigned_to`
  are server-controlled on create and ignored if spoofed.
- Assets soft-deleted, lost, retired, or disposed cannot be scheduled (`422`).

### Start work (`POST /api/v1/maintenance-records`)

- `maintenance_request_id` + `description` required; request must be `APPROVED`
  or `IN_PROGRESS` (`422` otherwise). Unknown request id → `404`.
- `asset_id` is always taken from the request (not client-settable);
  `technician_id` defaults to request assignee, then the acting user.
- `started_at` defaults to now; `cost` decimal(15,2); `result`/`completed_at` optional.
- Record `maintenance_request_id`/`asset_id` are immutable after creation.

### Parts

`POST /api/v1/maintenance-records/{maintenanceRecord}/parts` with `item_id`
(existing item) + `quantity` (positive integer). Adding parts is allowed only
while the request is `APPROVED`/`IN_PROGRESS`. Parts are append-only — there is
no update or delete endpoint. Active items only; deleted items → `422`
validation, inactive items → `422`.

Parts never mutate inventory balances automatically: `stock_movements` and per
warehouse balances remain the source of truth. Consuming stock for maintenance
is recorded separately via `POST /stock-movements` with `reference_type`
`maintenance` (see Inventory section).

### List filters

Requests: `search` (title/description), `status`, `priority`, `asset_id`,
`requester_id`, `assigned_to` (add `_all`), `location_id` (via asset),
`requested_from`/`requested_to`. Records: `maintenance_request_id`,
`asset_id`, `technician_id` (add `_all`). Sorting is whitelisted
(`priority`, `status`, `requested_at`, `created_at`, `updated_at` for requests;
`created_at`, `started_at`, `completed_at`, `cost` for records); invalid sort
keys fall back to default. Pagination `page`, `per_page` (max 100).

### Errors

- `401` unauthenticated; `403` missing permission or cross-user access.
- `404` unknown request/record/part parent; `405` unsupported method (there are
  no DELETE endpoints in this module).
- `422` invalid transition, invalid status/priority, ineligible asset, inactive
  or unqualified assignee/technician, parts on a closed request, deleted/inactive
  item, or validation failures.

### Development seed data

`MaintenanceSeeder` (idempotent — re-running never duplicates): demo assets
`MNT-AST-0001` (CNC Milling Machine) and `MNT-AST-0002` (Backup Generator),
four requests spanning the lifecycle (REQUESTED, APPROVED, IN_PROGRESS,
COMPLETED), two work orders and their part lines. Seeding never writes
`stock_movements`, so balances are untouched by maintenance data.

### Deferred — Phase 16B+

- Schedule / recurrences and labor hour totals.
- Consumables consumption with auto stock decrement and PDF work orders.
- Technician availability and asset reliability reports.

## Notifications — Operational Alerts (Phase 17A)

Per-user notification inbox. Rows are written **server-side only** by the
domain services when a real workflow event happens — there is **no create
endpoint**, and a client can never target an arbitrary `user_id`. The actor
performing the action is never notified about their own action.

### Endpoints

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/notifications` | authenticated (auth:sanctum) | Paginated inbox |
| GET | `/api/v1/notifications/unread-count` | authenticated | Unread count |
| GET | `/api/v1/notifications/{notification}` | authenticated | Detail |
| POST | `/api/v1/notifications/{notification}/read` | authenticated | Mark one read |
| POST | `/api/v1/notifications/read-all` | authenticated | Mark all read |

No role/permission is required beyond authentication — the inbox is scoped to
the authenticated user.

### Resource shape

```json
{
  "id": 12,
  "type": "ticket.assigned",
  "title": "Ticket assigned to you",
  "message": "Ticket TCK-ABC12345 has been assigned to you.",
  "data": { "ticket_id": 7, "ticket_number": "TCK-ABC12345" },
  "is_read": false,
  "read_at": null,
  "created_at": "2026-09-25T09:12:00.000000Z"
}
```

- `is_read` is derived from the nullable `read_at` column (`read_at !== null`).
  `read_at` is **never** client-settable — it is stamped server-side on read.
- The resource is compact: it never exposes `user_id` or a nested user.

### List filters

- `read` — `true` (read only) / `false` (unread only); anything else is ignored.
- `type` — exact notification type (`ticket.assigned`, `maintenance.approved`, …).
- Pagination: `page`, `per_page` (max 100, default 15); newest-first.

### Read semantics

- `POST /notifications/{notification}/read` is **idempotent**: reading an
  already-read row returns `200` and keeps the original `read_at`.
- `POST /notifications/read-all` marks every unread row of the current user and
  returns `{ "count": N }`; a second call returns `{ "count": 0 }`.

### Ownership & security

- Every read path enforces ownership: another user's notification is
  indistinguishable from a missing one (**404** `The requested resource was not
  found.`), so the API never leaks whether a notification exists.
- Recipients are always computed server-side from domain state. There is no
  `POST /notifications` route (`405`), and `user_id` in the query string is
  ignored.

### Event types (emitted by the domain services)

| Type | Trigger | Recipient |
| ---- | ------- | --------- |
| `ticket.assigned` | Assignee changed on a ticket | New assignee |
| `ticket.status_changed` | Ticket status changed | Requester |
| `maintenance.assigned` | Assignee changed on a maintenance request | New assignee |
| `maintenance.approved` | Request approved | Requester |
| `maintenance.completed` | Request completed | Requester |
| `asset.assigned` | Asset assigned to a user | Assignee |
| `asset.returned` | Active assignment returned | Assignment holder |

Notifications are created **inside the same transaction** as the domain
mutation — a failed or rolled-back update produces no notification. A replayed
no-op update (same status, same assignee) produces no duplicate row
(change-detection idempotence).

### Errors

- `401` unauthenticated; `404` unknown or non-owned notification;
  `405` missing routes (`POST /notifications`, update/delete).
- No `403` path exists: the inbox is auth-gated, not permission-gated.

### Development seed data

`NotificationSeeder` (idempotent — `firstOrCreate` on `user_id`+`type`+`title`)
seeds a small inbox across the `admin`, `technician`, and `staff` development
accounts. Re-running never duplicates rows.

### Deferred — Phase 17B+

- Retention/cleanup sweeps (old notifications are kept indefinitely for now).
- Push/email transport and read receipts beyond the inbox.
- Client UI consuming this inbox.

## Reports & Operational Analytics (Phase 18A)

Read-only operational aggregates across assets, inventory, helpdesk, and
maintenance. Every metric is a **server-side database aggregate** over the
existing domain tables — no rows are loaded into PHP to be counted, nothing is
cached, and no business state is touched. There is **no write verb** on a report
path (`POST`/`PUT`/`PATCH`/`DELETE` → `405`).

Reports are typed aggregate payloads, not serialized models, so `data` is shaped
by the report contract instead of an Eloquent resource.

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_reports` | super_admin, admin, manager | all `GET /reports*` |

`super_admin` bypasses permission checks (never authentication). `staff`,
`technician`, and `warehouse_staff` are **denied** — that separation is what
makes the unfiltered global aggregates safe, because the roles that can read a
report already have organization-wide visibility.

### Endpoints

| Method | Path | Query | Description |
| ------ | ---- | ----- | ----------- |
| GET | `/api/v1/reports/overview` | – | Compact cross-module snapshot |
| GET | `/api/v1/reports/assets` | `from`, `to` | Asset inventory + assignment + period activity |
| GET | `/api/v1/reports/inventory` | `from`, `to`, `limit` | Catalog, stored stock, movement activity |
| GET | `/api/v1/reports/tickets` | `from`, `to` | Ticket state, breakdowns, daily creation trend |
| GET | `/api/v1/reports/maintenance` | `from`, `to`, `limit` | Requests, costs, parts, asset ranking, lifecycle |

### Query parameters

| Name | Rules | Applies to |
| ---- | ----- | ---------- |
| `from` | `Y-m-d`, inclusive, required together with `to` | assets, inventory, tickets, maintenance |
| `to` | `Y-m-d`, inclusive, must be `>= from` | assets, inventory, tickets, maintenance |
| `limit` | integer 1–100, default `10` | `inventory` (`by_item`), `maintenance` (`by_asset`, `parts.top_items`) |

- A period spans at most **366 inclusive days**; a wider range is `422` so a
  daily series stays bounded.
- Both bounds or neither — sending only one is `422`, never a silent default.
- `overview` takes no period: it is a snapshot by definition.
- `limit` only bounds the **rankings** that scale with row count. Breakdowns over
  reference dimensions (status, priority, category, location, warehouse) are
  always complete and ignore `limit`. The applied limit is echoed back.

### Current state vs period

Detail reports keep the two strictly apart, and never mix them:

- `current` sections are a snapshot over the **whole** table and are never date
  filtered.
- `period` is `null` unless a range was requested, and contains only activity
  inside the range.

Date basis per metric (no report hardcodes a timezone; bounds are
`config('app.timezone')` = UTC calendar days):

| Report | `period` measures |
| ------ | ----------------- |
| assets | `assets.created_at`, `asset_assignments.assigned_at` / `returned_at` |
| inventory | `stock_movements.created_at` (movement count, quantity in/out) |
| tickets | `tickets.created_at` (created count, daily trend) |
| maintenance | `requested_at`, `approved_at`, `completed_at` |

### Overview

```json
{
  "success": true,
  "message": "Operational overview",
  "data": {
    "generated_at": "2026-09-26T07:41:12.000000Z",
    "assets": { "total": 3, "by_status": [{ "status": "ACTIVE", "count": 2 }, { "status": "DRAFT", "count": 1 }] },
    "inventory": { "item_count": 1, "warehouse_count": 1, "stock_quantity": 30 },
    "tickets": { "total": 4, "by_status": [{ "status": "CLOSED", "count": 1 }, { "status": "OPEN", "count": 3 }] },
    "maintenance": { "total": 4, "by_status": [{ "status": "REQUESTED", "count": 4 }] }
  }
}
```

`stock_quantity` is the signed balance of the **whole** journal, using the same
rule as the Inventory module (`SUM(STOCK_IN) − SUM(STOCK_OUT)`).

### Assets report

- `current`: `total`, `by_status`, `by_category` (`category_id`, `label`,
  `count`), `by_location` (`location_id`, `label`, `count`), `assigned_count`,
  `unassigned_count`, `without_location_count`. Soft-deleted assets are excluded;
  location is only counted when the asset is actually linked to one.
- `assignments`: `total` and `by_status` over `asset_assignments` lifecycle rows
  (`ACTIVE`, `RETURNED`, …).
- `period`: `assets_created`, `assignments_assigned`, `assignments_returned`.

### Inventory report

- `items`: `count`, `category_count` (distinct categories actually referenced).
- `warehouses`: `count` (catalog count, even when a warehouse holds nothing).
- `current_stock`: `total_quantity`, a complete `by_warehouse` breakdown
  (`warehouse_id`, `label`, `quantity`), and `by_item` — a ranking of
  `{ item_id, quantity }` limited by `limit` (echoed under `by_item.limit`).
  Warehouses/items whose signed balance is exactly `0` are omitted; a negative
  balance is reported as-is (fact, not an error).
- `period`: `movement_count`, `stock_in_total`, `stock_out_total` — positive
  quantities, with direction carried by the movement `type` (`STOCK_IN` /
  `STOCK_OUT`).

Balances are never recomputed by the report layer: every quantity comes from
`StockMovementService`, which owns the one balance rule, so a report cannot drift
from the Inventory module.

### Tickets report

- `current`: `total`, `by_status`, `by_priority`, `by_category`
  (`category_id`, `label`, `count`). Tickets without a category are counted in
  `total` and simply absent from the labelled category breakdown.
- `period`: `created` plus `trend` — one `{"date": "YYYY-MM-DD", "count": N}`
  entry per day in the range, **gap-filled with `0`** so the series can be
  charted without the client filling missing days.

### Maintenance report

- `requests`: `total`, `by_status`, `by_priority`.
- `records`: `count`, `costed_count`, `total_cost`, `average_cost`. Money is
  aggregated at decimal precision and returned as a 2-decimal string, matching
  `MaintenanceRecordResource`. `average_cost` is `null` when no record carries a
  cost (an average over zero samples is undefined); `total_cost` is `"0.00"`.
- `parts`: `usage_count` (all trace rows) and `top_items` — items ranked by total
  quantity, limited by `limit`, each `{ item_id, sku, label, quantity,
  usage_count }`. **Parts are a trace of what a work order recorded, not
  inventory consumption**: no stock movement is created by a part, so this never
  implies a stock change.
- `by_asset`: maintenance requests per asset, ranked by count, limited by
  `limit`, each `{ asset_id, asset_code, asset_name, count }` — a repeat-workload
  fact, not a risk score.
- `period`: `requested`, `approved`, `completed`.

### Interpretation and empty states

- No interpretive metric is produced: no health, risk, score, trend direction, or
  "needs attention" label is derived from the source rows.
- An empty report is **`200` with zeroes and empty arrays**, never `404` — zero
  tickets is a valid report, not a missing resource.
- `by_status` / `by_priority` use the exact stored values, so a status introduced
  later appears automatically without a report change.

### Errors

- `401` unauthenticated; `403` authenticated without `view_reports`;
  `405` any write verb on a report path; `422` invalid `from`/`to`/`limit`
  (including a single-sided or over-wide period) with the standard `errors`
  envelope; `404` is never returned by a report.

### Deferred — Phase 18B+

- A cross-module `/reports/activity` timeline: the four history tables have
  incompatible event vocabularies, and merging them would invent semantics the
  domain does not define. Phase 19A ships a separate `/command-center` recent
  activity list that keeps each source's own action vocabulary — see
  [Command Center (Phase 19A)](#command-center-phase-19a).
- Per-dimension filters (status, department, warehouse) — the existing list
  endpoints already cover scoped browsing, so reports stay deliberately global.
- Client report screens and chart rendering (Phase 18B).

## Command Center (Phase 19A)

One read-only call that answers "what is true right now, what is waiting, and
what just happened" for the authenticated caller. It is **not** a second
Reports API: reports are organization-wide and period-scoped
(`view_reports`), the Command Center is current-state and scoped to what the
caller may actually read.

### Permissions

- `GET /api/v1/command-center` — `auth:sanctum` + `permission:view_dashboard`.
  `view_dashboard` is already mapped to `super_admin`, `admin`, `manager`,
  `staff` and `warehouse_staff`; `technician` is denied. No new permission is
  seeded.
- Because those roles do **not** share one data visibility, each section is
  separately gated and **omitted entirely** when the caller may not read that
  domain. An absent key always means "not visible to you", never "no data":

  | Section / queue / activity source | Required |
  | --- | --- |
  | `snapshot.assets` | `view_assets` |
  | `snapshot.inventory` | `view_inventory` |
  | `snapshot.tickets` + `unassigned_tickets` | `view_tickets` |
  | `snapshot.maintenance` + `unassigned_maintenance_requests` | `view_maintenance` |
  | `pending_asset_assignments` | `view_asset_assignments` |
  | `snapshot.notifications` | none — always present, own inbox only |

- **Row scope, not just section scope.** The visible metrics and queue rows are
  restricted by the same rules the domain list endpoints already use:
  - tickets are organization-wide only for `manage_tickets` / `assign_tickets`,
    otherwise limited to the caller's own `requester_id`;
  - maintenance requests are organization-wide only for `manage_maintenance`,
    otherwise limited to the caller's own `requested_by`;
  - assets, inventory and asset assignments have no per-user scope, so any
    reader of those domains sees the organization-wide rows;
  - notifications are always filtered to `user_id = caller` and a read never
    marks a notification read.
- `super_admin` bypasses the permission checks as everywhere else in this API.

### Query parameters

- `limit` — optional integer `1..50` (default `10`). Bounds the queue item
  lists **and** the recent activity list. Queue `count` is always the complete
  number of waiting rows, so a truncated list never hides the size of the
  backlog.
- `from` / `to` are **prohibited** (`422`). This endpoint has no period: a
  date-filtered answer is the reports' job, so a period argument is rejected
  rather than silently ignored.
- There is no status, department, warehouse, category or per-dimension filter.
  Browsing is what the domain list endpoints are for.

### Response

```json
{
  "success": true,
  "message": "Command Center snapshot",
  "data": {
    "generated_at": "2026-09-26T09:15:00.000000Z",
    "snapshot": {
      "assets": { "total": 48, "active": 41, "in_maintenance": 5, "unassigned": 12 },
      "inventory": { "item_count": 30, "warehouse_count": 3, "stock_quantity": 812 },
      "tickets": { "total": 64, "active": 22, "unassigned": 9 },
      "maintenance": { "total": 27, "active": 11, "unassigned": 6, "awaiting_approval": 4 },
      "notifications": { "unread_count": 3 }
    },
    "queues": {
      "unassigned_tickets": {
        "count": 9,
        "limit": 10,
        "items": [
          {
            "id": 91,
            "type": "ticket",
            "reference": "TCK-7F3A9K2M",
            "title": "Projector lamp flickering",
            "status": "OPEN",
            "created_at": "2026-09-26T08:02:00.000000Z"
          }
        ]
      },
      "unassigned_maintenance_requests": { "count": 6, "limit": 10, "items": [] },
      "pending_asset_assignments": { "count": 2, "limit": 10, "items": [] }
    },
    "recent_activity": {
      "limit": 10,
      "items": [
        {
          "type": "maintenance",
          "action": "WORK_COMPLETED",
          "occurred_at": "2026-09-26T08:44:00.000000Z",
          "record_id": 18,
          "reference": "AST-000112",
          "label": "Replace projector lamp",
          "status": "COMPLETED",
          "quantity": null,
          "old_status": null,
          "new_status": null
        }
      ]
    }
  }
}
```

### Queue definitions

Each queue is defined only by stored columns — never by a severity, score or
"needs attention" label the domain does not define:

| Queue | Definition | Item `reference` |
| --- | --- | --- |
| `unassigned_tickets` | `tickets.assigned_to IS NULL` and status in `OPEN`, `IN_PROGRESS` | `ticket_number` |
| `unassigned_maintenance_requests` | `maintenance_requests.assigned_to IS NULL` and status in `REQUESTED`, `APPROVED`, `IN_PROGRESS` | `null` — a request has no server-side number |
| `pending_asset_assignments` | `asset_assignments.status = PENDING` (a handover awaiting return) | `assets.asset_code` |

Items are ordered newest first with an id tiebreak and expose only
`id`, `type`, `reference`, `title`, `status`, `created_at` — no model
serialization, no nested relations. An empty queue is `200` with `count: 0` and
`items: []`.

### Recent activity

- Four sources: `asset_histories`, `stock_movements`, `ticket_histories`,
  `maintenance_records`. Each source is read with its own `limit` and merged, so
  the newest `limit` items overall are always exact while at most
  `sources × limit` rows are read.
- **Source-native action values are preserved** and never normalized into a
  shared event vocabulary: `ASSIGNED` / `RETURNED` for assets, `STOCK_IN` /
  `STOCK_OUT` for inventory, the stored ticket action, and `WORK_STARTED` /
  `WORK_COMPLETED` for maintenance — a work record stores no `action` column, so
  the action is read off its real `completed_at` / `started_at` lifecycle
  timestamps.
- `type` is one of `asset`, `stock_movement`, `ticket`, `maintenance`. Every key
  is always present, so `null` means "this source does not record that fact".
- Ordering is `occurred_at` descending with a `(type, record_id)` tiebreak, so
  two events sharing a timestamp keep a fixed order across drivers.
- Activity obeys the same permission and row scope as the snapshot; a caller
  never sees another person's ticket or request events.
- Notifications are **not** an activity source — they are a per-user inbox, not
  an operational event stream. The unread count is the only notification fact
  reported.

### Interpretation and empty states

- No interpretive metric is produced: no health, risk, score, trend, ranking or
  "needs attention" label. `active` and `unassigned` are literal status
  predicates over stored columns.
- `snapshot.inventory.stock_quantity` is the signed journal balance from the
  inventory module, never a row count.
- `snapshot` counts current state; `recent_activity` is dated event history. The
  two are reported side by side and never summed or averaged together.
- An empty operation is `200` with zeroes, empty `items` and `count: 0`, never
  `404` — no tickets is a valid state, not a missing resource.
- `generated_at` is when the response was assembled; each metric is read in the
  same request, so a caller can tell how current the snapshot is.

### Performance

- Aggregate-only queries: every snapshot number is a `COUNT`/`SUM` (or the
  shared stock balance), never a hydrated collection.
- Queues are one `COUNT` plus one bounded `SELECT` each; activity is one bounded
  joined `SELECT` per permitted source. No per-row queries.
- Permission resolution loads the role's permission set once per request rather
  than issuing a lookup per check.
- A full snapshot for a caller with every section is **17 queries** (17 for a
  `staff` user, 11 for `warehouse_staff`) — asserted by a test budget of 20, and
  a queue-size independence test proves the count does not grow with backlog.
- `created_at` indexes were added to `asset_histories`, `ticket_histories` and
  `maintenance_records` for the newest-first ordering; `stock_movements` already
  had one. No other index, schema change or new package was needed.

### Errors

- `401` unauthenticated; `403` without `view_dashboard`; `405` any write verb on
  the path; `422` invalid `limit` or a prohibited `from` / `to` with the standard
  `errors` envelope. `404` is never returned.

### Deferred — Phase 19B+

- Client dashboard screens, charts, and a shared shell that composes the
  snapshot, queues and activity into widgets.
- Predictive or scored prioritization ("what will break next") — that would be
  an inference layer, not a read of current state.

## Audit Logs & Governance (Phase 20A)

A read-only, append-only governance trail: **who** performed **what** action on
**which resource**, **when**, with safe before/after context. Audit rows are
written exclusively by the domain services (see DR-020) — there is **no create,
update, or delete endpoint**, and no write verb exists on any audit path
(`POST`/`PUT`/`PATCH`/`DELETE` → `405`). Audit Logs describe governance events
and are deliberately not a second copy of the operational histories
(`ticket_histories`, `asset_histories`, `stock_movements`, maintenance records,
notifications).

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_audit_logs` | super_admin, admin | `GET /audit-logs`, `GET /audit-logs/{id}` |

The permission was seeded in Phase 04 and mapped to `super_admin` and `admin`
only — no seeder change was needed in Phase 20A. `manager`, `staff`,
`technician`, and `warehouse_staff` are denied (`403`); `super_admin` bypasses
the permission check but never authentication. Audit access is deliberately
separate from `view_reports`: reports are operational analytics, the audit
trail is governance data.

### Endpoints

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/audit-logs` | `view_audit_logs` | Paginated list (newest first) |
| GET | `/api/v1/audit-logs/{auditLog}` | `view_audit_logs` | Detail with actor and safe old/new values |
| POST/PUT/PATCH/DELETE | `/api/v1/audit-logs*` | – | **405 by construction** — the trail is append-only |

### Resource shape

```json
{
  "id": 42,
  "actor": { "id": 12, "name": "Admin" },
  "action": "status_changed",
  "resource": { "type": "ticket", "id": 7 },
  "description": "Ticket TCK-9F2A1B7C status changed from OPEN to IN_PROGRESS",
  "old_values": { "status": "OPEN" },
  "new_values": { "status": "IN_PROGRESS" },
  "ip_address": "127.0.0.1",
  "user_agent": "Mozilla/5.0 ...",
  "created_at": "2026-09-26T10:15:00.000000Z"
}
```

- The actor is a compact `id`/`name` object — never email, password, or tokens.
- `actor: null` means the acting user has been deleted (the FK is
  `nullOnDelete`, so the audit row survives and renders gracefully) or the
  event had no user context.
- `resource.type` uses controlled snake_case identifiers, never PHP class names.
- `old_values`/`new_values` were already scrubbed at write time; the API returns
  them as stored.

### List query parameters

```text
?page=1               current page
?per_page=25          page size (default 25, clamped to 1..100)
?actor_id=12          filter by acting user (query aid, not a scope: the whole
                      trail is visible to any caller holding the permission)
?action=created       filter by action (unknown values are ignored)
?resource_type=ticket filter by resource type (unknown values are ignored)
?resource_id=7        filter by resource id (combine with resource_type)
?from=2026-01-01      inclusive calendar-day lower bound on created_at
?to=2026-01-31        inclusive calendar-day upper bound on created_at
?sort=created_at      whitelisted: created_at | action | resource_type
                      (invalid values fall back to created_at)
?direction=desc       asc | desc (default desc — newest first, id tiebreak)
```

Date bounds are all-or-nothing (sending only one is `422`), must run forwards
(`from <= to`, else `422`), and may span at most **366 inclusive days** — the
same convention as the Reports API (DR-018).

### Actions (controlled vocabulary)

`created`, `updated`, `deleted`, `status_changed`, `assigned`, `returned`,
`registered`, `logged_in`, `logged_out` — defined as constants in
`App\Support\Audit\AuditAction`. Rows are only written by domain services for
governance-relevant mutations; reads, comments, notification reads, and
report/command-center views are never audited.

### Resource types (controlled vocabulary)

`user`, `asset`, `asset_assignment`, `item`, `item_category`, `warehouse`,
`stock_movement`, `ticket`, `maintenance_request`, `maintenance_record` —
defined in `App\Support\Audit\AuditResourceType`. Only types for modules that
actually emit audit events are listed; class names are never accepted or
exposed.

### Event coverage (what is audited)

| Domain | Audited mutations | Not audited |
| ------ | ----------------- | ----------- |
| Auth | registration, login, logout | failed logins (no misleading rows) |
| Users | create, update (incl. role change, activation), delete | reads |
| Assets | create, update, delete, assignment, return | QR lookups, reads |
| Inventory | item/category/warehouse create-update-delete, stock movements | balance reads, reports |
| Helpdesk | ticket create, status change, assignment, field updates | comments, reads |
| Maintenance | request create/update/status/assignment, record create/update | parts reads, reports |
| Notifications / Reports / Command Center | — | everything (read-only surfaces) |

Failed mutations (validation 422, conflicts 409, invalid transitions) produce
**no** audit row — the trail only records committed facts. No-op updates (same
status/assignee/values) produce **no** duplicate row. One user action produces
**exactly one** audit row; domain history tables (e.g. `ticket_histories`) are
complementary, not duplicated.

### Safe payload policy

- Never stored: passwords (values or hashes), tokens (personal access,
  bearer, refresh), session secrets, API keys, cookies, authorization headers.
- `SensitiveValueFilter` strips sensitive keys from old/new values at any
  depth before insert; only governance-safe allowlisted fields are written.
- A password *change* may be visible as a field delta's presence — never its
  value.
- Metadata is compact (`{status, priority, quantity, ...}`), never a dump of
  the request body or headers. There is no correlation-ID column in the
  schema, so none is invented.

### Immutability

The trail is append-only (DR-003/DR-020): no update or delete endpoint exists
(any write verb → `405`), the table has no `updated_at`, and the application's
only writer is `AuditLogService`. Deleting a user nulls the actor on their
audit rows (governance data survives; the API renders `actor: null`).

### Errors

- `401` unauthenticated; `403` without `view_audit_logs`; `404` unknown audit
  id; `422` invalid date range (one-sided, reversed, or wider than 366 days);
  `405` any write verb.

### Deferred — Phase 20B+

- Frontend governance workspace (list, detail, filters).
- Security-event separation (failed logins, authorization denials) if a
  compliance requirement justifies it.
- Retention/archival policy.

## Asset Categories (Phase 07)

CRUD for asset categories. Categories group assets and cannot be deleted while
they are referenced by any asset.

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_asset_categories` | super_admin, admin | `GET /asset-categories`, `GET /asset-categories/{id}` |
| `manage_asset_categories` | super_admin, admin | `POST/PUT/DELETE /asset-categories*` |

### Endpoints

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/asset-categories` | `view_asset_categories` | Paginated list with assets_count |
| POST | `/api/v1/asset-categories` | `manage_asset_categories` | Create |
| GET | `/api/v1/asset-categories/{assetCategory}` | `view_asset_categories` | Detail with assets_count |
| PUT | `/api/v1/asset-categories/{assetCategory}` | `manage_asset_categories` | Update |
| DELETE | `/api/v1/asset-categories/{assetCategory}` | `manage_asset_categories` | Delete (409 if in use) |

Create/update body: `name` (required, max 255), `code` (required, unique, max 50),
`description` (nullable, max 1000).

### List query parameters

```text
?page=1        current page
?per_page=15   page size (default 15, clamped to 1..100)
?search=IT     case-insensitive substring on name or code
?sort=name     whitelisted: name | code | created_at
?direction=asc asc | desc (default asc)
```

### List response

```json
{
  "success": true,
  "message": "Asset categories retrieved successfully",
  "data": {
    "items": [{
      "id": 1,
      "name": "Laptop",
      "code": "LAPTOP",
      "description": "Portable computers",
      "assets_count": 5,
      "created_at": "...",
      "updated_at": "..."
    }],
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 6,
      "last_page": 1
    }
  }
}
```

`assets_count` is loaded via `withCount('assets')`, never a per-row query.

### Conflict behavior

- **Category in use** (has assets): `DELETE` returns `409 Conflict` with
  `"Asset category cannot be deleted because it is still in use"`.
- No cascade deletion.

## Assets (Phase 07)

CRUD for individual assets. Assets belong to a category and optionally to a
location. The `current_user_id` field is preserved from the schema but no
assignment workflow is implemented in this phase.

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_assets` | super_admin, admin, manager, staff, technician, warehouse_staff | `GET /assets`, `GET /assets/{id}` |
| `manage_assets` | super_admin, admin | `POST/PUT/DELETE /assets*` |

### Endpoints

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/assets` | `view_assets` | Paginated list with category & location |
| POST | `/api/v1/assets` | `manage_assets` | Create |
| GET | `/api/v1/assets/{asset}` | `view_assets` | Detail with category, location, current_user |
| PUT | `/api/v1/assets/{asset}` | `manage_assets` | Update |
| DELETE | `/api/v1/assets/{asset}` | `manage_assets` | Soft delete (409 if in use) |

Create/update body:

| Field | Rule |
| ----- | ---- |
| asset_category_id | required | exists:asset_categories,id |
| asset_code | required | string | max:50 | unique |
| name | required | string | max:255 |
| description | nullable | string |
| serial_number | nullable | string | max:100 |
| status | sometimes | in:DRAFT,ACTIVE,INACTIVE,MAINTENANCE,RETIRED,LOST,DISPOSED |
| condition | sometimes | in:GOOD,FAIR,POOR,DAMAGED,FAILED |
| purchase_date | nullable | date |
| purchase_price | nullable | numeric | min:0 |
| warranty_expiry | nullable | date |
| location_id | nullable | exists:locations,id |
| current_user_id | nullable | exists:users,id |

### List query parameters

```text
?page=1             current page
?per_page=15        page size (default 15, clamped to 1..100)
?search=AST-001     case-insensitive on asset_code, name, serial_number
?asset_category_id=1 filter by category
?location_id=1       filter by location
?status=ACTIVE      filter by status
?sort=name          whitelisted: name | asset_code | created_at
?direction=asc      asc | desc (default asc)
```

### List response

```json
{
  "success": true,
  "message": "Assets retrieved successfully",
  "data": {
    "items": [{
      "id": 1,
      "asset_code": "AST-0001",
      "name": "Laptop Lenovo",
      "serial_number": "SN123",
      "status": "ACTIVE",
      "condition": "GOOD",
      "purchase_price": 15000000,
      "category": { "id": 1, "name": "Laptop", "code": "LAPTOP" },
      "location": { "id": 1, "name": "Head Office", "code": "HO" },
      "created_at": "...",
      "updated_at": "..."
    }],
    "pagination": { "current_page": 1, "per_page": 15, "total": 10, "last_page": 1 }
  }
}
```

`category` and `location` are eager-loaded (`with(['category', 'location'])`).
`current_user` is only included in the detail response (not list).

### Conflict behavior

- **Asset with assignments**: `DELETE` returns `409` with
  `"Asset cannot be deleted because it is still in use"`.
- **Asset with history records**: `DELETE` returns `409`.
- Assets are soft-deleted (ignored by default queries).

### Deferred — Phase 08+

- Asset Assignment workflow (`asset_assignments` table exists but is not
  exposed via API in this phase).
- QR code generation and scanning.

## Asset Assignment (Phase 08)

Asset assignment workflow: assign an asset to a user, list assignments, view
assignment details, and return (unassign) an asset. Assignment history is
recorded in `asset_histories` for ASSIGNED and RETURNED events.

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_asset_assignments` | super_admin, admin, manager, staff, technician, warehouse_staff | `GET /asset-assignments`, `GET /asset-assignments/{id}` |
| `manage_asset_assignments` | super_admin, admin, manager | `POST /asset-assignments`, `POST /asset-assignments/{id}/return` |

`view_asset_assignments` is available to all 6 roles. `manage_asset_assignments` is restricted to `super_admin`, `admin`, and `manager` (following the operational model where managers can assign assets within their organization).

### Endpoints

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/asset-assignments` | `view_asset_assignments` | Paginated list of assignments |
| POST | `/api/v1/asset-assignments` | `manage_asset_assignments` | Assign an asset to a user |
| GET | `/api/v1/asset-assignments/{assetAssignment}` | `view_asset_assignments` | Assignment detail with asset, user, location |
| POST | `/api/v1/asset-assignments/{assetAssignment}/return` | `manage_asset_assignments` | Return/unassign an asset |

### Create (`POST /api/v1/asset-assignments`)

Validation:

| Field | Rule |
| ----- | ---- |
| asset_id | required \| exists:assets,id |
| user_id | required \| exists:users,id |
| location_id | nullable \| exists:locations,id |
| notes | nullable \| string \| max:1000 |

Business rules enforced server-side:

- Asset must exist, not be soft-deleted, and have an assignable status
  (`DRAFT`, `ACTIVE`, or `INACTIVE`). Assets in `MAINTENANCE`, `RETIRED`,
  `LOST`, or `DISPOSED` cannot be assigned.
- User must exist and be active.
- Asset must not have an existing active assignment (status != `RETURNED`).
- Returns `409 Conflict` if the asset is already actively assigned.

On successful assignment:

- `asset_assignments.status` = `ACTIVE`, `assigned_at` = now.
- `assets.current_user_id` = assigned user ID (synchronized).
- `asset_histories` record created with `action = ASSIGNED`.

Request body:

```json
{
    "asset_id": 1,
    "user_id": 5,
    "location_id": 1,
    "notes": "Assigned to John for project X"
}
```

### List (`GET /api/v1/asset-assignments`)

Query parameters:

```
?page=1             current page
?per_page=15        page size (default 15, clamped to 1..100)
?search=AST-001     case-insensitive on asset_code, user name
?asset_id=1         filter by asset
?user_id=5          filter by assigned user
?location_id=1       filter by location
?status=ACTIVE      filter by assignment status
?sort=assigned_at   whitelisted: asset_code | user_name | assigned_at | status | created_at
?direction=asc      asc | desc (default asc)
```

Response:

```json
{
    "success": true,
    "message": "Asset assignments retrieved successfully",
    "data": {
        "items": [{
            "id": 1,
            "asset": { "id": 1, "asset_code": "AST-0001", "name": "Laptop" },
            "user": { "id": 5, "name": "John Doe", "email": "john@example.com" },
            "requested_by": { "id": 3, "name": "Jane Smith" },
            "location": { "id": 1, "name": "Head Office", "code": "HO" },
            "status": "ACTIVE",
            "assigned_at": "...",
            "returned_at": null,
            "notes": "...",
            "created_at": "...",
            "updated_at": "..."
        }],
        "pagination": { "current_page": 1, "per_page": 15, "total": 5, "last_page": 1 }
    }
}
```

### Detail (`GET /api/v1/asset-assignments/{assetAssignment}`)

Same structure as list items but with full relationship data.

### Return (`POST /api/v1/asset-assignments/{assetAssignment}/return`)

Business rules:

- Assignment must exist.
- Assignment must be currently `ACTIVE`.
- Returns `409 Conflict` if already returned.

On successful return:

- `asset_assignments.status` = `RETURNED`, `returned_at` = now.
- `assets.current_user_id` = null (cleared).
- `asset_histories` record created with `action = RETURNED`.

### Asset History

Each assignment lifecycle event creates an `asset_histories` record:

- `ASSIGNED`: recorded on successful assignment creation.
- `RETURNED`: recorded on successful return.

History records include `asset_id`, `user_id`, `action`, `old_status`, `new_status`, `notes`, `created_at`.

### Conflict behavior

- **Duplicate active assignment**: `409` — `"Asset is already actively assigned"`
- **Unassignable asset status**: `422` — `"Asset cannot be assigned: status is {status}"`
- **Inactive user**: `422` — `"User is inactive"`
- **Already returned assignment**: `409` — `"Asset assignment has already been returned"`
- **Missing assignment**: `404`

### Security

- `password`, `remember_token`, and Sanctum tokens are never returned.
- User responses include only `id`, `name`, `email`.
- Asset responses include only `id`, `asset_code`, `name`.

### Deferred

- Approval workflow (`approved_by`, `requested_by` columns exist but no approval
  endpoints in this phase).
- QR code scanning for assignment verification.

## QR Asset Identification (Phase 09)

QR-based asset identification: retrieve QR metadata for an asset and resolve
a scanned QR payload back to the asset. The QR identity uses the existing
unique `asset_code` — no new columns, no new packages.

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_assets` | super_admin, admin, manager, staff, technician, warehouse_staff | `GET /assets/{asset}/qr`, `GET /assets/qr/{identifier}` |

No new permissions introduced. QR endpoints reuse `view_assets` (already
granted to all 6 roles).

### Endpoints

| Method | Path | Permission | Description |
| ------ | ---- | ---------- | ----------- |
| GET | `/api/v1/assets/{asset}/qr` | `view_assets` | QR metadata (identifier + payload) for an asset |
| GET | `/api/v1/assets/qr/{identifier}` | `view_assets` | Resolve a scanned QR payload to an asset |

### QR Identity Strategy

- **Identifier:** the asset's existing `asset_code` (e.g. `AST-000123`).
- **Payload format:** `NEXORA:ASSET:<asset_code>` (e.g. `NEXORA:ASSET:AST-000123`).
- **Persistence:** no new column. The `asset_code` is already unique, stable,
  and non-sensitive. No migration required.
- **Immutability:** the payload never changes during the asset lifetime. It is
  independent from `current_user_id`, `location_id`, `status`, and
  `condition`. Assignment, return, relocation, and status changes do not
  affect the QR identity.
- **Uniqueness:** enforced by the existing `asset_code` unique constraint.
- **Deleted assets:** soft-deleted assets are excluded by `withoutTrashed()`;
  their QR payload does not resolve.

### Metadata (`GET /api/v1/assets/{asset}/qr`)

Response:

```json
{
    "success": true,
    "message": "Asset QR retrieved successfully",
    "data": {
        "asset_id": 1,
        "identifier": "AST-000123",
        "payload": "NEXORA:ASSET:AST-000123"
    }
}
```

### Lookup (`GET /api/v1/assets/qr/{identifier}`)

Accepts both the full payload (`NEXORA:ASSET:AST-000123`) and the bare
asset code (`AST-000123`). Returns the full `AssetResource` (with
`category`, `location`, `current_user` eager-loaded).

Response:

```json
{
    "success": true,
    "message": "Asset identified successfully",
    "data": {
        "id": 1,
        "asset_code": "AST-000123",
        "name": "Laptop Lenovo",
        "status": "ACTIVE",
        "condition": "GOOD",
        "category": { "id": 1, "name": "Laptop", "code": "LAPTOP" },
        "location": { "id": 1, "name": "Head Office", "code": "HO" },
        "current_user": { "id": 5, "name": "John Doe", "email": "john@example.com" },
        "created_at": "...",
        "updated_at": "..."
    }
}
```

### Validation

Invalid payloads return `404`:

- wrong prefix: `NEXORA:USER:123`
- malformed: `INVALID-PAYLOAD`
- nonexistent asset code

### Conflict behavior

No write operations — no conflict responses. Lookup never mutates state.

### Security

- Authenticated only (`auth:sanctum`).
- `view_assets` permission required.
- No sensitive fields in response (`password`, `remember_token`,
  `personal_access_token` all excluded by `AssetResource`).
- No public endpoint — internal authenticated identification only.

### Deferred

- Frontend QR scanner / Capacitor camera integration.
- QR rendering (frontend renders from payload string).

## User Management (Phase 06)

Administrative endpoints for managing platform users. Protected by
`view_users` (read) and `manage_users` (write); both granted to
`super_admin` and `admin` by the seeder.

### Permissions

| Permission | Granted to | Protects |
| ---------- | ---------- | -------- |
| `view_users` | super_admin, admin | `GET /users`, `GET /users/{id}` |
| `manage_users` | super_admin, admin | `POST/PUT/DELETE /users*` |

### List (`GET /api/v1/users`)

Pagination, search, filters, and whitelisted sorting.

Query parameters:

```text
?page=1            current page
?per_page=15       page size (default 15, clamped to 1..100)
?search=akmal      case-insensitive substring on name or email
?is_active=true    filter by active status
?role_id=2         filter by role
?department_id=1   filter by department
?sort=name         whitelisted: name | email | created_at
?direction=asc     asc | desc (default asc)
```

Response:

```json
{
  "success": true,
  "message": "Users retrieved successfully",
  "data": {
    "items": [{
      "id": 1,
      "name": "Example User",
      "email": "user@example.com",
      "is_active": true,
      "role": { "id": 1, "name": "Admin", "slug": "admin" },
      "department": { "id": 1, "name": "IT", "code": "IT" },
      "created_at": "...",
      "updated_at": "..."
    }],
    "pagination": {
      "current_page": 1,
      "per_page": 15,
      "total": 1,
      "last_page": 1
    }
  }
}
```

Role and department are eager-loaded (`with(['role', 'department'])`) and
serialized as flat objects — never recursive (no `department.users`,
`role.permissions`, etc.).

### Create (`POST /api/v1/users`)

Validation:

| Field | Rule |
| ----- | ---- |
| name | required \| string \| max:255 |
| email | required \| email \| max:255 \| unique:users,email |
| password | required \| string \| min:8 \| confirmed |
| role_id | required \| exists:roles,id |
| department_id | nullable \| exists:departments,id |
| is_active | boolean |

Password is hashed via Laravel's hasher; never returned. Role assignment is
guarded: a non-super-admin cannot assign the `super_admin` role.

### Update (`PUT /api/v1/users/{user}`)

Supported fields: `name`, `email`, `role_id`, `department_id`, `is_active`,
`password` (optional — only updated when present, must be `confirmed`).

Email uniqueness ignores the current user's own email. Password is hashed
when provided.

### Delete (`DELETE /api/v1/users/{user}`)

Conflict guards (return `409`):

- **Self-delete**: a user cannot delete their own account.
- **Last active super admin**: cannot be deleted — the system must always have
  a super admin.
- **In use**: user referenced by `ticket.requester_id`, `ticket_comment.user_id`,
  `stock_movement.performed_by`, `maintenance_request.requested_by`, or
  `asset_assignment.{user_id,requested_by}` returns `409`.

Department manager is **not** a blocking reference (the FK nulls the
`manager_id`); the department survives and its `manager_id` is cleared.

### Role escalation policy

- `super_admin` can assign any role, including `super_admin`.
- `admin` can assign all roles **except** `super_admin` (returns `403` with
  `Only a super admin can assign the super_admin role`).
- A user cannot change their own role or deactivate their own account.
- The last active super admin cannot be deactivated, demoted, or deleted.

### Active/inactive behavior

`is_active = false` prevents login (the auth service checks it). Admins can
toggle this via `PUT /users/{id}`; the change is immediate and verified by
attempting login after deactivation.

### Response shape

`UserManagementResource` never exposes `password`, `remember_token`, or any
 Sanctum token.