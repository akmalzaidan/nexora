# NEXORA Security

How the NEXORA API authenticates, authorizes, and protects data — and what an
operator must do before running it anywhere that is not a developer laptop.

Scope: `backend/` (Laravel 13 API) and `frontend/` (Angular 22 / Ionic client).
Architecture context lives in [`docs/architecture/README.md`](../architecture/README.md);
the API contract is in [`docs/api/README.md`](../api/README.md).

---

## 1. Security model in one paragraph

NEXORA is a single-tenant internal operations platform. There is no
organisation column and no tenant isolation layer; **one deployment serves one
company**, and the trust boundary is the login. Inside that boundary,
authorization is a role → permission matrix, with resource-level scoping layered
on top for the domains where a broad permission is not enough. The frontend is
untrusted: every permission check that matters is repeated on the server.

---

## 2. Authentication

| Concern | Implementation |
| --- | --- |
| Transport credential | Laravel Sanctum personal access token |
| Header | `Authorization: Bearer <token>` |
| Token name | `nexora-api` (`backend/app/Services/AuthService.php`) |
| Guard | `auth:sanctum`, applied to every `/api/v1` route except `health`, `auth/register`, `auth/login` |
| Password hashing | bcrypt via `BCRYPT_ROUNDS=12` |
| Login rate limit | 5 / minute, keyed on `email` + client IP |
| Register rate limit | 3 / minute, keyed on client IP |
| Token expiry | **None** — see §2.1; deactivation is the kill switch |
| CSRF | Not required for the bearer-token API; `sanctum/csrf-cookie` is still in `cors.paths` for the cookie-based path |

Rules enforced in code and covered by tests:

- **No user enumeration.** Unknown email, wrong password and inactive account all
  raise the same `InvalidCredentialsException` → `401 Invalid credentials`.
- **Deactivation is a kill switch.** `AuthService::login` refuses an inactive
  account, and `App\Http\Middleware\Authenticate` re-checks `is_active` on every
  authenticated request. A token minted before the account was deactivated is
  rejected with the same generic 401 **and deleted**, so access ends on the next
  request and reactivating the user does not resurrect the old session. Sanctum
  tokens themselves never expire, so without that check a deactivation would not
  have ended anything.
- **Role is server-assigned.** `register` hard-codes the `staff` role; the
  request body cannot set `role_id`, `is_active`, or `permissions`.
- **Logout revokes only the current token** (`$user->currentAccessToken()->delete()`)
  and then calls `Auth::forgetGuards()` so the resolved user is not reused for
  the rest of the process. Other sessions stay logged in.
- **Tokens are never logged.** See §7.

### 2.1 Known limitation — token storage and lifetime (accepted risk)

The Angular client stores the Sanctum token in `localStorage`
(`frontend/src/app/core/services/auth.service.ts`). This is the established
architecture for this project, not a Phase 21A change. It means a successful
XSS injection is a full account compromise. Mitigations in place: no
`innerHTML` rendering of API data, no third-party scripts, and a strict CORS
allowlist (§5) so an attacker's page cannot read the API cross-origin. The
durable fix is an `HttpOnly`, `SameSite` cookie plus the `sanctum/csrf-cookie`
route — that is a Phase 21B+ architecture change, not a hardening patch.

Separately, tokens have **no expiry** (`sanctum.expiration` is `null`). The
mitigation today is the deactivation kill switch above: an admin can end a
session immediately, but a *stolen* token of a still-active user is valid
indefinitely. Set `SANCTUM_EXPIRATION` to a number of minutes and add a refresh
flow if the threat model requires it.

---

## 3. Authorization

Two layers, and only the second one is authoritative.

### 3.1 Route-level permission matrix

`backend/routes/api.php` groups routes under `permission:<slug>` middleware.
The full slug list is seeded in `backend/database/seeders/PermissionSeeder.php`
and role mappings in `RolePermissionSeeder.php`. Representative groups:

| Permission | Routes |
| --- | --- |
| `view_assets` | `GET assets`, `GET assets/{asset}`, `GET assets/{asset}/qr`, `GET assets/qr/{identifier}` |
| `manage_assets` | `POST/PUT/DELETE assets` |
| `assign_assets` | `POST asset-assignments`, `POST asset-assignments/{id}/return` |
| `view_reports` | `GET reports/*` (read-only aggregates) |
| `view_audit_logs` | `GET audit-logs` (Phase 20B) |

`permission` middleware → 403 when the effective role lacks the slug. The
frontend `authGuard` / `permissionGuard` and the permission-filtered sidebar and
command palette exist so users do not see dead links; **they are UX, not
security.** A user who forges a request in devtools gets a 403 from the server.

### 3.2 Resource-level scoping

Broad permissions are narrowed per record where the domain demands it:

- **Helpdesk** — a requester sees only the tickets they raised: `TicketService::paginate()`
  adds `where('requester_id', $actor->id)` for every non-agent. An *agent* (any
  ticket-management permission) sees the full queue, because a shared queue is
  the point of the helpdesk. **Internal notes are never serialized to a
  requester** — the comment query filters `is_internal`, and `is_internal` on
  create is gated by `isAgent()`.
- **Notifications** — reads and marks are filtered by the authenticated user's
  own id; `read_at` is only ever set on a notification the caller owns.
- **Reports / Command Center** — aggregate data is only reachable through
  `view_reports`, which is granted to manager, admin and super_admin. Staff and
  technician roles have no report route, so the client never receives a global
  aggregate it should not have.
- **Command Center** — read-only. Every write verb returns 405 (locked by
  `CommandCenterReadOnlyApiTest`), and the response stays under a 20-query budget.
- **User management** — a user referenced by a RESTRICT foreign key (an
  assignment, a raised ticket, a comment, a stock movement, a raised maintenance
  request) cannot be deleted; the API returns 409 rather than letting the
  database raise an integrity error.

### 3.3 The `super_admin` bypass

`User::hasPermission()` short-circuits to `true` for the `super_admin` role.
This is applied consistently in `TicketService::isAgent()`,
`MaintenanceRequestService::canManage()`, the command-center scope and the
report scope, so super_admin does not have a different mental model per domain.
The bypass is checked at every permission gate rather than granted once at
login, so revoking it takes effect on the next request.

Two escape hatches are hard-blocked in `UserManagementService`, all 403:

- an admin may never assign the `super_admin` role (only a super_admin may);
- nobody may deactivate their own account;
- the **last active super_admin** can be neither deactivated, re-roled nor
  deleted, so the deployment can never be locked out of its own user directory.

---

## 4. Input handling

- **No mass assignment.** No controller, request or service uses `$request->all()`,
  `forceFill()` or an unrestricted `fill()`. Every model declares an explicit
  `$fillable` allowlist.
- **Server-owned identity.** `user_id`, `requested_by`, `assigned_to`,
  `performed_by`, `approved_by` and every `*_at` timestamp come from
  `auth()->user()` or the service, never from the request body. Permission
  checks therefore cannot be escalated by a crafted payload.
- **Form Requests own validation.** `backend/app/Http/Requests/**` declares the
  rules; controllers pass `$request->validated()` to services. Domain rules that
  a validator cannot express (workflow transitions, stock sufficiency, assignee
  eligibility) live in the service as explicit guards returning 409/422.
- **Conservative mass updates.** `update()` passes `$request->validated()` to
  `fill()`; fields absent from the payload are left untouched.

---

## 5. CORS

`backend/config/cors.php` is a narrow allowlist, enforced by
`tests/Feature/Http/CorsConfigurationTest.php`:

- `allowed_origins` — explicit origins from `CORS_ALLOWED_ORIGINS`. **Never `*`.**
  A credentialed request with `*` is rejected by browsers anyway, and a
  non-credentialed `*` would let any site read the API with a stolen token.
- `allowed_methods` — `GET, HEAD, POST, PUT, DELETE, OPTIONS`. The v1 API has no
  `PATCH` or `TRACE` routes, so advertising them tells browsers more than they
  need to know.
- `allowed_headers` — explicit: `Accept`, `Accept-Language`, `Authorization`,
  `Content-Language`, `Content-Type`, `Origin`, `X-Requested-With`,
  `X-XSRF-TOKEN`. **Never `*`.**
- `supports_credentials` — `false`. The API authenticates with a header, never a
  cookie, so credentialed cross-origin requests are unsupported by design.
- `exposed_headers` — empty; nothing needs to be readable cross-origin.

Production must set `CORS_ALLOWED_ORIGINS` to the real frontend origin, e.g.
`https://nexora.example.com`. Leaving the localhost default in production makes
the API unreachable from the real client — the failure is loud, not silent.

---

## 6. Error responses

`backend/app/Support/Exceptions/ApiExceptionRenderer.php` maps every throwable
to the one documented envelope:

```json
{ "success": false, "message": "Validation failed.", "errors": { "field": ["..."] } }
```

- Stack traces, exception classes and file paths are **never** in a response.
- 500 responses use the fixed message `Something went wrong.` The only exception
  is a 500 while `APP_DEBUG=true`, which is a local-only setting — see §8.
- A database integrity error would otherwise leak a table name and a constraint
  name to the client; the renderer collapses it to the generic 500.

---

## 7. Logging, auditing and secret hygiene

- **Audit log is append-only.** `audit_logs` has no update or delete route.
  Login, logout, registration, role changes, asset assignment, stock movement,
  ticket transitions and configuration writes are all recorded with the acting
  user, IP and user agent.
- **Secrets are redacted at write time.** `SensitiveValueFilter` walks the
  audited payload and strips anything matching credential key patterns, so a
  future `record()` call cannot accidentally persist a password or token.
- **No credentials in logs.** The API logs requests through the standard
  Laravel stack; NEXORA adds no logging of credentials, headers or request
  bodies. The only frontend `console.*` call is a bootstrap failure message in
  `frontend/src/main.ts`.
- **No secrets in the repository.** `.env` is ignored at the repo root and in
  `backend/`, along with `.env.*` (only `!.env.example` is tracked). Both
  `.env.example` files ship empty placeholders — `APP_KEY=`, `DB_PASSWORD=`,
  `CORS_ALLOWED_ORIGINS` defaults — and no real credential has ever been
  committed. The one exception is the root `.env.example`
  `POSTGRES_PASSWORD=replace_with_your_local_password`, which must be non-empty
  or the image will not start; it is a placeholder to be replaced, not a secret,
  and the published port is bound to `127.0.0.1` (see §10).

---

## 8. Production deployment checklist

Every item is a hard gate. `APP_DEBUG=true` in production is the single most
dangerous mistake this codebase can make.

### Backend

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false` — otherwise 500s return exception messages
- [ ] `APP_KEY` set to a freshly generated `php artisan key:generate --show` value
- [ ] `APP_URL` set to the real public API origin
- [ ] `DB_CONNECTION=pgsql`, `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` /
      `DB_PASSWORD` set from the platform secret store
- [ ] `CORS_ALLOWED_ORIGINS` set to the real frontend origin(s) — no `*`
- [ ] `LOG_LEVEL=info` (or `warning`); `LOG_CHANNEL=stack` with a rotating sink
- [ ] `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true` once served over
      HTTPS
- [ ] `MAIL_MAILER` set to a real transport (defaults to `log` — password resets
      would be written to disk and never delivered)
- [ ] `php artisan migrate --force` run as part of the release step
- [ ] `php artisan config:cache` and `route:cache` after the env is final;
      re-run them after any `.env` change
- [ ] TLS terminated in front of the app; `Authorization` headers must never
      cross plaintext HTTP

### Frontend

- [ ] `environment.prod.ts` API base points at the real API origin (or the
      `apiUrl` build variable is set); no `localhost` / `127.0.0.1` / `10.0.2.2`
- [ ] `npm ci` from a lockfile, then `npm run build`
- [ ] `frontend/www/` (or `dist/`) served as static files over HTTPS
- [ ] Source maps either disabled for production or kept private

### Not yet production-ready (deliberate, tracked)

- [ ] **PostgreSQL is now verified — resolved in Phase 21B.** The dev machine had
      only `pdo_sqlite`, so earlier phases ran the suite on SQLite and the two
      PostgreSQL-only CHECK-constraint tests were skipped. `pdo_pgsql` is now
      enabled and the full suite passes against PostgreSQL 16 (`postgres:16-alpine`).
      See §10. Keep a PostgreSQL job in CI regardless: SQLite cannot catch
      `ORDER BY ""`, SELECT aliases in `HAVING`, or implicit-commit DDL.
- [ ] No email verification, password reset, MFA or account lockout. `register`
      creates an immediately usable account.
- [ ] Tokens have no expiry. Add `SANCTUM_EXPIRATION` plus a refresh flow if the
      threat model requires it.
- [ ] No centralized log shipping or alerting.

### Accepted dependency advisories

`composer audit` is clean. `npm audit` reports **3 moderate, 0 critical**, all
in one dev-only chain: `@capacitor/cli` → `xcode` → `uuid < 11.1.1`
(GHSA-w5hq-g745-h8pq, a missing bounds check in `uuid`'s buffered v3/v5/v6).
`uuid` is reached only while `@capacitor/cli` scripts an Xcode project, it is a
`devDependency`, and it never ships in `frontend/www`. npm's only offered fix is
`--force`, which downgrades `@capacitor/cli` to 8.4.3 and breaks the Capacitor
toolchain. Re-check on the next Capacitor major.

`vitest` was bumped `~4.0.18` → `^4.1.11` in this phase to clear
GHSA-5xrq-8626-4rwp (critical, CVSS 9.8) and GHSA-82fw-gwwq-j7x9 (moderate).
Neither is reachable in this project's workflow — the first requires the Vitest
**UI server** to be listening, which NEXORA never starts. The bump is
`isSemVerMajor: false`; note that vitest 4.1 moves Vite 6 → 8, which replaces
rollup with rolldown, so `package-lock.json` legitimately shrinks by ~660 lines
as the rollup binary tree is dropped.

`vitest` 4.1 also surfaces unhandled promise rejections that 4.0 swallowed
silently. Two auth specs were relying on that: they configured
`provideRouter([])` while `AuthService.handleUnauthorized()` navigates to
`/login`, so the navigation rejected with `NG04002` and the rejection escaped
the suite. The route table now registers the destinations the service actually
targets. **This is worth remembering: the suite exiting `0` was previously
masking a real error class.**

---

## 9. Reporting a vulnerability

This is a portfolio project with no published security contact. For a real
deployment, publish a dedicated address and a `SECURITY.md` with a response SLA
before inviting external users.

---

## 10. Runtime verification (Phase 21B)

The controls above are only worth the tests that back them. Phase 21B ran the
whole suite against a real PostgreSQL 16 container and then exercised the running
API over HTTP, not just in-process.

### 10.1 Database

`postgres:16-alpine` (PostgreSQL 16.15), local PHP 8.3.33 with `pdo_pgsql`
enabled. `php artisan test` → **762 passed / 0 failed / 0 skipped, 2986
assertions**. The same suite on SQLite passes 759 and skips the three
PostgreSQL-only CHECK tests (`items.minimum_stock >= 0`,
`stock_movements.quantity > 0`, `maintenance_parts.quantity > 0`).

Four classes of real defect only appear on PostgreSQL and are now fixed and
regression-tested:

- `ORDER BY ""` when a validated `sort` field was omitted, in five services that
  assigned the ternary's false branch to an undefined variable.
- SELECT aliases in `HAVING`, which MySQL/SQLite tolerate and PostgreSQL rejects.
- Implicit commit on DDL, so `MigrationsTest`'s `migrate:fresh --seed` leaked
  seeded rows into every later test; it now restores an unseeded schema in
  `tearDown()`.
- `jsonb` does not preserve key order, so two notification assertions compared
  JSON strings instead of structure.

### 10.2 Live API

69 checks over real HTTP against the container, all passing. The ones that map to
this document:

- §2 login returns `401 Invalid credentials` for a wrong password; a request with
  no token or a bogus token is `401`; `POST /auth/logout` kills only the current
  token.
- §2 **the deactivation kill switch holds over real HTTP**: a token minted while
  the account was active is `401` immediately after an admin sets
  `is_active=false`, and stays `401` after the user is reactivated.
- §2 the register rate limit is enforced: a burst of 5 registrations against a
  3/minute limit returns `429`.
- §3.2 notifications are owner-scoped — marking another user's notification read
  is `403`, and `read-all` drives the unread count to `0`.
- §3.2 the Command Center is read-only — `POST /api/v1/command-center` is `405`.
- §3.2 audit logs are append-only — `POST /api/v1/audit-logs` is `405`, and the
  detail endpoint returns the actor, resource, and `jsonb` `old_values` /
  `new_values` without a `data` envelope mismatch.
- §5 a CORS preflight from an allowed origin is `204` with exactly
  `GET, HEAD, POST, PUT, DELETE, OPTIONS` and no `PATCH`; an unlisted origin gets
  no `Access-Control-Allow-Origin`; no wildcard appears in methods or headers.
- §6 a genuine 500 is sanitized: `ApiErrorFormatTest` asserts the exact envelope
  `{"success":false,"message":"Something went wrong.","errors":[]}` with
  `APP_DEBUG=false`, and that no stack trace, exception class, file path or
  `SQLSTATE` is present. A second test asserts a trace is never emitted even when
  `APP_DEBUG=true`.

### 10.3 Database credential handling

`docker-compose.yml` reads `POSTGRES_PASSWORD` as `${POSTGRES_PASSWORD:?...}` so
Compose aborts with a readable message instead of letting the image restart-loop
on an empty password, and the published port is bound to `127.0.0.1`. Full setup
and the environment overrides needed to run the suite against the container are in
[`docker/README.md`](../../docker/README.md).
