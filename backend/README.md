# NEXORA — Backend

Laravel REST API for the NEXORA enterprise operations platform.

## Requirements

- PHP 8.3+ with `pdo_sqlite` (tests) and `pdo_pgsql` (PostgreSQL, production)
- Composer
- Node.js 20+ / npm (for OpenAPI generation, planned later)

## Setup

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed    # database (SQLite or PostgreSQL)
php artisan serve                    # http://localhost:8000
```

### Environment

`.env.example` is committed; never commit real credentials. Default local
runtime is **SQLite** (`DB_CONNECTION=sqlite`). PostgreSQL is the production
database and requires the `pdo_pgsql` extension.

## Testing

```sh
composer test                         # PHPUnit
php artisan test                      # or directly via Artisan
```

107 tests covering migrations, seeders, models, auth, RBAC, organization
(departments/locations), and rate limiting. 2 tests require `pdo_pgsql` and are
skipped in the SQLite test harness.

Code style: `./vendor/bin/pint` (Laravel Pint).

## API base URL

```text
http://localhost:8000/api/v1
```

### Auth endpoints

| Method | Path | Description |
| ------ | ---- | ----------- |
| POST | `/api/v1/auth/register` | Create account (role `staff`) |
| POST | `/api/v1/auth/login` | Login, returns bearer token |
| POST | `/api/v1/auth/logout` | Revoke current token |
| GET | `/api/v1/auth/me` | Current user with role/department |
| GET | `/api/v1/health` | API health check |

### Organization endpoints (departments & locations)

|| Method | Path | Permission |
|| ------ | ---- | ---------- |
|| GET | `/api/v1/departments` | `view_departments` |
|| POST | `/api/v1/departments` | `manage_departments` |
|| GET | `/api/v1/departments/{department}` | `view_departments` |
|| PUT | `/api/v1/departments/{department}` | `manage_departments` |
|| DELETE | `/api/v1/departments/{department}` | `manage_departments` |
|| GET | `/api/v1/locations` | `view_locations` |
|| POST | `/api/v1/locations` | `manage_locations` |
|| GET | `/api/v1/locations/{location}` | `view_locations` |
|| PUT | `/api/v1/locations/{location}` | `manage_locations` |
|| DELETE | `/api/v1/locations/{location}` | `manage_locations` |

### User management endpoints

| Method | Path | Permission |
| ------ | ---- | ---------- |
| GET | `/api/v1/users` | `view_users` |
| POST | `/api/v1/users` | `manage_users` |
| GET | `/api/v1/users/{user}` | `view_users` |
| PUT | `/api/v1/users/{user}` | `manage_users` |
| DELETE | `/api/v1/users/{user}` | `manage_users` |

### Asset category endpoints

| Method | Path | Permission |
| ------ | ---- | ---------- |
| GET | `/api/v1/asset-categories` | `view_asset_categories` |
| POST | `/api/v1/asset-categories` | `manage_asset_categories` |
| GET | `/api/v1/asset-categories/{assetCategory}` | `view_asset_categories` |
| PUT | `/api/v1/asset-categories/{assetCategory}` | `manage_asset_categories` |
| DELETE | `/api/v1/asset-categories/{assetCategory}` | `manage_asset_categories` |

### Asset endpoints

| Method | Path | Permission |
| ------ | ---- | ---------- |
| GET | `/api/v1/assets` | `view_assets` |
| POST | `/api/v1/assets` | `manage_assets` |
| GET | `/api/v1/assets/{asset}` | `view_assets` |
| PUT | `/api/v1/assets/{asset}` | `manage_assets` |
|| DELETE | `/api/v1/assets/{asset}` | `manage_assets` |

### Asset assignment endpoints

|| Method | Path | Permission |
|| ------ | ---- | ---------- |
|| GET | `/api/v1/asset-assignments` | `view_asset_assignments` |
|| POST | `/api/v1/asset-assignments` | `manage_asset_assignments` |
|| GET | `/api/v1/asset-assignments/{assetAssignment}` | `view_asset_assignments` |
||| POST | `/api/v1/asset-assignments/{assetAssignment}/return` | `manage_asset_assignments` |

### QR asset identification endpoints

||| Method | Path | Permission |
||| ------ | ---- | ---------- |
||| GET | `/api/v1/assets/{asset}/qr` | `view_assets` |
||| GET | `/api/v1/assets/qr/{identifier}` | `view_assets` |

User management adds `view_users` and `manage_users` permissions
`super_admin` and `admin`). Asset categories add `view_asset_categories` and
`manage_asset_categories` (seeded to `super_admin` and `admin`). Assets add
`view_assets` (granted to all roles) and `manage_assets` (seeded to
`super_admin` and `admin`). Asset assignments add `view_asset_assignments`
(granted to all roles) and `manage_asset_assignments` (seeded to
(`super_admin`, `admin`, `manager`). QR asset identification adds no new
permissions (reuses `view_assets`). See `docs/api/README.md` for the full
reference and `docs/architecture/README.md` for Decision Records 007, 008,
and 009.

## Conventions

- **Modular monolith.** Modules live under `app/Modules/<Domain>` when their
  business phase begins.
- **Thin controllers.** Business logic belongs in `app/Services/`.
- **Consistent envelope.** Every endpoint returns
  `{ "success": true, "message": "...", "data": {} }`.
- **Zero-cost only.** Open-source and free tooling; no paid services.
- **No secrets in code.** Configuration through `.env` only.

## Docs

```text
docs/api/README.md          API endpoints, auth flow, RBAC
docs/database/README.md     migrations, models, seeders
docs/architecture/README.md request flow, decision records
```
