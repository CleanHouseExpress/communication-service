# Inbox Summary API

## Purpose

The inbox summary API exposes internal aggregate counters for panel inbox badges and tabs.

It is intentionally separate from `GET /api/internal/inbox/conversations` so the list endpoint can stay focused on paginated records.

Authentication, users, RBAC, TBAC and permissions remain responsibilities of `orchestra-api`.

## Endpoint

```http
GET /api/internal/inbox/summary?tenant_id=tenant-1
```

Headers:

```http
Accept: application/json
X-Service-Token: <service-token>
```

`Authorization: Bearer <service-token>` is also accepted.

## Query

- `tenant_id` required string;
- `assigned_external_user_id` optional string, used to calculate `total_my_assigned`.

Example:

```http
GET /api/internal/inbox/summary?tenant_id=tenant-1&assigned_external_user_id=user-123
```

## Response

```json
{
  "data": {
    "total_open": 10,
    "total_pending": 3,
    "total_closed": 20,
    "total_unassigned": 5,
    "total_assigned": 8,
    "total_handoff_requested": 2,
    "total_my_assigned": 4,
    "total_inbound_last_message": 6,
    "total_outbound_last_message": 7
  }
}
```

When `assigned_external_user_id` is not provided, `total_my_assigned` is returned as `null`.

## Tenant Runtime

When `COMMUNICATION_TENANT_RUNTIME_ENABLED=false`, counters use the current default database.

When `COMMUNICATION_TENANT_RUNTIME_ENABLED=true`, the query resolves `tenant_id` to an active tenant connection and counts records in the tenant database.

## Safety

The endpoint returns counts only. It does not return message payloads, provider responses, headers, tokens or contact data.

## Limitations

- Internal service token only.
- No user authentication in communication-service.
- No RBAC/TBAC checks in communication-service.
- No cache yet.
- No SLA, analytics or historical trend calculations.
