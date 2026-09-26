# NEXORA

**Enterprise Operations Management Platform**

> **Important:** NEXORA is a **fictional/demo portfolio project**. It is not a
> product, not affiliated with any company, and is built to demonstrate
> full-stack software engineering. All data and business domains are
> illustrative.

---

## Overview

NEXORA centralizes common internal company operations — people, organization,
assets, inventory, helpdesk, and maintenance — into a single platform with a
responsive web and mobile interface.

The project demonstrates:

- Full-stack development (Angular + Laravel)
- REST API design with a consistent response contract
- Database design with historical records
- Role-based access control
- Business workflow implementation
- Enterprise application architecture (modular monolith)
- Responsive UI (desktop / tablet / mobile, light / dark / system)
- Testing, Docker, documentation, and free-deployment planning

## Features

Planned feature areas:

- Authentication (Laravel Sanctum)
- User management and profile (admin CRUD with role escalation guard)
- Role & permission management (database-driven)
- Department and location management
- Asset management, asset assignment, QR asset identification
- Inventory management and stock movement
- Helpdesk / ticketing with history
- Maintenance requests, records, and parts
- Notifications, dashboards, reports, and audit logs

**Backend foundation is implemented:** a versioned API (`/api/v1`) with a
consistent response envelope, JSON error handling, a health endpoint, and
Sanctum prepared. The full **database layer is implemented** — schema
migrations (Identity, Organization, Assets, Inventory, Helpdesk, Maintenance,
System), Eloquent models and relationships, idempotent dev seeders, factories,
and foundation tests (see `docs/database/README.md`). **Authentication & RBAC
is implemented** — register/login/logout/me with Sanctum bearer tokens,
role & permission middleware, centralized permission checks, and rate limiting
(see `docs/api/README.md`). **The Organization module is implemented** —
departments & locations CRUD with pagination, case-insensitive search,
whitelisted sorting, permission-guarded endpoints, and conflict-safe delete
(see `docs/api/README.md`). Business APIs for the remaining modules are
implemented in later phases.

## Technology Stack

| Layer        | Technology                          |
| ------------ | ----------------------------------- |
| Frontend     | Angular, TypeScript, Ionic, Capacitor, Angular Router, Reactive Forms, RxJS |
| Backend      | Laravel (PHP), Laravel Sanctum, REST API |
| Database     | PostgreSQL                          |
| Infra        | Docker, Docker Compose              |
| Docs         | OpenAPI / Swagger, Markdown         |
| Testing      | PHPUnit/Pest (backend), Vitest/Jasmine (frontend) |

## Architecture

**Modular monolith.** A single Laravel backend organized around business
domains, with a frontend that talks to it over a REST API. No microservices.

```text
Angular + Ionic (frontend)
        │  HTTPS / JSON
        ▼
Laravel REST API (single codebase, modular)
        │
        └── PostgreSQL
```

Request flow: Route → Controller → Request Validation → Service → Model → DB,
responses serialized through a consistent envelope.

See `docs/architecture/` for the decision records, request flow, and layer
conventions.

## Project Structure

```text
nexora/
│
├── frontend/          # Angular + Ionic + Capacitor app
├── backend/           # Laravel REST API
├── docs/
│   ├── architecture/  # architecture + decision records
│   ├── database/      # schema design notes
│   ├── api/           # API contract notes
│   ├── workflows/     # business workflow documentation
│   └── screenshots/   # UI screenshots (populated later)
│
├── docker/            # container builds (future) + notes
├── docker-compose.yml # PostgreSQL local database
├── .gitignore
├── .env.example       # root env for Docker Compose
├── README.md
└── LICENSE            # MIT
```

## Development Setup

Requirements:

- PHP 8.3+ with Composer
- Node.js 20+ with npm
- PostgreSQL (via `docker compose up -d db`, or a local install)

### Backend (Laravel)

```sh
cd backend
cp .env.example .env          # set your APP_KEY after copying
composer install
php artisan key:generate
php artisan migrate
php artisan serve             # http://localhost:8000
```

API base URL: `http://localhost:8000/api/v1`.

Health check:

```sh
curl http://localhost:8000/api/v1/health
```

> The committed `.env.example` targets PostgreSQL. For a zero-config local
> start you may temporarily use SQLite (`DB_CONNECTION=sqlite`), but PostgreSQL
> is the project database and the PHP runtime needs the `pdo_pgsql` extension
> enabled.

### Frontend (Angular + Ionic)

```sh
cd frontend
npm install
npm start                     # http://localhost:8100
```

The frontend reads `src/environments/environment.ts` for the API base URL
(default `http://localhost:8000/api/v1`).

### Native builds (Capacitor)

```sh
cd frontend
npm run build
npx cap add android   # or: npx cap add ios
npx cap sync
```

## Environment Configuration

Configuration lives in `.env` files. Never commit real secrets; only commit
`.env.example` files.

- Root `.env.example` — Docker Compose (database credentials, ports)
- `backend/.env.example` — Laravel app + database + CORS origins
- Frontend settings — `frontend/src/environments/environment*.ts`

## Testing

```sh
# Backend (Laravel — PHPUnit/Pest)
cd backend
composer test

# Frontend (Vitest)
cd frontend
npm test
```

## Docker

```sh
docker compose up -d db   # start PostgreSQL
docker compose down       # stop
```

Container images for the backend and frontend themselves will be added in a
later phase (see `docker/README.md`).

## Roadmap

1. **Initialization** — repository shell, frameworks, docs. *done*
2. **Backend foundation** — versioned `/api/v1`, response envelope, JSON error
   handling, health endpoint, CORS, Sanctum prepared, foundation tests. *done*
3. **Database layer** — full schema migrations, Eloquent models, idempotent dev
   seeders, factories, database tests. *done*
4. **Identity & Auth** — login/register, Sanctum tokens, RBAC enforcement,
   users/roles/permissions APIs, audit logging. *auth + RBAC part done*
5. **Organization module** — departments, locations. *done*
6. **User management** — admin user CRUD, role escalation guard, self-protection. *done*
7. **Assets module** — asset categories, assets, assignments, history, QR.
7. **Inventory module** — items, warehouses, stock movements.
8. **Helpdesk module** — tickets, comments, history.
9. **Maintenance module** — requests, records, parts.
10. **Frontend foundation** — shell, navigation, themes (light/dark/system).
11. **Dashboards, reports, notifications.**
12. **Testing, Docker images, deployment (free/self-hosted).**

Work stops after each phase and the next phase starts only on request.

## Zero-Cost Project Policy

NEXORA is developed with a **zero-cost requirement**:

- Prefer open-source alternatives.
- Prefer self-hosted solutions.
- Prefer permanently free tools.
- Prefer available free tiers when appropriate.
- If nothing fits, redesign or omit the feature.

No paid APIs, SaaS, cloud services, domains, databases, email providers, or
features requiring payment are introduced.

## License

[MIT](./LICENSE)