# Inbox Write API

## Purpose

The inbox write API exposes internal operational commands for conversations.

These endpoints are intended to be called by `orchestra-api` after it validates user identity, tenant access, RBAC/TBAC and permissions.

`communication-service` does not manage real users or permissions.

## Headers

```http
Accept: application/json
X-Service-Token: <service-token>
```

`Authorization: Bearer <service-token>` is also accepted.

## Send Human Message

```http
POST /api/internal/inbox/conversations/{conversation_id}/messages
```

Payload:

```json
{
  "tenant_id": "tenant-1",
  "text": "Mensagem do atendente"
}
```

Behavior:

- resolves the conversation by `conversation_id` and `tenant_id`;
- rejects missing or cross-tenant conversations with `404`;
- rejects closed conversations with `409`;
- uses the existing conversation contact/channel;
- does not accept `external_contact_id` from callers;
- creates an outbound text message with source `human`;
- delegates provider delivery to the existing outbound flow;
- returns the safe `MessageResource` shape.

Response example:

```json
{
  "data": {
    "id": "uuid",
    "tenant_id": "tenant-1",
    "conversation_id": "uuid",
    "contact_id": "uuid",
    "channel_id": "uuid",
    "provider": "zapi",
    "direction": "outbound",
    "message_type": "text",
    "text": "Mensagem do atendente",
    "status": "sent",
    "occurred_at": "2026-06-25T12:00:00-03:00",
    "created_at": "2026-06-25T12:00:00-03:00"
  }
}
```

## Safety

Responses do not include raw payloads, provider responses, tokens, headers or provider configuration values.

## Limitations

- Text only.
- No media.
- No realtime/WebSocket.
- No read receipts.
- No user/RBAC/TBAC enforcement in communication-service.
- No automatic handoff state transitions.
- No transfer back to AI.
