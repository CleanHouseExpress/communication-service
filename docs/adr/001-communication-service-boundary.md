# ADR 001 - Communication Service Boundary

## Status

Accepted.

## Decision

Decision B was chosen: this repository will be treated officially as the `communication-service`, even while the directory and Laravel project still use the historical `communication-gateway` name.

This task does not rename the directory, package, app name, namespace, database, or deployment identifiers.

## Context

The service started as a technical gateway for WhatsApp/Z-API webhooks. After the initial phases, it also owns local conversations, messages, outbound sends, agent runs and internal communication contracts.

That behavior is broader than a pure gateway. The service is now the communication boundary for provider adapters and the minimal communication domain state.

## Communication-Service Responsibilities

The communication-service owns:

- provider adapters and webhook ingestion;
- raw provider events;
- inbound normalization;
- technical idempotency for inbound and outbound;
- local channels, contacts, conversations and messages;
- outbound provider sends;
- agent dispatch records;
- minimal tenant replica required to route communication data;
- future communication tenant database resolution.

## Orchestra-API Responsibilities

The orchestra-api remains the source of truth for:

- users;
- tenants/redes;
- authentication;
- RBAC/TBAC;
- roles and permissions;
- franchisor/franchise administrative domain;
- CRM and operational business domain;
- administrative workflows and dashboards.

## Responsibilities That Do Not Belong Here

The communication-service must not own:

- user authentication;
- user profiles;
- roles, permissions, RBAC or TBAC;
- full tenant administration;
- franchise hierarchy as source of truth;
- CRM/patient/customer business records;
- billing or contracts;
- administrative panels outside communication operations.

## Consequences

- The repo name can remain `communication-gateway` temporarily, but docs and architecture should refer to the service as `communication-service`.
- Tenant data is replicated, not authored, in this service.
- Only the minimum tenant metadata needed for communication routing should be stored locally.
- Future tenant databases belong to communication workloads only.
- Internal APIs must keep a clear service-token boundary until a stronger service auth mechanism is introduced.

## Risks

- The old project name may confuse contributors until deployment naming is aligned.
- Tenant replica drift is possible if sync events fail.
- Communication data may grow before tenant database split is implemented.
- Copying too much Orchestra domain data would blur boundaries and increase coupling.

## Next Steps

1. Keep the physical project name unchanged for now.
2. Introduce landlord tenant replica tables.
3. Add internal tenant sync endpoint for Orchestra-originated events.
4. Enforce tenant existence only behind `COMMUNICATION_TENANCY_ENFORCE`.
5. Define TenantCreated, TenantUpdated and TenantDisabled integration contracts.
6. Later, add tenant database provisioning and dynamic connection resolution.
