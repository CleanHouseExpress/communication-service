# Conversation Timeline

## Purpose

`communication_conversation_events` is an operational event store for conversations.

It is not a Laravel audit log. It records business-level events that can later support:

- timeline UI;
- analytics;
- SLA;
- replay;
- dashboards;
- debugging;
- AI training context.

## Event Shape

Each event stores:

- tenant;
- conversation;
- optional message;
- optional agent run;
- event type;
- actor type;
- optional actor id/name;
- description;
- metadata;
- occurrence timestamp.

## Initial Event Types

- `conversation_created`
- `message_received`
- `message_sent`
- `agent_started`
- `agent_finished`
- `agent_skipped`
- `handoff_requested`
- `conversation_assigned`
- `conversation_returned_to_ai`
- `conversation_closed`
- `conversation_reopened`
- `human_message_sent`

## Automatic Recording

The service records events for:

- new conversation creation;
- inbound message received;
- outbound message sent;
- agent started, finished or skipped;
- handoff requested;
- human assignment;
- return to AI;
- close/reopen;
- human message sent.

Event recording is best effort. If recording fails, the main flow continues and a structured warning is logged.

## Timeline Endpoint

```http
GET /api/internal/inbox/conversations/{conversation_id}/timeline?tenant_id=tenant-1
```

Requires service token.

Response fields:

- `event_type`;
- `actor_type`;
- `actor_name`;
- `description`;
- `metadata`;
- `occurred_at`.

The endpoint returns events in chronological order.

## Safety

Timeline resources remove sensitive metadata keys such as tokens, secrets, authorization headers, raw payloads and provider responses.

## Limitations

- No websocket.
- No SLA calculations yet.
- No analytics yet.
- No advanced audit trail.
- No full event sourcing/rebuild guarantees yet.
