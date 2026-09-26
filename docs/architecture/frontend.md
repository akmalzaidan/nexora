# NEXORA Frontend Architecture

## Overview

NEXORA's frontend is built with **Angular 22**, **Ionic 9**, and **Capacitor 8**, consuming a Laravel 13 REST API at `/api/v1`. The architecture follows a shell-based layout with a centralized design system, reusable UI primitives, and a command palette.

## Architecture

```
Angular 22
  ├── Ionic 9 (mobile-ready UI components)
  ├── Capacitor 8 (native bridge)
  ├── Router (shell-based routing)
  ├── Reactive Forms
  ├── RxJS (minimal — signals preferred)
  └── Standalone components
```

## Application Shell

The shell (`app-shell`) provides the foundational layout:

```
┌─────────────────────────────────────────────────────┐
│  Sidebar (220–240px)  │  Main content workspace    │
│  - Logo               │  - Header (search, theme,  │
│  - Nav items          │    user avatar)            │
│  - Bottom profile     │  - Router outlet           │
│                       │                            │
│  Responsive:          │  Mobile: bottom nav        │
│  - Desktop: full      │    replaces sidebar        │
│  - Tablet: collapsible│                            │
│  - Mobile: hidden     │                            │
└─────────────────────────────────────────────────────┘
```

### Desktop (≥1024px)
- Fixed sidebar (220–240px)
- Content workspace with header
- Command palette overlay

### Tablet (768–1023px)
- Collapsible sidebar (rail mode)
- Same workspace layout

### Mobile (<768px)
- Sidebar hidden
- Bottom navigation bar (Home, Assets, +, Ops, Me)
- Full-screen page content

## Routing

Shell-protected routes wrap business pages:

```text
/login          → LoginPage
/register       → RegisterPage
───────────────────────────────
/               → Shell → router-outlet
                          ├ /home         → HomePage
                          ├ /assets       → AssetsPage
                          ├ /assets/:id   → AssetsPage
                          ├ /inventory    → InventoryPage
                          ├ /requests     → RequestsPage
                          ├ /maintenance  → MaintenancePage
                           ├ /people       → PeoplePage
                           ├ /locations    → LocationsPage
                           ├ /reports      → ReportsPage
                           ├ /settings     → SettingsPage
                           └ /profile      → ProfilePage
```

All business pages are placeholders except the **Command Center / Operational Intelligence Workspace** (implemented in Phase 19B), the **Assets Management Workspace** (implemented in Phase 13), the **Inventory Management Workspace** (implemented in Phase 14), the **Helpdesk / Request Management Workspace** (implemented in Phase 15), the **Maintenance Management Workspace** (implemented in Phase 16), the **Notifications & Operational Alerts Workspace** (implemented in Phase 17), and the **Reports & Operational Analytics Workspace** (implemented in Phase 18B); the others render minimal content indicating the module is coming later.

`/home` is the Command Center: it is intentionally **not** route-guarded by `view_dashboard` — every authenticated role lands here, and the page itself renders the Access Restricted state without requesting anything when the caller lacks the permission, so the shell and its navigation remain usable.

`/reports` is lazy-loaded through `loadComponent` and guarded by `permissionGuard` with `data: { permission: 'view_reports' }`, so a user without the permission is redirected to `/unauthorized` before any report request is made.

## Services

### ThemeService
- Manages `dark | light | system` theme modes
- Persists preference to localStorage (`nx-theme`)
- Applies `data-theme` attribute to root element
- Detects system preference via `prefers-color-scheme`

### AuthService
- Holds current user as a signal
- Manages authentication state
- Persists user to localStorage (`nx-user`)
- Provides `login()`, `logout()`, `updateUser()`

### CommandPaletteService
- Manages palette open/close state
- Holds 19 available commands (navigation + placeholder actions)
- Filters commands by query
- Tracks active (highlighted) index
- Keyboard navigation (ArrowUp/Down, Enter, Escape)

### StorageService
- Thin localStorage wrapper with JSON serialization

### NavigationService
- Tracks current/previous route paths
- Provides `navigate()`, `back()`, `canGoBack()`

### ApiService
- Centralized HTTP client base
- Handles API error responses
- Returns typed data or throws

### AssetService
- Typed client for the backend asset domain at `/api/v1`
- Assets: `listAssets()`, `getAsset()`, `createAsset()`, `updateAsset()`, `deleteAsset()`
- Asset categories: `listCategories()`
- Asset assignments: `listAssignments()`, `createAssignment()`, `returnAssignment()`
- QR identity: `getQrMetadata()`, `lookupQr()`
- Lookups: `listUsers()`, `listLocations()` (used by filters, forms, assignment picker)
- `mapAssetError()` maps HTTP failures into safe user-facing text and per-field messages for 422 validation responses
- Filters are cleaned (empty/`null`/`undefined` values dropped) before they reach the query string

### InventoryService
- Typed client for the backend inventory domain at `/api/v1`
- Item categories: `listItemCategories()`, `createItemCategory()`, `updateItemCategory()`, `deleteItemCategory()`
- Items: `listItems()`, `getItem()`, `createItem()`, `updateItem()`, `deleteItem()`
- Warehouses: `listWarehouses()`, `createWarehouse()`, `updateWarehouse()`, `deleteWarehouse()`
- Stock movements: `listStockMovements()`, `createStockMovement()`
- `mapInventoryError()` maps HTTP failures into `InventoryErrorInfo` (status, message, per-field 422 errors, and an `insufficientStock` flag for the stock-out business rejection `Insufficient stock for this movement.`)

### NxToastService
- Root-provided notification service owning a `toasts` signal
- `show()` / `success()` / `warning()` / `danger()` / `info()`
- `NxToastContainerComponent` (mounted once in the shell) renders the service state — pages never render toasts directly

### ReportService
- Typed, read-only client for the Phase 18A report API at `/api/v1/reports`
- `getOverview()`, `getAssets()`, `getInventory()`, `getTickets()`, `getMaintenance()` — all plain `GET`s through `ApiService`; there is no write path, no polling, and no export
- `ReportFilters` carries the optional `from` / `to` / `limit` query; params are cleaned so a half-filled period is never sent (`from` without `to` is dropped, not sent and rejected)
- `validateReportPeriod()` mirrors the server contract in the browser (both-or-neither, ISO `Y-m-d`, `from <= to`, at most 366 inclusive days) so an obviously invalid range never reaches the network
- `mapReportError()` maps HTTP failures into `ReportErrorInfo` (status, safe message, per-field 422 errors, and an `accessDenied` flag for 403); the backend stays authoritative and a 422 period rejection keeps its own message
- Domain types mirror the backend payloads exactly (`ReportOverview`, `AssetReport`, `InventoryReport`, `TicketReport`, `MaintenanceReport`, `ReportPeriod`, `ReportDayBucket`, `StatusBreakdown`, `PriorityBreakdown`, `CategoryCount`, `LocationCount`) — the client reshapes nothing

### CommandCenterService
- Single read-only client for the Phase 19A `GET /api/v1/command-center` endpoint (DR-019); a plain `GET` through `ApiService` with no write path, polling, websocket, or cache
- `getSnapshot(limit?)` optionally bounds the queue/activity lists; no date parameters exist because the endpoint is a current-state surface and rejects `from`/`to`
- Domain types mirror the payload exactly (`CommandCenterPayload`, `CommandCenterSnapshot`, `CommandCenterQueues`, `CommandCenterQueue`, `CommandCenterActivity`, `CommandCenterActivityItem`) — sections the caller cannot read are *omitted* by the backend, never sent as zeroes
- `mapCommandCenterError()` maps HTTP failures into `CommandCenterErrorInfo` (status, safe message, 422 field errors, `accessDenied` flag for 403) consistent with the other domain services

## Shared Components (NX Primitives)

All components use the `app-nx-` selector prefix.

| Component | Purpose |
|---|---|
| `app-nx-button` | Primary/secondary/ghost/danger/icon variants, sm/lg sizes |
| `app-nx-badge` | Status badges: active, inactive, pending, maintenance, success, warning, danger, info, neutral |
| `app-nx-input` | Form input with label, hint, error, disabled, loading states |
| `app-nx-search` | Compact search field with clear button and keyboard shortcut hint |
| `app-nx-panel` | Workspace section container with optional header/footer |
| `app-nx-loading-state` | Spinner, skeleton, or overlay loading indicator |
| `app-nx-empty-state` | Placeholder for empty lists/results |
| `app-nx-error-state` | Error placeholder with optional retry button |
| `app-nx-confirm-dialog` | Destructive action confirmation dialog |
| `app-nx-toast-container` + `NxToastService` | Toast notification system |
| `app-nx-table` | Enterprise data table with loading/error/empty states, status badges, monospace IDs |

## Design System

See [docs/architecture/design-system.md](./design-system.md).

Centralized design tokens in `src/theme/_design-tokens.scss`:
- Colors (dark + light palettes)
- Surfaces, borders, text colors
- Accent (#E8FF4F dark, #A3C400 light)
- Semantic status colors
- Spacing scale
- Typography scale
- Radii, shadows, transitions
- Z-index layers
- Layout widths

## Theme System

Three modes: **dark** (default), **light**, **system**.

- Applied via `data-theme` attribute on root element
- CSS custom properties for all theme values
- Persisted to localStorage (`nx-theme`)
- System mode detects `prefers-color-scheme`
- No flash of incorrect theme (script applies theme before render)

## Command Palette

- Keyboard shortcut: **Ctrl+K** (Windows/Linux) / **Cmd+K** (Mac)
- Toggled by `CommandPaletteService`
- Searchable across 20 commands
- Grouped: Navigation, Assets, Requests, System
- Keyboard navigation: ArrowUp/Down, Enter, Escape
- Placeholder actions for deferred business functionality

## Authentication Integration

- Reuses `AuthService` for auth state
- Login/Register pages are placeholder forms
- Shell checks `auth.user()` for user menu display
- Role-aware navigation structure (UX only — backend is authorization layer)

## API Integration Boundary

- Base URL: `/api/v1`
- Response envelope: `{ success, message, data }`
- Error envelope: `{ success: false, message, errors }`
- `ApiService` handles errors and returns typed data
- Bearer token authentication via Sanctum (placeholder in Phase 10)

## Assets Management Workspace

The `/assets` page (`AssetsPage`) is the first fully implemented business module and establishes the master-detail pattern used by future modules.

### Layout & behavior

- **Desktop (≥1024px):** side-by-side grid, master list column at `minmax(360px, 38%)`, detail column fills the rest
- **Tablet (768–1023px):** narrower master column (`minmax(280px, 32%)`)
- **Mobile (<768px):** list-then-detail workflow — the detail panel slides in over the list when an asset is selected and a back button restores the list (`detail-open` body/workspace class)
- Pagination (Prev/Next + current/last page) is driven by the backend pagination envelope

### Master list

- Debounced (300 ms) server-side search, plus status / category / location filters
- Filters combine into a single `GET /assets` request; active filters can be cleared in one action
- Loading skeleton, error state (with retry), and empty state (with a permission-gated Create Asset action)
- Rows are keyboard-accessible `<button>` elements with a selected state and an asset-condition indicator
- Selection is reflected in the URL as `?selected=<id>` so it survives reload and is shareable

### Detail

- Fetched on demand via `GET /assets/{id}`; loading, error (with retry), and empty (no selection) states
- **QR Identity panel** from `GET /assets/{id}/qr` (`identifier` + `payload`); if the QR endpoint fails, a derived identity (`NEXORA:ASSET:<code>`) is shown as a fallback
- **Properties panel** renders category, condition, serial number, description, purchase info, warranty, location, holder, created/updated timestamps
- **Assignment panel** shows the current holder and active assignment; visible only with `view_asset_assignments` or `manage_asset_assignments`
- Manual **QR lookup** in the master panel resolves an identifier/payload/code via `GET /assets/qr/{identifier}` and opens the matching asset

### Mutations (backend stays authoritative)

- **Create / Edit:** inline dialog with the full `StoreAssetRequest`/`UpdateAssetRequest` field set; client-side required checks (asset_code, name, category) before submit; 422 responses surface per-field messages; success reloads the list and opens the created asset
- **Delete:** confirmation dialog then `DELETE /assets/{id}`; 409 responses are shown as a "still in use" warning
- **Assign / Return:** assignment modal (user + optional location/notes) via `POST /asset-assignments`, return via `POST /asset-assignments/{id}/return`
- All mutation results surface through `NxToastService`

### Permission mapping

| Capability | Permission |
|---|---|
| View the page | `view_assets` (route guard) |
| Create / edit / delete | `manage_assets` |
| View assignment panel | `view_asset_assignments` |
| Assign / return | `manage_asset_assignments` |

Visibility is driven by `AuthorizationService` + the `appNxHasPermission` structural directive. All role-derived behavior maps 1:1 to backend permissions; the backend middleware remains the security boundary.

### API integration

- Every call goes through `ApiService` (base `/api/v1`) and `AssetService`, never raw `HttpClient` or mocked data
- Response envelope `{ success, message, data }`; failures route through `mapAssetError` for status-aware, field-level messaging
- No asset history endpoint exists, so no activity/history section is rendered

## Inventory Management Workspace

The `/inventory` page (`InventoryPage`, in `frontend/src/app/pages/inventory/`) is the second fully implemented business module. It reuses the Assets master-detail pattern and adds a tabbed workspace for the four inventory domains.

### Layout & behavior

- Header with title, `⌘K` command-palette hint, and a permission-gated **New Item** action
- **Summary strip**: four cards (Items, Categories, Warehouses, Movements) driven by each list's backend pagination `total` (single-source-of-truth from the API)
- **Tab bar**: `Items | Categories | Warehouses | Movements`
- **Items tab** (master-detail, mirrors Assets):
  - Master list: debounced (300 ms) server-side `sku`/name search + category and warehouse filters, pagination (15/page, `sort=sku,direction=asc`), loading skeleton, error state with retry, empty state
  - Rows are keyboard-accessible `<button>` elements showing code + category + on-hand stock; selection is mirrored to `?selected=<id>`
  - Detail: fetched on demand via `GET /items/{id}`; identifier, properties grid (category, description, unit, status, created/updated), **stock total**, and per-warehouse stock breakdown (from the detail payload's `stock.warehouses`)
- **Categories / Warehouses tabs**: paged tables with create/edit/delete (permission-gated)
- **Movements tab**: read-only journal (immutable — no edit/delete UI) with `type`, `item`, `warehouse`, `quantity`, `reference`, `notes`, `performer`, `created_at`, plus type/item/warehouse filters; newest-first is backend-authoritative
- Mobile (<768px): list-then-detail workflow — detail slides over the list with a back button (`detail-open` class), matching Assets

### Mutations (backend stays authoritative)

- **Item create/edit**: dialog with reactive form (name, `sku`, unit, category, min/max stock, description); client-side required checks first, then 422 field errors surface per field; success reloads the list and selects the created item
- **Stock in/out**: dialog with warehouse selector + live per-warehouse balance, quantity (`≥1`), optional reference/notes; `POST /stock-movements` with `type=STOCK_IN|STOCK_OUT`
  - Business rejection (`422 Insufficient stock for this movement.`) keeps the dialog open and renders an inline **"Unable to complete stock out"** error with the detail message
  - `409` conflicts surface as `toast.warning`
- **Category / warehouse create/edit and delete**: dialogs + confirm flow; `409` on delete surfaces as "still in use" warning
- **New Item / Stock In / Stock Out / Edit / Delete** visibility is driven by `AuthorizationService` + `appNxHasPermission`; view-only roles see no mutation controls
- All mutation results surface through `NxToastService`

### Permission mapping

| Capability | Permission |
|---|---|
| View the page | `view_inventory` (route guard) |
| Create / edit / delete items, categories, warehouses | `manage_inventory` |
| Create stock movements (stock in/out) | `manage_stock` |

Visibility is driven by `AuthorizationService` + the `appNxHasPermission` structural directive; the backend middleware remains the security boundary.

### API integration

- Every call goes through `ApiService` (base `/api/v1`) and `InventoryService` — no raw `HttpClient` and no mocked inventory data
- Endpoints used: `GET/POST/PUT/DELETE /item-categories`, `GET/POST/PUT/DELETE /items`, `GET/POST/PUT/DELETE /warehouses`, `GET/POST /stock-movements`
- Response envelope `{ success, message, data: { items, pagination } }`; failures route through `mapInventoryError` for status-aware, field-level messaging
- Item totals always come from `item.stock.total` (backend-computed); the frontend never derives stock quantities
- Movement `StockMovement` responses are append-only; the UI performs no optimistic stock math

### Files

```
frontend/src/app/core/services/inventory.service.ts        # API client, types, mapInventoryError
frontend/src/app/core/services/inventory.service.spec.ts   # endpoint + error-mapping tests
frontend/src/app/pages/inventory/inventory.page.ts         # signals state, reactive forms, loaders
frontend/src/app/pages/inventory/inventory.page.html       # tabs, master/detail, dialogs
frontend/src/app/pages/inventory/inventory.page.scss       # component styles (design tokens)
frontend/src/app/pages/inventory/inventory.page.spec.ts    # harness tests (16 tests)
```

## Helpdesk / Request Management Workspace

The `/requests` page (`RequestsPage`, in `frontend/src/app/pages/requests/`) is the third fully implemented business module. It replaces the placeholder with a tabbed workspace over tickets, categories, comments, and history, guarded by the `view_tickets` route permission.

### Layout & behavior

- Header with title, `⌘K` command-palette hint, and a permission-gated **New Request** action (plus **New Category** on the categories tab for agents)
- **Summary strip**: two cards (Requests, Categories) driven by each list's backend pagination `total` (single-source-of-truth from the API)
- **Tab bar**: `Requests | Categories` (Categories tab only for agents)
- **Requests tab** (master-detail, mirrors Assets/Inventory):
  - Master list: debounced (300 ms) server-side title/ticket-number search + status / priority / category filters + assignee filter (agents only) and sort (created, updated, ticket number), pagination (15/page, backend-authoritative order), loading skeleton, error state with retry, empty state
  - Rows are keyboard-accessible `<button>` elements showing badge (status/priority) + title + category + requester; selection is mirrored to `?selected=<id>`
  - Detail: fetched on demand via `GET /tickets/{id}` alongside comments and history; identifier, overview grid (requester, category, department, location, created/updated), assignment panel (agent controls), comment stream, and a status timeline
- **Categories tab** (agents only): paged table with create / edit / delete
- Mobile (<768px): list-then-detail workflow — detail slides over the list with a back button (`detail-open` class), matching Assets/Inventory

### Permissions & role model

- Anyone with `view_tickets` can open `/requests` and create a request, but only sees tickets they can access plus public comments
- **Agents** = `manage_tickets` OR `assign_tickets`; they get the internal-note toggle, the Categories tab, status/workflow controls, and assignment controls
- **Staff** see no mutation controls (no status/assign/edit, no internal notes, no Categories tab)

| Capability | Permission |
|---|---|
| View the page | `view_tickets` (route guard) |
| Create requests | `view_tickets` (backend allows) |
| Manage tickets (workflow, edit) | `manage_tickets` |
| Assign / unassign | `assign_tickets` (or `manage_tickets`) |
| Manage categories | `manage_tickets` |

Visibility is driven by `AuthorizationService` + the `appNxHasPermission` structural directive; the backend middleware remains the security boundary.

### Status workflow

- Backend-state-machine: `OPEN → IN_PROGRESS → RESOLVED → CLOSED` and `CLOSED → OPEN`; the UI derives the next transition and labels from the server value and never holds local status truth
- Status changes go through the shared confirm dialog; a `422 Invalid status transition` is surfaced as a warning toast and the detail + history are reloaded to re-sync with the backend
- Assignment uses a user-select dialog (`GET /users?is_active=true&per_page=100`, agents only) and an unassign confirm; success reloads detail, history, and list

### Comments & history

- Comments: `GET/POST /tickets/{id}/comments`; agents can mark a comment **internal** (`is_internal`) — staff never see the toggle; thread reloads after posting
- History: `GET /tickets/{id}/history`, oldest-first, rendered as a timeline; the backend order is preserved (never reversed client-side)

### API integration

- Every call goes through `ApiService` (base `/api/v1`) and `TicketService` — no raw `HttpClient` and no mocked ticket data
- Endpoints used: `GET/POST /ticket-categories` + `GET/PUT/DELETE /ticket-categories/{id}`, `GET/POST /tickets` + `GET/PUT /tickets/{id}`, `GET/POST /tickets/{id}/comments`, `GET /tickets/{id}/history`
- Response envelope `{ success, message, data: { items, pagination } }`; failures route through `mapTicketError` for status-aware mapping: 403 → `accessDenied`, 404, 409, 422 field errors, 422 invalid status transition → `invalidTransition`, 429, offline, 5xx
- Status, assignee, totals, and history values are always rendered from backend responses — the frontend performs no optimistic updates

### Files

```
frontend/src/app/core/services/ticket.service.ts        # API client, types, mapTicketError
frontend/src/app/core/services/ticket.service.spec.ts   # endpoint + error-mapping tests (20 tests)
frontend/src/app/pages/requests/requests.page.ts        # signals state, reactive forms, loaders
frontend/src/app/pages/requests/requests.page.html      # tabs, master/detail, dialogs
frontend/src/app/pages/requests/requests.page.scss      # component styles (design tokens)
frontend/src/app/pages/requests/requests.page.spec.ts   # harness tests (19 tests)
```

The command palette's **Create request** command is gated on `view_tickets` to match the backend's create policy.

## Maintenance Management Workspace

The `/maintenance` page (`MaintenancePage`, in `frontend/src/app/pages/maintenance/`) is the fourth fully implemented business module. It reuses the master-detail pattern over the Phase 16A maintenance API (requests → work records → parts), guarded by the `view_maintenance` route permission. The route, sidebar entry, quick action, and command-palette command were already wired in earlier phases.

### Layout & behavior

- Header with title, `⌘K` command-palette hint, and a permission-gated **New Request** action (`view_maintenance` — only `super_admin`/`admin`/`technician` map to it)
- **Summary strip**: three cards (Requests, In Progress, Completed) — counts come only from the backend pagination `total` of `GET /maintenance-requests` (filters per card) and `GET /maintenance-records?per_page=1`; never derived client-side
- Master list: debounced (300 ms) server-side search + status / priority / asset / assignee / location / date-range filters and a sort selection (priority, status, requested_at, created_at, updated_at — closed whitelist), pagination, loading skeleton, error state with retry, empty state, `clearFilters` + active-filter count, and an `=0` highlight on card counts
- Rows are keyboard-accessible `<button>` elements showing badge (status/priority) + title + asset/requester; selection is mirrored to `?selected=<id>` (survives reload, shareable)
- Detail: fetched on demand via `GET /maintenance-requests/{id}`; loading, back-to-list, `ACCESS RESTRICTED` (403 — backend-resolved visibility), error-with-retry, and select-prompt states; description panel, Overview grid (asset, requester, priority, status, assigned to, requested/approved/completed timestamps — no `location` field exists in the resource, so it is omitted), and the **Work Records** panel
- Mobile (<768px): list-then-detail workflow — detail slides over the list with a back button (`detail-open` class), matching Assets/Inventory/Requests
- A workflow hint banner reflects the backend state machine (`REQUESTED → APPROVED → IN_PROGRESS → COMPLETED`; `CANCELLED` from REQUESTED/APPROVED) and never holds local status truth

### Workflow (backend stays authoritative)

- Actions rendered per status by the backend-value: **Approve** / **Start Work** (POST `/api/v1/maintenance-records`) / **Complete** / **Cancel** / **Reassign** — all gated on `manage_maintenance` via `appNxHasPermission`
- Non-terminal status transitions go through `PUT /maintenance-requests/{id}` with only `{ status }`
- **Start Work** is `POST /maintenance-records` with `{ maintenance_request_id, description, technician_id? }` — **never** a `status=IN_PROGRESS` push, no fake start endpoint
- A `422` rejected transition (e.g. `Invalid maintenance status transition`, `maintenance work can only be started`) surfaces as a warning toast and the detail + list are reloaded to re-sync
- Assignee picker only lists users who hold `manage_maintenance` (per `roleHasPermission`) — matches the backend's technician scope

### Work records & parts

- **Records**: each shows description, technician, and started/completed timestamps; appending a record does not push request status
- **Record edit**: PUT work fields only (`description`, `started_at`, `completed_at`, `result`, `cost`, `technician_id`); request/asset FKs are immutable with no UI to change them
- **Parts**: read-only table per record (`GET /maintenance-records/{id}/parts`, up to 100/page) plus an append-only **Add Part** dialog (`POST .../parts`); quantity is integer `≥ 1` (client-side validated)
- Recording a part is **trace-only**: it never deducts stock and never touches `/inventory` or `/stock-movements` — the part's stock deduction is a backend concern, and the UI states "no stock is deducted"

### Permission mapping

| Capability | Permission |
|---|---|
| View the page | `view_maintenance` (route guard) |
| Create requests | `view_maintenance` (backend allows) |
| Full workspace (view all requests, manager role only) | backend-scoped listing |
| Workflow / assign / record / part actions | `manage_maintenance` |
| Summary strip visibility | `view_maintenance` |

Create-picker assets filter to live, non-retired/non-lost/non-disposed assets from `GET /assets?is_active=1`; visibility is driven by `AuthorizationService` + the `appNxHasPermission` structural directive, and the backend middleware remains the security boundary.

### API integration

- Every call goes through `ApiService` (base `/api/v1`) and `MaintenanceService` — no raw `HttpClient` and no mocked maintenance data
- Endpoints used: `GET/POST /maintenance-requests` + `GET/PUT /maintenance-requests/{id}`, `GET/POST /maintenance-records` + `GET/PUT /maintenance-records/{id}`, `GET/POST /maintenance-records/{id}/parts`, plus `GET /assets`, `GET /users`, `GET /locations`, `GET /inventory-items` for pickers
- Response envelope `{ success, message, data: { items, pagination } }`; failures route through `mapMaintenanceError` for status-aware mapping: 403 → `accessDenied`, 404 → `notFound`, 409, 422 field errors, 422 workflow rejection → `workflowRejected`, 429, offline, 5xx
- Status, counts, timestamps, and totals are always rendered from backend responses — the frontend performs no optimistic updates and no fake start/stock math

### Files

```
frontend/src/app/core/services/maintenance.service.ts        # API client, types, mapMaintenanceError
frontend/src/app/core/services/maintenance.service.spec.ts   # endpoint + error-mapping tests (13 tests)
frontend/src/app/pages/maintenance/maintenance.page.ts       # signals state, reactive forms, loaders
frontend/src/app/pages/maintenance/maintenance.page.html     # master/detail, records, parts, dialogs
frontend/src/app/pages/maintenance/maintenance.page.scss     # component styles (design tokens)
frontend/src/app/pages/maintenance/maintenance.page.spec.ts  # harness tests (19 tests)
```

`AuthorizationService` now exports `roleHasPermission(roleSlug, permission)` as a pure helper shared by `hasPermission()` and the maintenance assignee filter.

## Reports & Operational Analytics Workspace

The `/reports` page (`ReportsPage`, in `frontend/src/app/pages/reports/`) is the sixth fully implemented business module, over the Phase 18A read-only report API. It is a **reporting surface, not a decision surface**: a report is a fact, so the workspace renders exactly what the API returns and never derives a metric of its own. No export, no scheduling, no email, no live updates, and no client-side chart library (the visualisations are CSS + semantic tables).

### Layout & behavior

- Header: title, fixed subtitle, a compact context chip (**Current state** or the active period), `Refresh` (active report only, disabled while in flight) and a `Period` toggle
- Five tabs — **Overview / Assets / Inventory / Requests / Maintenance** — as a real `role="tablist"` with `aria-selected`, roving `tabindex`, and Arrow/Home/End keyboard movement; the strip scrolls horizontally on narrow screens
- Reports are **lazy per tab**: only the active report is fetched, a tab is not re-fetched while its data and period are unchanged, and every tab owns its own loading / error / data state so one failure never disturbs another
- Each tab renders at most 4–6 metric cards (label + value), then breakdown panels; breakdowns use `ReportBarListComponent`, a semantic table whose count cell carries a proportional bar sized against the largest row in that list
- A report whose facts are all zero/empty renders a proper **empty** state, not an error
- Requested work is never duplicated: a repeated request for the period already in flight is dropped, while a period *changed* mid-flight is queued and replayed once the running request settles, so the screen can never display a range it did not ask for

### Current state vs. period

- The API is explicit about the distinction and so is the UI: `current` sections are an undated snapshot and `period` is `null` unless a range was requested. The two are always rendered as separate sections, and a `period`-less report shows a "Current state only — choose a period…" note instead of an empty panel
- The period control is **disabled on the Overview tab** and the header chip reads "Current state" there, because `GET /reports/overview` is an undated snapshot by contract and accepts no period
- One period is shared by the four detail reports and preserved across tab switches; applying or clearing it re-fetches only the report on screen
- Date validation runs in the browser first (`validateReportPeriod`) and a server `422` is still surfaced with its own message

### Rendering rules (facts, not verdicts)

- Every count, quantity, and total is rendered as returned. The client computes no totals, rates, ratios, deltas, or health/risk/performance scores, and never invents a status, severity, or priority
- Breakdown rows keep the backend's own vocabulary: values are humanized for display only (`in_progress` → "In Progress") while the raw value stays in the row key and `data-status`
- Breakdown order is the backend's deterministic order; the client does not re-sort, so a table can never disagree with the API
- Money is displayed as the decimal string the API produced — no parsing, no re-formatting, and no currency symbol the API does not supply. `average_cost: null` renders as `—` with an explicit "missing data, not a cost of zero" note; a `0.00` total stays `0.00`
- Inventory current stock is presented as the journal balance the backend owns (DR-014); maintenance parts are presented as **Parts recorded** (a trace), with a note that recording a part does not move stock (DR-016)
- `ReportTrendComponent` plots the backend's gap-filled daily ticket series as CSS bars with `role="img"` and a structural `aria-label`; every value is also available as a real table behind a "View as table" disclosure. It draws no trend line, moving average, or direction verdict

### Access, errors & integration

- Guarded by `permissionGuard` + `view_reports` (super_admin, admin, manager — mirroring `RolePermissionSeeder`); no permission is invented
- Per-tab error handling via `NxErrorStateComponent` with **Retry**; a `403` renders the shared `UnauthorizedComponent` access-restricted state instead of a generic failure
- Reachable from the sidebar (`Reports`, permission-filtered) and the command palette (`View reports`); the bottom navigation is intentionally left at five items
- Dark/light/system theming comes from the existing `--nx-*` tokens; no new palette, font, or dependency was introduced

### Files

```
frontend/src/app/core/services/report.service.ts                    # API client, report types, period validation, error mapping
frontend/src/app/core/services/report.service.spec.ts               # endpoint, param-cleaning, validation + error-mapping tests (24 tests)
frontend/src/app/pages/reports/reports.page.ts                       # per-tab state machine, period handling, presentation helpers
frontend/src/app/pages/reports/reports.page.html                     # header, period control, tablist, five report bodies
frontend/src/app/pages/reports/reports.page.scss                     # component styles (design tokens)
frontend/src/app/pages/reports/reports.page.spec.ts                  # harness tests (20 tests)
frontend/src/app/pages/reports/components/report-bar-list.component.ts   # accessible breakdown table with proportional bars
frontend/src/app/pages/reports/components/report-trend.component.ts    # dependency-free daily series + table fallback
```

## Notifications & Operational Alerts Workspace

The `/notifications` page (`NotificationsPage`, in `frontend/src/app/pages/notifications/`) is the fifth fully implemented business module, over the Phase 17A notifications API. Notifications are created **server-side only** by domain mutations; the frontend is a read/mark-read workspace — there is no create/update/delete UI and no create endpoint to call.

### Shell integration (bell + unread badge)

- **Bell** lives in the shell header (`app-notification-bell`), an icon-only `<button>` with `aria-label`, `aria-expanded`, `aria-controls`, and keyboard activation; Escape and outside-click close the panel
- **Unread badge** comes exclusively from `GET /api/v1/notifications/unread-count` (never derived from a page of list data); `0` renders no badge, larger counts render capped at `99+`
- The bell communicates unread state subtly (no shaking/pulsing animation); the badge uses the existing accent token
- **Preview panel** (`NotificationPanelComponent`) mounts only while open; opening refreshes the latest 6 notifications + the unread count. Rows are compact buttons; the footer offers **Mark all as read** (only when unread exist) and **View all notifications** → `/notifications`
- Clicking an unread row marks it read (`POST /notifications/{id}/read`) and then opens the related record; opening the panel alone never consumes notifications

### Inbox (`/notifications`)

- Master/detail workspace (desktop split; mobile list-then-detail with a back button) over `GET /api/v1/notifications`
- **Read filter** (`All / Unread / Read`) maps to the backend `read` param (`true`/`false`, omitted for All); **type filter** sends the exact backend type (`ticket.assigned`, …) grouped as Ticket / Maintenance / Asset
- Backend-driven pagination (15/page, Prev/Next + `Page x of y`); no fake infinite scroll
- Detail shows title, humanized type, message, timestamp, read state, payload facts (backend keys only: `ticket_number`, `status`, ids), and **Open related record** when a supported id exists — otherwise no action is offered
- **Mark all as read** appears only when the backend count is positive; failures keep the count intact and surface a warning toast (no optimistic zeroing)

### Deep-link mapping

`NotificationNavigationService` is the single mapper from notification type + payload → workspace route (no route logic in templates). Ticket types deep-link to `/requests?selected={ticket_id}`, maintenance types to `/maintenance?selected={maintenance_request_id}`, asset types to `/assets?selected={asset_id}` — matching each workspace's existing `?selected=` URL state. Payload ids are **not** treated as proof of access: the destination workspace handles 403 (ACCESS RESTRICTED) via existing backend authorization.

### Types & resilience

- Supported types: `ticket.assigned`, `ticket.status_changed`, `maintenance.assigned`, `maintenance.approved`, `maintenance.completed`, `asset.assigned`, `asset.returned` (labels/icons per type; unknown future types render gracefully as `Alert` with title/message/timestamp and no deep link)
- Relative timestamps (`Just now`, `5 mins ago`, …) fall back to an absolute date after a week; no date library added
- Errors route through `mapNotificationError` (401/403/404/409/422/429/offline/5xx) consistent with the other domain services

### Session & refresh strategy

- **Session isolation:** `NotificationInboxService.reset()` clears cached state when `auth.isAuthenticated()` flips false (shell effect); a new login fetches a fresh unread count, so no state crosses users
- **Non-blocking boot:** startup fetches the unread count but never blocks app load on its failure
- **No polling / no websocket:** refresh happens on app bootstrap, bell open, inbox open, and after mutations. There is deliberately no continuous polling timer
- Every call goes through `ApiService` (base `/api/v1`) via `NotificationService` — no raw `HttpClient`, no mock API, no push/queue infrastructure

### Permission mapping

| Capability | Gate |
|---|---|
| View the page / bell | authenticated only (`authGuard`; no RBAC permission — Phase 17A uses ownership) |
| Mark read / mark all read | backend ownership (404 for another user's notification) |

### Files

```
frontend/src/app/core/services/notification.service.ts                              # API client + inbox state + type meta + mapNotificationError
frontend/src/app/core/services/notification.service.spec.ts                        # endpoint, filter-cleaning, error-mapping tests
frontend/src/app/core/services/notification-navigation.service.ts                  # type+payload → deep-link mapper
frontend/src/app/core/services/notification-navigation.service.spec.ts             # mapper tests (all types + unknown + bad payloads)
frontend/src/app/layout/shell/notification-panel/notification-panel.component.*    # bell preview panel (+ spec)
frontend/src/app/layout/shell/shell.component.ts / .html                           # bell button, badge, session-isolation effect (+ spec)
frontend/src/app/pages/notifications/notifications.page.*                          # inbox workspace (+ spec)
```

The command palette includes **View notifications** (authenticated users) and **Mark all notifications as read**; existing palette commands are unchanged.

## Command Center / Operational Intelligence Workspace

The `/home` page (`HomePage`, in `frontend/src/app/pages/home/`) is the **operational cockpit** (Phase 19B) over the Phase 19A `GET /api/v1/command-center` endpoint (DR-019). It presents **current operational state and actionable queues**, not historical reporting — Reports (Phase 18) remains the analytical surface.

### Route & access

- `/home` stays the shell's landing page for **every** authenticated role, so the route is deliberately **not** wrapped in `permissionGuard`. A user without `view_dashboard` (e.g. `technician`) lands here, sees the **ACCESS RESTRICTED** state, the page makes **no request at all**, and the shell plus its navigation stay fully usable — permission links (Requests / Inventory / Assets, filtered by what the role holds) offer somewhere to go instead of dead-ending back to the same page via `/unauthorized`
- Access is computed from `AuthorizationService.hasPermission('view_dashboard')`; the backend remains the final authorization boundary and its 403 is surfaced through the same restricted state

### API integration

- Exactly **one API call** loads the page: `GET /api/v1/command-center` through `ApiService` via `CommandCenterService.getSnapshot()` — no `limit` is sent, so the backend default applies and is echoed back on every queue
- The workspace never calls `/assets`, `/inventory`, `/tickets`, `/maintenance-requests`, or `/reports` to rebuild a figure the snapshot already returns, and no polling, websocket, SSE, cache, or export exists anywhere in the page
- Domain types (`CommandCenterPayload`, `CommandCenterSnapshot`, `CommandCenterQueues`, `CommandCenterActivity`, `CommandCenterActivityItem`, `CommandCenterQueue`, `CommandCenterQueueItem`) mirror the backend payload exactly — nothing is invented, renamed, or derived
- `mapCommandCenterError()` maps failures status-aware: 401 session expired, 403 `accessDenied`, 404, 422 field errors, 429, offline, 5xx

### Sections

- **Snapshot** (`aria-label="Current operational state"`): one compact group per domain the API returned — Assets, Inventory, Requests (`tickets`), Maintenance, plus the caller's own Notifications unread count. A domain the caller may not read is **omitted by the backend**, not sent as zero, and the page renders exactly that: the group disappears, it is never shown as `0`
- **Operational queues**: `unassigned_tickets` → "Unassigned requests", `unassigned_maintenance_requests` → "Unassigned maintenance", `pending_asset_assignments` → "Pending asset handovers" — in the documented presentation order, only when present. Each queue shows the complete `count` plus its newest rows; when the list is truncated by `limit`, a "Showing X of Y" note keeps the backlog size honest. A waiting queue with no rows in its page renders "Waiting, but no details are loaded", never a fabricated row
- **Recent activity**: `recent_activity` rendered in the API's own order (no client re-sort), with source icons, `action` labels humanized for display only (raw value kept in `data-action`), reference, status transition (`old → new` when the source stored both ends), quantity, and machine-readable `<time datetime>`
- **Quick actions**: every earlier-phase Home action is preserved (Create asset, Scan asset, Assign asset, Create request, Assets, Inventory, Requests, Maintenance, Reports, People, Locations, Notifications, Toggle theme). Mutations stay gated on the real capability (`manage_assets`, `assign_assets`, `manage_tickets`) and navigation on the view permission, via `AuthorizationService` filtering — a read-only role is never offered an action the backend would reject

### Fact-only rendering & navigation

- Counts are displayed exactly as returned (grouped for reading only). No total, rate, delta, trend, score, ranking, severity, or "needs attention" label is computed anywhere, and no activity row is a link: `record_id` is an *event row* id that addresses no workspace selection, so guessing a destination would be wrong — activity is read-only
- Queue rows are buttons only when the API addressed a real record: `ticket` → `/requests?selected={id}`, `maintenance_request` → `/maintenance?selected={id}` — the same `?selected=` URL state those workspaces already read. An `asset_assignment` row carries the *assignment* id (not an asset id), so it renders as a read-only reference rather than navigating to the wrong record
- Ids are not authorization: the destination route guard + workspace handle 403 through their own Access Restricted state

### State handling

- **Loading**: first load renders skeleton blocks shaped like the real layout (strip + two columns) inside `NxLoadingStateComponent`; a refresh **keeps the current snapshot on screen** and only marks the header as `Refreshing…` — the workspace never blanks
- **Errors**: a failed first load shows `NxErrorStateComponent` with Retry (403 shows the restricted state with no retry that cannot succeed); a failed *refresh* keeps the stale snapshot visible with a "figures below are the last ones loaded" banner + Retry, instead of replacing a working view with an error page
- **Empty**: when every count, queue, and event is genuinely zero, a proper empty state renders — distinct from being denied, and never fake sample numbers
- A single atomic `role="status"` live region announces state in words (loading → refreshing → error → "as of {timestamp}"); the shell's notification bell remains the notifications surface, the palette keeps Ctrl/Cmd+K (**"Go to Command Center"**), and the page renders no toolbar of its own

### Responsive & theme

- Desktop ≥1024px: snapshot strip, two-column queues/activity, quick-action row. Tablet 768–1023px: stacked snapshot, two columns retained. Mobile <768px: single column in order **Header → Snapshot → Queues → Activity → Quick actions**, with quick actions always reachable without horizontal scrolling
- Everything is styled from the existing `--nx-*` tokens (dark/light/system) with the acid accent as micro-accent only; Inter for labels, `nx-mono` for numbers, references, and timestamps. No new palette, font, chart library, or dependency was added

### Files

```
frontend/src/app/core/services/command-center.service.ts        # typed payload, mapCommandCenterError, getSnapshot()
frontend/src/app/core/services/command-center.service.spec.ts   # endpoint, method/URL, param + error mapping (22 tests)
frontend/src/app/pages/home/home.page.ts                        # signals state, snapshot/queue/activity presentation, navigation resolution
frontend/src/app/pages/home/home.page.html                      # restricted / skeleton / error / empty / workspace rendering
frontend/src/app/pages/home/home.page.scss                      # component styles (design tokens, reduced-motion aware)
frontend/src/app/pages/home/home.page.spec.ts                   # 36 tests: data fidelity, queues, activity, refresh, errors, permissions, a11y
```

## Role-Aware Navigation

Sidebar structure supports future permission-based visibility:
- Main nav items (Home, Assets, Inventory, Requests, Maintenance, Organization, Settings)
- Bottom items (Profile)
- Organization group with children (People, Locations)
- Visibility controlled by future backend permissions, not hardcoded

## Future Module Expansion

The following patterns are established for future phases:

- **Master-detail layout** (`app-nx-master-detail`): reusable split-pane pattern for Assets, Inventory, Requests, Maintenance, Users (baked into AssetsPage; reusable component extraction is reserved for a later refactor)
- **NX primitives**: all new modules use `app-nx-*` components
- **Routing**: new modules add routes under shell protection
- **Services**: new business services follow existing patterns

## Style Conventions

- Standalone components with `app-` selector prefix
- Signal inputs/outputs preferred
- `inject()` for dependency injection
- SCSS with design tokens via `@use`
- Minimal RxJS — signals for state management
- Strict TypeScript, no `any` in business logic
