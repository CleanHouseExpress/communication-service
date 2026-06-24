# Tenant Migrate All

## Command

```bash
php artisan communication:tenant:migrate-all
```

Options:

```bash
php artisan communication:tenant:migrate-all --pretend
php artisan communication:tenant:migrate-all --force
php artisan communication:tenant:migrate-all --only=tenant_1,tenant_2
php artisan communication:tenant:migrate-all --status=active
```

## Behavior

The command finds communication tenant replicas by status. The default status is `active`, so disabled tenants are ignored by default.

For each matching tenant, it reuses `RunTenantMigrationsAction`, the same action used by `communication:tenant:migrate`.

The command continues processing remaining tenants if one tenant fails.

## Summary

At the end, the command prints:

- `total`
- `success`
- `failed`
- `skipped`

`skipped` means the tenant matched the query but had no valid tenant connection with `database_name`.

## Pretend Mode

`--pretend` validates tenant connections and prints the connection/path that would be used. It does not run migrations.

## Limitations

- No rollback.
- No queue or retry.
- No runtime model connection switching.
- No web panel.
- No RBAC/TBAC/users in this service.
