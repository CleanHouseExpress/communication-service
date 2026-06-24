# Tenant Runtime Validation

## Prerequisites

- Tenant replica exists in the landlord database.
- Tenant connection exists and is active.
- Tenant database was provisioned.
- Tenant migrations were executed.
- `COMMUNICATION_TENANT_RUNTIME_ENABLED` remains `false` by default.

## Sync Or Create Tenant

Use the internal sync endpoint or Orchestra event endpoint:

```bash
POST /api/internal/tenants/sync
POST /api/internal/orchestra/events/tenants
```

The tenant id used by communication-service is `orchestra_tenant_id`.

## Provision Database

```bash
php artisan communication:tenant:migrate tenant_123 --pretend
```

Provisioning itself is handled by:

```bash
POST /api/internal/tenants/tenant_123/provision-database
```

## Run Tenant Migrations

```bash
php artisan communication:tenant:migrate tenant_123
```

For production:

```bash
php artisan communication:tenant:migrate tenant_123 --force
```

## Diagnose Tenant Runtime

```bash
php artisan communication:tenant:diagnose tenant_123
```

The command shows:

- tenant status;
- connection status;
- database name;
- whether `migrated_at` is filled;
- connection test result;
- expected tenant table count when possible.

It never prints passwords.

## Local Smoke Test

Without sending data:

```bash
php artisan communication:tenant:smoke-test tenant_123
```

With fake inbound write:

```bash
php artisan communication:tenant:smoke-test tenant_123 --send-inbound
```

The smoke test temporarily enables tenant runtime in-process, disables the agent, keeps Z-API fake and verifies that a fake inbound message is written to the tenant database.

## Turn Runtime On Locally

In `.env`:

```env
COMMUNICATION_TENANT_RUNTIME_ENABLED=true
COMMUNICATION_TENANT_CONNECTION_NAME=communication_tenant
```

Then clear config:

```bash
php artisan config:clear
```

## Send Inbound Test

Use the internal inbound endpoint with `tenant_id` equal to `orchestra_tenant_id`.

```json
{
  "provider": "zapi",
  "tenant_id": "tenant_123",
  "external_message_id": "manual-test-1",
  "external_contact_id": "5500000000000",
  "message_type": "text",
  "text": "Teste runtime tenant"
}
```

## Confirm Tenant DB Write

Check the tenant database table:

```sql
select * from communication_messages where external_message_id = 'manual-test-1';
```

The landlord `communication_messages` table should not receive that row when runtime is enabled.

## Turn Runtime Off

Set:

```env
COMMUNICATION_TENANT_RUNTIME_ENABLED=false
```

Then:

```bash
php artisan config:clear
```

## Current Limitations

- No tenant-aware read endpoints yet.
- No historical data migration.
- No queue/retry orchestration.
- No runtime production rollout automation.
- No RBAC/TBAC/users in this service.
