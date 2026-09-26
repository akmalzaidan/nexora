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
cp .env.example .env   # root .env, customize credentials
docker compose up -d db
```

Then point `backend/.env` at PostgreSQL:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nexora
DB_USERNAME=nexora
DB_PASSWORD=nexora_secret_change_me
```

Note: the local PHP runtime must have the `pdo_pgsql` extension enabled.