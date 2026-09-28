# NEXORA — Deployment Guide

Phase 22A. **Preparation only.** Nothing in this document has been deployed. No
Git remote exists, no cloud resources exist, and no credentials are stored in the
repository. Every value marked *you provide* is entered by hand in a provider
dashboard.

---

## A. Target architecture (zero cost)

```
┌────────────────────────────────────┐
│  Cloudflare Pages (free)           │   https://<project>.pages.dev
│  Angular 22 + Ionic 9 static build │   edge-cached, always HTTPS
└─────────────────┬──────────────────┘
                  │  HTTPS, cross-origin, Bearer token
                  │  CORS allowed via CORS_ALLOWED_ORIGINS
┌─────────────────▼──────────────────┐
│  Render Free Web Service           │   https://<service>.onrender.com
│  Laravel 13 / PHP 8.3 / Apache     │   Docker runtime, sleeps when idle
└─────────────────┬──────────────────┘
                  │  TLS, port 5432
┌─────────────────▼──────────────────┐
│  Neon Free PostgreSQL              │   0.5 GB, no expiry
└────────────────────────────────────┘
```

Total: **Rp0 / $0**.

### Why the frontend and API are on separate origins

Cloudflare Pages `_redirects` supports "proxying", but it is **relative-URL only —
you cannot proxy to an external domain**. A same-origin `/api/*` proxy to Render
is therefore *not available on Pages*. The browser calls the Render host directly
and the API permits it through CORS.

This matters because the CORS layer is not optional scaffolding — it is the
mechanism. `backend/config/cors.php` already implements it correctly
(`config/cors.php:18-46`): explicit origin allowlist, explicit method and header
allowlists, `Authorization` included, `supports_credentials: false`, never `*`.

### Why Neon and not Render Free Postgres

Render's own documentation states free Postgres databases **expire 30 days after
creation**. Neon Free has no expiry. Both PostgreSQL 16-compatible, both Rp0,
only one of them still exists in a month.

### Why no Redis

`config/queue.php:16`, `config/cache.php:18` and `config/session.php:21` all
default to the `database` driver, and the app ships no Redis dependency. Queue,
cache and sessions live in PostgreSQL alongside everything else. Adding a Redis
instance would add cost, a cold start and a failure mode for zero benefit at this
scale.

---

## B. Local vs hosted environments

| | Local | Hosted |
|---|---|---|
| Frontend | `ng serve` → `http://localhost:8100` | Cloudflare Pages |
| API | `php artisan serve` → `http://localhost:8000` | Render |
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | `false` |
| Database | `docker compose` PostgreSQL 16 on `127.0.0.1:5432` | Neon |
| API base URL | `/api/v1` (relative) | absolute, from `NEXORA_API_URL` |
| Auth | Sanctum bearer token | Sanctum bearer token |

`frontend/src/environments/environment.ts` is the development file; `ng build`
swaps in `environment.prod.ts` via `fileReplacements` in `angular.json`.
Development is unaffected by anything in this document.

---

## C. Cloudflare Pages

### Settings

| Setting | Value |
|---|---|
| Framework preset | None |
| Root directory | `frontend` |
| Build command | `npm ci && npm run build` |
| Build output directory | `www` |
| Production branch | `master` |

> If you prefer to leave the root directory at the repository root, use
> `cd frontend && npm ci && npm run build` with output directory `frontend/www`.

### Environment variables (build time)

| Variable | Required | Value |
|---|---|---|
| `NEXORA_API_URL` | **yes** | `https://<service>.onrender.com/api/v1` — set *after* Render exists |
| `NODE_VERSION` | **yes** | `24` — Angular 22 requires `^22.22.3 \|\| ^24.15.0 \|\| >=26.0.0` and the repo has no `.nvmrc` |

Set these under **Settings → Environment variables**. Mark `NEXORA_API_URL` as
available to the production branch.

### How `NEXORA_API_URL` is consumed

`npm run build` runs `npm run build:env` first
(`frontend/scripts/set-api-url.mjs`), which regenerates
`frontend/src/environments/environment.prod.ts` with the given base URL before
`ng build` reads it. The script:

- writes the relative default `/api/v1` when the variable is unset, so a local
  build leaves the file byte-identical and Git stays clean;
- rejects anything that is not an absolute `https:` URL, and trims trailing
  slashes, so a misconfigured build fails loudly instead of shipping a bundle
  that calls the wrong origin.

The production bundle therefore never contains `localhost`, `127.0.0.1` or
`:8000`. Do not hand-edit `environment.prod.ts` — the script overwrites it.

### SPA routing

`frontend/src/deploy/_redirects` is copied to the root of the build output as
`_redirects` (see the `assets` entry in `angular.json`):

```
/*    /index.html   200
```

Without it, reloading a deep link such as `/assets/42` asks the edge for a file
that does not exist. No further SPA configuration is required; do not add a
`/api/*` proxy rule, because Pages cannot proxy to an external host.

### Notes

- Output is a fully static site; no SSR, no Functions, no Workers.
- `outputHashing: "all"` is on, so assets are cache-safe and `index.html` is
  always revalidated.
- Set the `_redirects` file in the repo (already done) rather than in the
  dashboard, so every deploy is reproducible.

---

## D. Render Free Web Service

### Settings

| Setting | Value |
|---|---|
| Service type | Web Service |
| Runtime | Docker |
| Plan | Free |
| Root directory | `backend` |
| Dockerfile path | `./backend/Dockerfile` (relative to the **repo root**) |
| Docker build context | `./backend` |
| Health check path | `/api/v1/health` |
| Build command | *(leave empty — the Dockerfile builds)* |
| Start command | *(leave empty — the Dockerfile CMD runs)* |

`render.yaml` at the repository root encodes all of this. Applying it as a
Blueprint prompts for the secrets. `dockerfilePath` and `dockerContext` are both
resolved against the repository root, not against `rootDir` — both are stated
explicitly to remove the ambiguity.

### Port behaviour

Render assigns `$PORT` when the service is scheduled. The image does not bake a
port: the container's `CMD` writes `Listen $PORT` into `/etc/apache2/ports.conf`
at start and then execs `apache2-foreground`. `ENV PORT=10000` exists only as a
local default. Apache listens on `0.0.0.0:$PORT`; nothing listens on 8000.

### Runtime environment variables

Non-secret values are already declared in `render.yaml`. Set these in the Render
dashboard:

| Variable | Value |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` — **required**, the app cannot boot without it |
| `APP_URL` | `https://<service>.onrender.com` |
| `DB_HOST` | Neon host — **direct endpoint, no `-pooler` suffix** |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | Neon database name |
| `DB_USERNAME` | Neon username |
| `DB_PASSWORD` | Neon password |
| `DB_SSLMODE` | `require` (already set in `render.yaml`) |
| `CORS_ALLOWED_ORIGINS` | `https://<project>.pages.dev` |
| `TRUSTED_PROXIES` | optional — see below |

`SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`, `BROADCAST_CONNECTION`,
`FILESYSTEM_DISK`, `LOG_LEVEL`, `NEXORA_NAME` and `APP_VERSION` are pinned in
`render.yaml`.

#### Trusted proxies

Render terminates TLS and forwards the client address in `X-Forwarded-For`.
`bootstrap/app.php` reads `TRUSTED_PROXIES` and trusts **nothing** when it is
unset, which is the pre-deployment behaviour.

It is deliberately *not* defaulted to `*`. The Render hostname is publicly
reachable, so trusting every proxy would let any client forge `X-Forwarded-For`
and write an arbitrary address into the audit log. Leave it unset unless
recording real client IPs behind the edge matters more than that risk; if you do
set it, use Render's proxy CIDR list rather than `*`.

### CORS

`CORS_ALLOWED_ORIGINS` is comma-separated. If it is left at its default
(`http://localhost:8100,http://127.0.0.1:8100` from `config/cors.php:24`) the
browser blocks every deployed request. This fails closed, so a forgotten value
shows up immediately as a CORS error rather than a silent hole — but it is the
single most likely first-deploy mistake.

### Health check

`/api/v1/health` (`backend/routes/api.php:47`) is a public, rate-limited-free
route handled by `HealthController`, which returns static metadata and performs
**no database query**. It therefore reports application liveness without failing
when Neon is briefly suspended.

### Image

`backend/Dockerfile`, two stages:

- **vendor** — `composer:2.8`, `composer install --no-dev --no-scripts`, then
  `dump-autoload --optimize --classmap-authoritative --no-dev`. Only `/app/vendor`
  is copied forward; Composer itself is not in the published image.
- **runtime** — `php:8.3-apache` with `pdo_pgsql`, `pgsql`, `bcmath`, `intl` and
  `zip` compiled in. A build-time assertion fails the image if any of those fails
  to load, which catches a shared library lost to an over-eager `apt purge`.

`docker/php-production.ini` caps `memory_limit` at 256M and opcache at 96M
(Render Free instances have 512 MB) and turns off `expose_php`.
`docker/apache-production.conf` sets the document root to `public/`, so
application code, `.env` and `storage/` are never web-reachable, and disables
`.htaccess` in favour of an inline rewrite.

#### Why `config:cache` is deliberately absent

Render injects environment variables **at runtime**, after the image is built.
Running `php artisan config:cache` during the build would therefore freeze an
empty `APP_KEY` and empty database credentials into `bootstrap/cache/config.php`
and the container would fail on every request. Config caching is skipped, and
Laravel reads `getenv()` per request instead. The cost is a few milliseconds of
request time; the benefit is that configuration is correct by construction. On
Render Free there is also no shell in which to cache it at runtime.

`.dockerignore` excludes `/bootstrap/cache/*.php` for the same reason: no config
or route cache from the build host may leak into the image. Laravel regenerates
the package manifest on first request; `bootstrap/cache` is created and made
writable in the image so that write succeeds.

---

## E. Neon Free PostgreSQL

### Checklist

1. Create a project at <https://console.neon.tech> (no card required).
2. Choose a region near the Render region (`render.yaml` defaults to `oregon`).
3. Copy the connection details **by hand** from the Neon console:
   host, port, database, user, password.
4. Paste them into Render's environment variables. Do not put them in
   `.env`, `.env.example`, `render.yaml`, or any other tracked file.
5. Run the migrations from your own machine (see below).

### Use the direct endpoint, not the pooler

Neon offers a pooled endpoint whose host contains `-pooler`. **Do not use it
here.** The pooler is PgBouncer in transaction mode, which discards session
state between statements; the database session, cache and queue drivers all hold
state across statements and will misbehave. The direct endpoint is the correct
choice at this traffic level. Revisit only if connection limits actually bite.

### Migrations must run locally

Render Free provides **no shell, no SSH and no one-off job runner**. There is no
supported way to execute `php artisan migrate` against the deployed container.

Run migrations from your machine instead, against Neon:

```sh
# in backend/, with the Neon values exported for this shell only
export APP_ENV=production APP_DEBUG=false
export DB_CONNECTION=pgsql DB_HOST=<neon-host> DB_PORT=5432
export DB_DATABASE=<neon-db> DB_USERNAME=<neon-user> DB_PASSWORD=<neon-password>
export DB_SSLMODE=require
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync
export APP_KEY=$(php artisan key:generate --show)

php artisan migrate --force            # schema
# php artisan db:seed --force         # only when you actually want seed data
```

Overriding the cache/session/queue drivers to non-database values for the
duration of the migration avoids writing cache and session rows into Neon.
Export them into your shell for the command; do not write them to a tracked
file.

**Back up before migrating.** `migrate:rollback` and `migrate:reset` are
destructive. Neon Free offers a 6-hour instant-restore window (capped at 1 GB of
change history) and one manual snapshot — enough to recover from a bad
migration, not a substitute for thinking.

---

## F. Storage review

The application **performs no filesystem writes of its own**. A search of
`backend/app`, `backend/routes`, `backend/config` and `backend/database` for
`Storage::put`, `->store(`, `storeAs`, `putFile`, `file_put_contents`, `fopen`
and `fwrite` returns nothing.

| What | Where | Persistent? | Classification |
|---|---|---|---|
| All business data (assets, items, tickets, maintenance, users, audit logs, permissions) | PostgreSQL | yes | **Required persistent storage — satisfied by Neon** |
| Queued jobs | `jobs` table | yes | **Required persistent storage — satisfied by Neon** |
| Sessions | `sessions` table | yes | **Required persistent storage — satisfied by Neon** |
| Cache | `cache` table | no | Non-persistent, regenerable |
| `storage/logs/` | container disk | no | **Temporary — lost on every deploy/restart** |
| `storage/framework/views/` | container disk | no | Regenerated on demand |
| `bootstrap/cache/` | container disk | no | Regenerated on demand |
| File uploads | — | — | **No upload feature exists** |

Render Free's filesystem is ephemeral and cannot be given a persistent disk, but
nothing durable is written to it, so this is a non-issue. Two consequences worth
knowing:

- **Logs are lost** on every deploy, restart and spin-down. There is no
  aggregation service — `LOG_LEVEL=info` is set to keep the log small.
- Any *future* feature that persists user-uploaded files would need object
  storage or a paid disk. That is out of scope here and is recorded as a
  limitation rather than pre-emptively designed around.

---

## G. Deployment sequence

The ordering matters: the API must exist before the frontend build, because the
frontend needs the API's URL.

1. **Neon** — create the project; note the direct-endpoint credentials.
2. **Render** — apply `render.yaml` (or create the service by hand using the
   table in section D). Fill in every prompted secret. Note the resulting
   `https://<service>.onrender.com`. Deploy.
3. **Migrate** — run `php artisan migrate --force` locally against Neon
   (section E).
4. **Verify the API** — `curl https://<service>.onrender.com/api/v1/health`
   returns `success: true`. The first call may take up to a minute while the
   instance spins up.
5. **Cloudflare Pages** — create the project from the repository. Set root
   directory `frontend`, build `npm ci && npm run build`, output `www`,
   production branch `master`, and set `NODE_VERSION=24` plus
   `NEXORA_API_URL=https://<service>.onrender.com/api/v1`. Deploy.
6. **Set CORS** — confirm `CORS_ALLOWED_ORIGINS` on Render equals the deployed
   `https://<project>.pages.dev`. A Pages project is reachable at
   `*.pages.dev` and at any custom domain; list every origin you actually use.
7. **Verify** — load the site, log in, exercise a few API calls, and watch the
   browser console and the Render log for CORS errors.

---

## H. Rollback

| Layer | Action | Automatic? |
|---|---|---|
| Backend | Render dashboard → the previous successful deploy → **Rollback** | No — manual |
| Frontend | Cloudflare Pages → the previous production deploy → **Rollback** | No — manual |
| Database | `php artisan migrate:rollback --force`, or Neon instant restore | No — manual |

Render does **not** automatically revert a failed deploy to the previous
version. A failed deploy leaves the service on the last good image only if that
image was already live; otherwise roll back explicitly. Because config is not
cached, rolling back is purely a code change and takes effect on the next
request.

Rolling the database back is the dangerous one: it drops columns and data. Prefer
a forward-fix migration where possible.

---

## I. Free-tier limitations

### Render Free Web Service

- Sleeps after **15 minutes** without traffic; takes **about a minute** to wake.
  A page load after idle can look like a timeout.
- **No persistent disk**, no SSH or shell, no one-off jobs, no edge caching, no
  scaling beyond one instance. Render may also restart a free instance at any
  time.
- 750 free instance hours per month, which one service cannot exceed anyway.
- Ephemeral filesystem: logs and caches do not survive.
- Custom domains *are* supported on free web services, and the automatic
  `*.onrender.com` TLS certificate is included.

### Neon Free

- **0.5 GB storage per project.** Exceeding it fails writes that would grow
  storage, it does not bill you.
- 100 CU-hours per project per month; compute **scales to zero after 5 minutes
  of inactivity and that cannot be disabled**.
- **5 GB/month public egress.**
- Instant restore limited to a **6-hour** window, capped at 1 GB of change
  history, plus one manual snapshot. No scheduled backups.
- Community support only.

### Cloudflare Pages

- **500 builds per month** and 1 concurrent build on the free plan. Builds are
  rejected, not billed, once the monthly count is reached.
- Builds time out after 20 minutes; `npm ci && npm run build` sits well inside
  that, but a slow dependency install could reach it.
- Static asset requests and bandwidth are unmetered. Pages *Functions* are not
  free — they draw on the Workers quota — which is one more reason this
  deployment uses no Functions and does the SPA fallback in `_redirects`.
- 20,000 files per site, 100 custom domains per project, 100 projects per
  account, and 2,000 static redirects (this project uses 1).

### The two cold starts

Free-tier latency is **additive**. After a quiet period both layers are cold:
Neon resumes compute (~seconds) *and* Render wakes the container (~a minute),
and the browser waits for both. The frontend itself never sleeps, so most of the
apparent slowness is API-side. This is inherent to a Rp0 architecture; the only
fix is paying for a warm instance.

---

## J. Security rules

1. **Never commit secrets.** `.env`, `.env.*` and `*.pem/*.key/*.p12/*.pfx` are
   gitignored and excluded from the Docker build context. The image contains no
   `.env` at all — every value is injected at runtime.
2. **`APP_KEY` is a secret.** Generate with `php artisan key:generate --show` and
   paste it into Render. Never run `key:generate` against a live service: it
   rotates the key and invalidates every issued Sanctum token and session.
3. **Neon credentials live only in Render's dashboard.** They must never appear
   in a tracked file, in a commit message, or in a screenshot.
4. **HTTPS only.** `NEXORA_API_URL` is rejected at build time if it is not
   `https:`.
5. **CORS is an allowlist, never `*`.** `supports_credentials` is `false`;
   authentication is a Sanctum bearer token in the `Authorization` header, not a
   cookie, so no `SameSite`/credentialed-CORS surface exists.
6. **`APP_DEBUG=false` in production.** It is pinned in `render.yaml`.
7. **Do not trust all proxies by default** — see `TRUSTED_PROXIES` in section D.
8. **Rotate on exposure.** If a Neon password or `APP_KEY` is ever pasted
   somewhere public, rotate it in the provider console rather than deleting the
   message.

---

## K. Known non-production limitations

This deployment is a working Rp0 demonstration, not a production platform. Known
and accepted:

1. Cold starts on both the API and the database.
2. Logs are ephemeral and cannot be correlated after a restart.
3. No Redis, so cache and queue throughput are bounded by PostgreSQL.
4. Neon egress (5 GB/month) and storage (0.5 GB) are hard ceilings.
5. A single Render instance with no load balancing.
6. No persistent file storage — an upload feature would need object storage.
7. Migrations are run from a developer machine, not from CI or the platform.
8. Rollback is entirely manual on every layer.
9. `config:cache` is off, costing a few milliseconds per request.
10. Cloudflare Pages gives no way to proxy to the API, so cross-origin CORS is
    load-bearing and must be configured correctly.

---

## L. Files

| File | Role |
|---|---|
| `render.yaml` | Render Blueprint — service, plan, paths, env vars |
| `backend/Dockerfile` | Production image for Render |
| `backend/.dockerignore` | Keeps secrets and dev files out of the image |
| `backend/docker/apache-production.conf` | Apache vhost, `public/` document root |
| `backend/docker/php-production.ini` | Production PHP settings for 512 MB instances |
| `backend/bootstrap/app.php` | Opt-in `TRUSTED_PROXIES` |
| `frontend/scripts/set-api-url.mjs` | Injects `NEXORA_API_URL` into the prod build |
| `frontend/src/environments/environment.prod.ts` | Generated by the script above |
| `frontend/src/deploy/_redirects` | Cloudflare Pages SPA fallback |
| `frontend/angular.json` | `www` output, `_redirects` asset entry |
| `docs/deployment/README.md` | This document |

---

*Phase 22A — preparation only. Nothing has been deployed, pushed, or provisioned.*
