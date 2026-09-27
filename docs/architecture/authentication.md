# Authentication & Session (NEXORA)

## Overview
Phase 11 replaced the placeholder auth stubs with a real client-side authentication
flow that talks to the Laravel Sanctum API. There is no local `users` store: the
frontend delegates identity to the backend and only persists the token + user graph
that the API returns.

## Backend contract
All calls go through `ApiService` whose `baseUrl` is `/api/v1`, so endpoints are
`/api/v1/auth/...`:

| Endpoint                | Request                                               | Success `data`               |
| ----------------------- | ----------------------------------------------------- | ---------------------------- |
| `POST /api/v1/auth/login`    | `{ email, password }`                              | `{ user, token }`            |
| `POST /api/v1/auth/register` | `{ name, email, password, password_confirmation }` | `{ user, token }`            |
| `POST /api/v1/auth/logout`   | `{}` (Authorization header)                        | `{}`                          |
| `GET  /api/v1/auth/me`       | `{}` (Authorization header)                        | `{ user }`                    |

- Register returns a token, so a successful registration starts a session immediately.
- `UserResource` is a nested graph: `{ id, name, email, role: {id,name}` , `department: {id,name,code}, is_active, created_at }`. `role` and `department` are objects (or `null`), never strings.
- Error envelope: `{ success: false, message, errors? }`.

## AuthService
Lives in `src/app/core/services/auth.service.ts`. Signal-backed, injectable at root.

State:
- `token()` — string | null
- `user()` — UserResource | null
- `isAuthenticated()` — derived `!!token() && !!user()`

Lifecycle:
- `ensureInitialized()` — hydrates the session from `StorageService` (keys `nx-token`,
  `nx-user`) and re-validates with `GET /auth/me` when a token is present before
  resolving. Guards `await` this before deciding access.
- `login(email, password)` — `POST /auth/login`, stores token+user, resolves
  `{ user, token }`.
- `register(...)` — `POST /auth/register`, stores token+user immediately.
- `updateUser(user)` — patches the in-memory + persisted user (used by profile).
- `logout()` — `POST /auth/logout`; clears session even if the call fails.

Error mapping (`mapAuthError`):
- 401 → `Invalid email or password.`
- 422 → first field error
- 429 → `Too many attempts. Please try again later.`
- `>= 500` → `Something went wrong. Please try again later.`

## StorageService
Thin wrapper over `localStorage` (also isolates member keys under an `nx-` prefix so
tests don't collide with browser state). API: `get<T>(key)`, `set<T>(key, value)`,
`remove(key)`. Auth keys: `nx-token`, `nx-user`; redirect return URL: `nx-return-url`.

## HTTP interceptor
`src/app/core/interceptors/auth.interceptor.ts` (functional, registered with
`provideHttpClient(withInterceptors([authInterceptor]))`):
- Attaches `Authorization: Bearer <token>` to requests whose URL starts with `/api/`.
- On a 401 response it clears the session via `AuthService.handleUnauthorized()` so a
  later navigation can show the login page again.

## Route guards
Both live in `src/app/core/guards`:

- `authGuard` — `await auth.ensureInitialized()`; if `isAuthenticated()` is false it
  records the intended destination in `nx-return-url` (only for safe internal URLs)
  and redirects to `/login`. Otherwise `true`.
- `guestGuard` — for public routes; if `isAuthenticated()` is true it redirects to
  `/home`, else `true`.

## Pages
- `src/app/pages/login` — LoginPage form → `AuthService.login`, then redirect to the
  saved `nx-return-url` or `/home`.
- `src/app/pages/register` — RegisterPage form → `AuthService.register` (with
  `password_confirmation`), starts a session immediately.
- `src/app/pages/profile` — shows `auth.user()`, `updateUser()` patch, logout.

## Server-side session invalidation
Tokens are Sanctum personal access tokens and do not expire, so "log this user
out" cannot mean "wait for the token". Two server-side rules cover it:

- **Deactivation is immediate.** `App\Http\Middleware\Authenticate` re-checks
  `is_active` on every authenticated request. A token minted before the account
  was deactivated returns the ordinary `401 Unauthenticated.` envelope and the
  token is deleted, so access ends on the next request and reactivating the
  account does not bring the old session back. The frontend needs no special
  handling: the interceptor already tears the session down on any protected
  `/api/` 401, so a deactivated user is returned to `/login` on their next
  click.
- **Logout revokes only the calling token.** Other sessions stay signed in.

The model behind all of this, plus the production checklist, is in
[`docs/security/README.md`](../security/README.md).

## Redirect etiquette
Public endpoints `/login` and `/register` may still return 401/422 (validation) and
must NOT clear the session. The interceptor treats those as ordinary responses; only
protected `/api/` 401s trigger session teardown.

## Testing
Specs use the Angular `HttpTestingController` + real `TestBed` (no jasmine globals):
- `core/services/auth.service.spec.ts` — login/register/logout/me + error mapping.
- `core/interceptors/auth.interceptor.spec.ts` — bearer attach, bare requests,
  non-API passthrough, 401 teardown.
- Page specs follow the IonicModule smoke convention from `home.page.spec.ts`.
