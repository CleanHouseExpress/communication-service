# Tenant Runtime

## Tenant Resolution

Operational payloads already carry `tenant_id`. In the communication-service, this value maps to `communication_tenants.orchestra_tenant_id`.

When `COMMUNICATION_TENANT_RUNTIME_ENABLED=true`, runtime flows resolve the tenant by `tenant_id`, validate that it is active and find an active `communication_tenant_connections` record.

When the flag is false, all current behavior is preserved and operational writes continue using the default database connection.

## Tenant Connection

`ResolveTenantRuntimeConnectionAction`:

1. Receives the payload `tenant_id`.
2. Resolves the active `CommunicationTenant`.
3. Finds an active tenant connection with `database_name`.
4. Uses `TenantConnectionConfigurator` to register the Laravel connection at runtime.
5. Stores the current context in `CurrentTenantConnection`.

`CurrentTenantConnection` is request-memory only. Actions clear it in `finally` blocks so the context does not leak to the next request/test/job.

## Operational Data

When runtime is enabled, these operational models may use the current tenant connection:

- `CommunicationRawEvent`
- `CommunicationChannel`
- `CommunicationContact`
- `CommunicationConversation`
- `CommunicationMessage`
- `CommunicationOutboundMessage`
- `CommunicationAgentRun`

Landlord models stay on the default connection:

- `CommunicationTenant`
- `CommunicationTenantConnection`
- `CommunicationIntegrationEvent`

## Feature Flag

```env
COMMUNICATION_TENANT_RUNTIME_ENABLED=false
COMMUNICATION_TENANT_CONNECTION_NAME=communication_tenant
```

Default is disabled.

## Gradual Migration Strategy

1. Keep runtime disabled in production.
2. Provision and migrate tenant databases.
3. Enable runtime in a controlled environment.
4. Validate inbound/outbound/agent writes per tenant.
5. Add tenant-aware reads/inbox later.
6. Plan historical data migration from landlord to tenant databases.

## Current Limitations

- No historical data migration.
- No tenant-aware inbox/read endpoints.
- No rollback.
- No queue/retry orchestration.
- No RBAC/TBAC/users in this service.
- Runtime is prepared for operational writes only; public query flows are not implemented.
