# NEXORA Backend — Agent Conventions

This directory contains the **Laravel REST API** for NEXORA, a fictional/portfolio
enterprise operations platform.

## Prerequisites

```sh
php -v
composer -V
```

## Conventions

- **Modular monolith.** Business domains live under `app/Modules/<Domain>` once
  business work begins. Never spread a module's code across isolated packages.
- **Thin controllers.** Controllers delegate to services/actions for any logic
  beyond trivially wiring a request to a model query.
- **Consistent API envelope.** Every endpoint returns

  ```json
  { "success": true, "message": "...", "data": {} }
  ```

  and on validation/error `success: false` with `errors`. See
  `docs/api/README.md`.
- **Database is PostgreSQL** (see `.env.example`). History-bearing tables
  preserve history; nothing is overwritten.
- **No secrets.** Nothing hardcoded; configuration only through `.env`.
- **ZERO-COST policy.** Open-source and free tools only. Do not add paid
  services/dependencies.

## Verify

```sh
composer test     # PHPUnit/Pest
php artisan route:list
```

## Explicit non-goals

- No microservices.
- No Laravel Boost or similar add-on tooling.
- No business module implementation until the corresponding phase is started.