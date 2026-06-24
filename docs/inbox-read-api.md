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
- `assignment_status` optional: `unassigned`, `assigned`;
- `assigned_external_user_id` optional string;
- `handoff` optional: `requested`, `none`;
- `has_handoff_requested` optional boolean (`true`, `false`, `1`, `0`);
- `closed` optional boolean (`true`, `false`, `1`, `0`);
- `last_message_from` optional: `inbound`, `outbound`;
- `updated_since` optional date;
- `sort` optional: `last_message_at`, `created_at`, `updated_at`;
- `direction` optional: `asc`, `desc`;
- `contact_id` optional UUID;
- `channel_id` optional UUID;
- `search` optional string, searches contact fields and message text;
- `page` optional;
- `per_page` optional, max 100.

Default ordering is `last_message_at desc`.

Examples:

```http
GET /api/internal/inbox/conversations?tenant_id=tenant-1&handoff=requested&assignment_status=unassigned
GET /api/internal/inbox/conversations?tenant_id=tenant-1&assigned_external_user_id=user-123&closed=false
GET /api/internal/inbox/conversations?tenant_id=tenant-1&last_message_from=inbound&sort=updated_at&direction=desc
```

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

Conversation responses include IDs, status, `assignment_status`, `has_handoff_requested`, contact summary and latest message summary.

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
- Assignment and handoff are operational fields only; authorization remains in Orchestra.
- No websocket/realtime.
- No aggregate counters, SLA, export or analytics.
