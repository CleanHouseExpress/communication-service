# Conversation Service Mode

## Overview

Conversations start in AI service mode. In this mode, inbound text messages can be dispatched to the configured agent and the agent may reply automatically.

Humans can observe the inbox without changing the conversation. A conversation only moves to human service mode when an internal caller explicitly assigns it through the assignment endpoint.

## Fields

`status` describes the operational lifecycle of the conversation:

- `open`: active conversation;
- `pending`: waiting for human attention;
- `closed`: finished conversation.

`service_mode` describes who is currently responsible for service:

- `ai`: default mode, agent can process inbound text;
- `human`: a human has assumed service, inbound text is not dispatched to the agent.

`handoff_status` describes the handoff queue state:

- `none`: no handoff requested;
- `requested`: the conversation is waiting for a human;
- `assigned`: a human has assumed it.

## Default AI Service

New conversations are created with:

- `status=open`;
- `service_mode=ai`;
- `handoff_status=none`.

## Human Observing

Reading conversations or messages does not change `service_mode` or `handoff_status`.

This allows the panel or `orchestra-api` to display/observe an AI-led conversation without interrupting the agent.

## Agent Requesting Handoff

When the agent returns `should_handoff=true`, the conversation remains in AI mode but is moved to the human waiting queue:

- `service_mode=ai`;
- `handoff_status=requested`;
- `handoff_requested_at=now`;
- `handoff_requested_by=agent`;
- `handoff_requested_reason` with a generic or agent-provided reason;
- `status=pending`.

No user is assigned automatically.

## Human Assuming

When `orchestra-api` calls the assignment endpoint, the conversation becomes human-led:

- `service_mode=human`;
- `handoff_status=assigned`;
- `assigned_external_user_id/name` are stored as external references;
- `assigned_at=now`;
- `handoff_assigned_at=now`;
- `status=open`.

While `service_mode=human`, inbound text is not sent to the agent.

## Compatibility

Older handoff fields remain available:

- `handoff_requested_at`;
- `handoff_reason`;
- `assigned_external_user_id`;
- `assigned_external_user_name`;
- `assigned_at`;
- `closed_at`.

New code should prefer `service_mode` and `handoff_status` for routing decisions, while the older fields remain useful for timestamps, display and compatibility.

## Limitations

- No return from human service to AI yet.
- No supervised AI response approval.
- No realtime/presence.
- No transfer between attendants.
- No SLA or advanced audit trail.
