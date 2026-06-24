# Multi-Tenancy Replica

## Source of Truth

The `orchestra-api` remains the source of truth for tenants/redes and all administrative identity concerns. The communication-service receives a replica of only the tenant fields needed to process communication traffic.

The communication-service must not become a second tenant administration system.

## Landlord Database

The communication-service has its own landlord database. In this phase, the landlord database stores:

- `communication_tenants`;
- `communication_tenant_connections`;
- existing communication tables that have not yet been moved to tenant databases.

No physical tenant database is created in this phase.

## Minimal Tenant Mirror

`communication_tenants` mirrors:

- `orchestra_tenant_id`;
- `name`;
- `slug`;
- `status`;
- `timezone`;
- small metadata;
- sync timestamps.

The local `id` is internal to communication-service. External calls should use `orchestra_tenant_id`, which maps to existing `tenant_id` fields in communication payloads.

## Future Tenant Database

`communication_tenant_connections` reserves the future connection metadata for a communication database per tenant/rede.

It stores connection shape and migration status, but this phase does not:

- create databases;
- switch Laravel connections dynamically;
- run tenant migrations;
- move existing tables.

## Sync Flow

Future Orchestra events should call `POST /api/internal/tenants/sync`.

### TenantCreated

Orchestra sends the tenant id, name, slug, active/pending status, timezone and metadata. The communication-service creates a local replica and sets `synced_at`.

### TenantUpdated

Orchestra sends the current representation. The communication-service updates the local replica by `orchestra_tenant_id` and refreshes `synced_at`.

### TenantDisabled

Orchestra sends `status=disabled`. The communication-service stores the disabled status and sets `disabled_at`.

## Tenant Resolution

`TenantResolver` resolves a local `CommunicationTenant` by `orchestra_tenant_id`.

When `COMMUNICATION_TENANCY_ENFORCE=false`, existing inbound/outbound/agent flows keep their current behavior.

When `COMMUNICATION_TENANCY_ENFORCE=true`, inbound, outbound and agent flows validate that the payload `tenant_id` exists as an active `communication_tenants.orchestra_tenant_id`.

This phase does not switch database connections.

## What Must Not Be Replicated

Do not replicate:

- users;
- passwords or auth credentials;
- roles;
- permissions;
- RBAC/TBAC policies;
- full CRM data;
- franchise administration workflows;
- billing or contracts.

The communication-service should only know enough tenant information to accept, route and store communication traffic.
