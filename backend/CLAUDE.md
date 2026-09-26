# NEXORA Backend

Laravel REST API for NEXORA, a fictional/portfolio enterprise operations platform.

Conventions for this codebase live in `AGENTS.md`:

- Modular monolith — business domains under `app/Modules/<Domain>`.
- Thin controllers; logic in services/actions.
- Consistent API envelope (`success` / `message` / `data`).

Verify with `composer test` and `php artisan route:list`.

No Laravel Boost / third-party tooling. Zero-cost policy applies.