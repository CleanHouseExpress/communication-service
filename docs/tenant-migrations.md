# Tenant Migrations

## Landlord vs Tenant Migrations

Landlord migrations are the normal Laravel migrations in:

```txt
database/migrations
```

They create service-level tables such as tenant replicas, tenant connection metadata and integration events.

Tenant migrations live in:

```txt
database/migrations/tenant
```

They create the operational communication tables that will eventually live in each tenant/rede database.

## Current Tenant Migration Tables

This phase adds tenant versions of:

- `communication_channels`
- `communication_contacts`
- `communication_conversations`
- `communication_messages`
- `communication_raw_events`
- `communication_outbound_messages`
- `communication_agent_runs`

These are equivalent to the current landlord communication tables as much as possible. The landlord tables are not removed or changed in this phase.

## Running Migrations For One Tenant

Use:

```bash
php artisan communication:tenant:migrate {orchestra_tenant_id}
```

Example:

```bash
php artisan communication:tenant:migrate tenant_123
```

The command:

1. Finds the tenant replica by `orchestra_tenant_id`.
2. Rejects disabled tenants.
3. Finds a provisioned tenant connection with `database_name`.
4. Configures a dynamic Laravel connection at runtime.
5. Runs migrations from `database/migrations/tenant`.
6. Marks the connection `active` and sets `migrated_at` on success.
7. Marks the connection `failed` with a summarized error on failure.

## Pretend Mode

Use:

```bash
php artisan communication:tenant:migrate tenant_123 --pretend
```

Pretend mode validates tenant and connection resolution, configures the connection and prints the target connection/path without running migrations.

## Production

Use `--force` in production:

```bash
php artisan communication:tenant:migrate tenant_123 --force
```

## Rollback

Rollback is not supported in this phase.

## Limitations

- Runtime models still use the current landlord connection.
- Tenant DB migrations do not move existing data.
- No all-tenants migration command exists.
- No queue/retry orchestration exists.
- No rollback command exists.
- No dynamic request-time tenant connection switching exists.

## Next Steps

- Add a tenant connection resolver for runtime models.
- Add a command to migrate all active tenant databases.
- Define rollback and repair procedures.
- Move communication runtime tables from landlord to tenant DBs.
- Add queue/retry for long-running tenant migration operations.
