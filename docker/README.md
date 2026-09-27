# Docker

The only infrastructure containerized today is **PostgreSQL** for the database.
It is defined in the root `docker-compose.yml`.

This folder is reserved for container builds of the application itself:

- `backend/Dockerfile` — Laravel + PHP-FPM image (future)
- `frontend/Dockerfile` — Angular/Ionic static build served over nginx (future)

Those are added in a later phase once the backend and frontend are feature
complete enough to ship. Following the Zero-Cost Policy, only open-source
images are used.

To start only the database:

```sh
cp .env.example .env   # root .env — then set POSTGRES_PASSWORD
docker compose up -d db
```

**`POSTGRES_PASSWORD` is mandatory.** The `postgres:16-alpine` image refuses to
initialise a data directory with an empty password and will restart-loop with
`Database is uninitialized and superuser password is not specified`.
`docker-compose.yml` therefore reads it as `${POSTGRES_PASSWORD:?...}` and
aborts immediately with a readable message if it is unset. Set it in the root
`.env` before the first `docker compose up`.

The root `.env` feeds `docker-compose.yml` through `POSTGRES_USER` /
`POSTGRES_PASSWORD` / `POSTGRES_DB`. Then point `backend/.env` at the same
database — the two must agree:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
# These three must match the root .env.
DB_DATABASE=nexora
DB_USERNAME=postgres
DB_PASSWORD=
```

Inside Docker Compose the app reaches the database as `DB_HOST=db`, not
`127.0.0.1` (see the note in `backend/.env.example`).

The published port is bound to `127.0.0.1` so the development database is not
reachable from the network. Because of that, the placeholder password in
`.env.example` is only safe as a local development default — replace it with
your own local secret, and never reuse any development credential outside a
developer machine. See [`docs/security/README.md`](../docs/security/README.md).

The local PHP runtime must have the `pdo_pgsql` extension enabled, otherwise the
API cannot connect even though the container is healthy. On Windows with
Laragon, uncomment `extension=pdo_pgsql` in the active `php.ini` and point
`extension_dir` at `ext\php_pgsql.dll`.

### Rotating the local database password

If the local development password is ever exposed — pasted into a log, a ticket,
a chat window, a terminal transcript — treat it as compromised and rotate it.
`ALTER USER` keeps the data volume intact, so this does not require re-seeding:

```sh
# 1. Generate a new value and change the role. Feed the statement over stdin so
#    the password never appears in argv, in the process list, or in output.
NEW_PW=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9')
printf "ALTER USER postgres WITH PASSWORD '%s';\n" "$NEW_PW" \
  | docker exec -i nexora-postgres psql -U postgres -d postgres -q -v ON_ERROR_STOP=1

# 2. Update the root .env (used by Compose) to the same value.
#    Never echo it; edit the file in place.

# 3. Restart the API so it picks up the new credential. `artisan serve` spawns a
#    `php -S` child, so stop BOTH or the stale child keeps the old credential
#    and keeps the port:
#      pkill -f "artisan serve"; pkill -f "resources/server.php"
#      php artisan serve

# 4. Confirm the new value works and the old one no longer does, then delete
#    any temporary file that held it.
```

A base62-only value (letters and digits) is used deliberately: it needs no SQL
quoting, so the password cannot surface in a `psql` parse error.

**Always stop the `php -S` child as well as the `artisan serve` parent.** The
parent exiting does not stop the child, and a leftover child keeps port 8000
while holding the old credential — which surfaces as HTTP 500 on every
database-backed request, not as a connection error.

### Running the test suite against the container

```sh
# backend/, in the same shell
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432
export DB_DATABASE=nexora DB_USERNAME=postgres DB_PASSWORD='<root .env password>'
php artisan test
```

`phpunit.xml` pins SQLite in-memory, so the `DB_*` variables above must be
exported into the environment to override it. See
[`docs/release/README.md`](../docs/release/README.md) for the full checklist.