# Database

PostgreSQL is the NEXORA database. All schema migrations, Eloquent models,
idempotent dev seeders, factories, and foundation database tests are
**implemented** in `backend/database/` and `backend/app/Models/`.

Local tests run against in-memory SQLite (see `backend/phpunit.xml`); the
committed `.env.example` targets PostgreSQL.

## Principles

- Primary keys, foreign keys, and unique constraints on all reference data.
- Not-null constraints where the domain requires a value.
- Check constraints added as raw `ALTER TABLE ... ADD CONSTRAINT` statements,
  guarded to PostgreSQL only. SQLite cannot add check constraints to existing
  tables and is used only for tests.
- Indexes based on real query patterns, never added blindly.
- History is preserved, never overwritten. Work that changes state writes to an
  append-only history table:

```text
asset_histories      (no updated_at)
stock_movements      (no updated_at)
ticket_histories     (no updated_at)
audit_logs           (no updated_at)
maintenance_records  (timestamps; result field preserves record)
```

- Foreign keys use `NO ACTION`/`RESTRICT` by default so history is never
  cascade-deleted. `cascadeOnDelete` is used only where the row is intrinsically
  owned (`role_permissions` pivot, `notifications.user_id`).
- Soft deletes only on `assets` and `items`.

## Tables by Domain

### Identity

```text
users            name, email (unique), password, role_id (FK, nullable), department_id (FK, nullable),
                 is_active, last_login_at
roles            name, slug (unique), description
permissions      name, slug (unique), description
role_permissions pivot (unique role_id + permission_id, cascade delete)
```

### Organization

```text
departments      name, code (unique), description, manager_id (FK users, nullable), is_active
locations        name, code (unique), description, address, is_active
```

`departments.manager_id` is created before `users` carries `department_id`;

the foreign key is added in a dedicated migration. The circular reference
`users ↔ departments` is an intentional, documented modelling choice.

### Assets

```text
asset_categories     name, code (unique), description
assets               asset_category_id (FK), asset_code (unique), name, description,
                     serial_number (indexed, nullable), status (indexed), condition,
                     purchase_date, purchase_price decimal(15,2), warranty_expiry,
                     location_id (FK), current_user_id (FK users), soft deletes
asset_assignments    asset_id (FK), user_id (FK), requested_by (FK), approved_by (FK),
                     location_id (FK), assigned_at, returned_at, status (indexed)
asset_histories      append-only, action/old_status/new_status/old+new location/notes
```

### Inventory

```text
item_categories      name, code (unique), description
items                item_category_id (FK), sku (unique), name, description, unit,
                     minimum_stock (check >= 0, pgsql), maximum_stock, is_active, soft deletes
warehouses           name, code (unique), location_id (FK), description, is_active
stock_movements      item_id (FK), warehouse_id (FK), type (indexed), quantity (check > 0, pgsql),
                     reference_type/reference_id (polymorphic), performed_by (FK users),
                     notes, created_at only
```

### Helpdesk

```text
ticket_categories    name, code (unique), description
tickets              ticket_number (unique), title, description, category_id (FK),
                     requester_id (FK), assigned_to (FK), department_id (FK),
                     location_id (FK), priority, status, closed_at
ticket_comments      ticket_id (FK), user_id (FK), comment, is_internal
ticket_histories     append-only, action/old_status/new_status/notes
```

### Maintenance

```text
maintenance_requests    asset_id (FK), requested_by (FK), assigned_to (FK), title,
                        description, priority, status, requested_at,
                        approved_at, completed_at
maintenance_records     maintenance_request_id (FK), asset_id (FK), technician_id (FK),
                        started_at, completed_at, description, result, cost decimal(15,2)
maintenance_parts       maintenance_record_id (FK), item_id (FK),
                        quantity (check > 0, pgsql)
```

### System

```text
notifications   user_id (FK, cascade delete), type, title, message, data jsonb, read_at
audit_logs      user_id (FK, nullable), action, entity_type, entity_id,
                description, old_values/new_values jsonb, ip_address, user_agent, created_at only
```

## Migration Order

Migrations run in the order shown by `php artisan migrate:status`. The
dependency order:

1. Framework tables (`0001_01_01_000000*`, personal access tokens)
2. Identity foundations — `roles`, `permissions`, `departments`, `locations`
3. `users` identity extension (adds `role_id`, `department_id`, ...)
4. `role_permissions` pivot, then `departments.manager_id` foreign key
5. Assets → Inventory → Helpdesk → Maintenance → System

## Seeding

`php artisan migrate:fresh --seed` (or `db:seed`) loads reference data. All
seeders are **idempotent** (`updateOrCreate` / `syncWithoutDetaching`).

- **Roles** (6): super_admin, admin, manager, staff, technician, warehouse_staff
- **Permissions** (19): view_dashboard, manage_users, manage_roles,
  manage_departments, manage_locations, view_assets, manage_assets,
  assign_assets, approve_asset_assignments, view_inventory, manage_inventory,
  manage_stock, view_tickets, manage_tickets, assign_tickets, view_maintenance,
  manage_maintenance, view_reports, view_audit_logs
- **Departments** (6) and **Locations** (4)
- **Asset/Item/Ticket categories** (6 / 4 / 5)
- **Development accounts** — six users `<role>@nexora.test`
  (e.g. `admin@nexora.test`) with the shared dev password `password`. These are
  development-only; never use on a real deployment.

## Factories

`UserFactory` (updated with identity fields), plus `AssetFactory`,
`ItemFactory`, `TicketFactory`, `MaintenanceRequestFactory` and supporting
category factories, used by the foundation tests.

## Tests

`tests/Feature/Database/`:

```text
MigrationsTest          migrate:fresh --seed runs and creates every table
DatabaseSeederTest      reference data, role→permission counts, idempotency
RelationshipsTest       key Eloquent relationships resolve
ConstraintsTest         unique constraints reject duplicates
QuantityConstraintTest  check constraints (skipped on non-PostgreSQL drivers)
```

The PostgreSQL-only check-constraint tests are skipped on SQLite by design.