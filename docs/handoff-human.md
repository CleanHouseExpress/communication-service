# Human Handoff

## What Handoff Means

Handoff is an operational marker that indicates a conversation should be handled by a human.

The communication-service does not decide user permissions, queue visibility or who is allowed to handle the case. Those decisions remain in `orchestra-api`.

## When The Agent Can Request Handoff

The n8n/AI agent can return:

```json
{
  "should_handoff": true
}
```

When this happens, the communication-service marks the conversation with:

- `handoff_requested_at`;
- `handoff_reason`;
- `handoff_status=requested`;
- `handoff_requested_by=agent`;
- `handoff_requested_reason`;
- `status=pending`.

The conversation remains `service_mode=ai` until a human explicitly assumes it. No user is assigned automatically.

## Orchestra Responsibilities

The `orchestra-api` remains responsible for:

- user authentication;
- RBAC/TBAC;
- permissions;
- deciding which attendants can see the conversation;
- deciding which user can assign/close/reopen.

## Communication-Service Responsibilities

The communication-service stores only operational state:

- service mode (`ai` or `human`);
- handoff status (`none`, `requested`, `assigned`);
- handoff request timestamp/reason;
- assigned external user id/name received from Orchestra;
- assignment timestamp;
- closed timestamp;
- conversation status.

`assigned_external_user_id` and `assigned_external_user_name` are external references. They are not local users.

## Internal Endpoints

- `POST /api/internal/inbox/conversations/{conversation_id}/request-handoff`
- `POST /api/internal/inbox/conversations/{conversation_id}/assign`
- `POST /api/internal/inbox/conversations/{conversation_id}/close`
- `POST /api/internal/inbox/conversations/{conversation_id}/reopen`

All endpoints require service token and `tenant_id`.

## Limitations

- No local users.
- No RBAC/TBAC.
- No advanced attendance queue.
- No SLA.
- No websocket/realtime notifications.
- No advanced audit trail.
