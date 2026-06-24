# Inbox Read API

## Purpose

The inbox read API exposes internal tenant-aware read endpoints for conversations and messages.

These endpoints are intended for future consumption by `orchestra-api` or an internal panel. They are not public endpoints.

Authentication, users, RBAC, TBAC and permissions remain responsibilities of `orchestra-api`.

## Headers

```http
Accept: application/json
X-Service-Token: <service-token>
```

`Authorization: Bearer <service-token>` is also accepted.

## Tenant

All endpoints require `tenant_id` in the query string.

When `COMMUNICATION_TENANT_RUNTIME_ENABLED=false`, reads use the current default database.

When `COMMUNICATION_TENANT_RUNTIME_ENABLED=true`, reads resolve `tenant_id` to an active `CommunicationTenant`, configure the tenant database connection and query operational tables there.

## Endpoints

### GET /api/internal/inbox/conversations

Query:

- `tenant_id` required string;
- `status` optional: `open`, `pending`, `closed`;
- `contact_id` optional UUID;
- `channel_id` optional UUID;
- `search` optional string, searches contact fields and message text;
- `page` optional;
- `per_page` optional, max 100.

### GET /api/internal/inbox/conversations/{conversation_id}

Query:

- `tenant_id` required string.

Returns a single conversation for that tenant or `404`.

### GET /api/internal/inbox/conversations/{conversation_id}/messages

Query:

- `tenant_id` required string;
- `direction` optional: `inbound`, `outbound`;
- `message_type` optional enum;
- `page` optional;
- `per_page` optional, max 100.

## Response Safety

Conversation responses include IDs, status, contact summary and latest message summary.

Message responses include safe message fields only:

- id;
- tenant_id;
- conversation_id;
- contact_id;
- channel_id;
- provider;
- direction;
- message_type;
- text;
- status;
- occurred_at;
- created_at.

Responses do not include raw provider payloads, provider responses, tokens, headers or configuration values.

## Limitations

- Internal service token only; no user auth.
- No RBAC/TBAC enforcement in communication-service.
- Handoff endpoints store operational state only; authorization remains in Orchestra.
- No read receipts.
- No assignment/handoff workflow.
- No websocket/realtime.
- No advanced filters, export or analytics.
