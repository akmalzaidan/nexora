# NEXORA — Release Checklist

What has to be true before NEXORA is called a release candidate, and how to
reproduce each check. Phase 21B is the current release-candidate gate; the
security posture is documented separately in
[`docs/security/README.md`](../security/README.md).

The product is a modular monolith: a Laravel 13 API in `backend/` and an
Angular 22 / Ionic client in `frontend/`, against PostgreSQL 16.

---

## 1. Prerequisites

| Tool | Verified version |
| --- | --- |
| PHP | 8.3.33 with `pdo_pgsql` **and** `pdo_sqlite` enabled |
| Composer | 2.x |
| Node.js | 20+ (Angular 22 toolchain) |
| Docker | Engine 29.7.2 / Compose 5.5.1 |
| PostgreSQL | `postgres:16-alpine` → 16.15 |

`pdo_pgsql` is the one prerequisite that is easy to miss and produces a
misleading error: the API reports a missing driver even though the container is
healthy. See [`docker/README.md`](../../docker/README.md).

---

## 2. Database

`POSTGRES_PASSWORD` must be set in the root `.env`; Compose now aborts with a
readable message rather than letting the image restart-loop on an empty
password.

```sh
cp .env.example .env      # then edit POSTGRES_PASSWORD
docker compose up -d db
docker compose ps         # expect nexora-postgres (healthy)
```

Migrations must be clean and repeatable:

```sh
cd backend
php artisan migrate:fresh --seed     # 30 migrations applied, 0 pending
php artisan migrate:status           # every migration [1] Ran
```

Seeders are idempotent — running `db:seed` twice must not change any count.
Phase 21B verified on PostgreSQL: 6 users, 2 assets, 5 items, 15 stock
movements, 4 tickets, 6 comments, 13 history rows, 4 maintenance requests, 2
maintenance records, 2 maintenance parts, 5 notifications, 0 audit logs.

---

## 3. Backend test suite

`phpunit.xml` pins SQLite in-memory, so a PostgreSQL run has to override it from
the environment:

```sh
cd backend
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432
export DB_DATABASE=nexora_test DB_USERNAME=postgres DB_PASSWORD='<root .env password>'
php artisan test
```

| Run | Result |
| --- | --- |
| PostgreSQL 16 | **762 passed, 0 failed, 0 skipped, 2986 assertions** |
| SQLite in-memory | 759 passed, 0 failed, 3 skipped |

The 3 SQLite skips are the PostgreSQL-only CHECK constraints
(`items.minimum_stock >= 0`, `stock_movements.quantity > 0`,
`maintenance_parts.quantity > 0`). **A green SQLite run is not sufficient** —
see §7.

Style:

```sh
cd backend
vendor/bin/pint --test     # passed
composer audit             # no security vulnerability advisories
```

---

## 4. Frontend

```sh
cd frontend
npm ci
npm test                   # 31 files, 385 passed
npm run lint               # all files pass
npm run build              # production bundle into www/
```

Production bundle: 682 kB raw / 164 kB estimated transfer. Confirm the built
output contains no environment leak:

```sh
grep -rE "localhost|127\.0\.0\.1|:8000" www/*.js www/*.html   # expect no matches
```

The API base is the relative `/api/v1`, so the bundle is host-agnostic.

`npm audit --omit=dev` → **0 vulnerabilities**. `npm audit` (all deps) → 3
moderate, all in the dev-only `@capacitor/cli` → `xcode` → `uuid < 11.1.1` chain;
npm's only fix is `--force`, which downgrades Capacitor and breaks the mobile
toolchain. Accepted, tracked in `docs/security/README.md` §8.

### Known build warnings (non-blocking)

- `anyComponentStyle` budget exceeded for 5 page stylesheets against the 8 kB
  warning budget; the largest is `requests.page.scss` at 11.57 kB, still under
  the 12 kB error threshold.
- `NG8113` unused `IonIcon` / `IonButton` in `NxConfirmDialogComponent`.
- Dart Sass `@import` deprecation warnings across `src/global.scss` and pages.

---

## 5. Live API smoke

Start the API against the same database, then exercise it over real HTTP:

```sh
cd backend
php artisan serve --host=127.0.0.1 --port=8000
curl -i http://127.0.0.1:8000/api/v1/health     # 200
```

Phase 21B ran 69 checks across authentication, CORS, reports, command center,
audit logs, inventory, helpdesk, notifications, maintenance, assets and logout —
all passing. The security-relevant expectations are listed in
`docs/security/README.md` §10.2. A preflight is `204`, not `200`; a wrong
password is `401`, not `422`.

---

## 6. Production configuration

Every item is a hard gate; full detail in `docs/security/README.md` §8.

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false` — otherwise 500 responses return exception messages.
      Verified by `ApiErrorFormatTest`, which asserts the sanitized envelope and
      the absence of any stack trace, exception class, file path or `SQLSTATE`.
- [ ] `APP_KEY` freshly generated
- [ ] `DB_*` supplied from the platform secret store
- [ ] `CORS_ALLOWED_ORIGINS` set to the real frontend origin — never `*`
- [ ] `SANCTUM_EXPIRATION` set if the threat model needs token expiry
- [ ] `MAIL_MAILER` set to a real transport
- [ ] `php artisan migrate --force`, then `config:cache` and `route:cache`
- [ ] TLS in front of the app; `Authorization` never crosses plaintext HTTP

---

## 7. Why PostgreSQL must stay in CI

Every defect below passed the full suite on SQLite and failed on PostgreSQL:

| Defect | SQLite | PostgreSQL |
| --- | --- | --- |
| `ORDER BY ""` from an unassigned default sort | tolerated | error |
| SELECT alias in `HAVING` | tolerated | error |
| `migrate:fresh --seed` inside a test | rolled back | implicitly committed, leaking seed rows into later tests |
| `jsonb` key ordering | n/a | differs, breaking string comparisons |

If CI ever drops back to SQLite-only, all four come back.

---

## 8. Release-candidate status

Phase 21B result: **COMPLETE** for a local/internal release candidate. The
outstanding items in `docs/security/README.md` §8 (token expiry, email
verification, password reset, MFA, lockout, log shipping) are deliberate,
documented product decisions rather than verification gaps, and they do not
block an internal deployment.
