# Eventos Realtime da Comunicacao

## Objetivo

O communication-service publica eventos resumidos para atualizacao futura da
Inbox. Ele nao autentica usuarios e nao decide permissoes de tenant ou conversa.

O realtime permanece desligado por padrao:

```env
COMMUNICATION_REALTIME_ENABLED=false
COMMUNICATION_REALTIME_QUEUE=communication-realtime
```

Quando a flag esta desligada, nenhum evento de broadcast e despachado e nenhuma
configuracao valida de Reverb e exigida.

## Canais

Cada evento e publicado em dois canais privados:

```text
tenant.{tenantId}.communication
conversation.{conversationId}
```

- O canal tenant atualiza listagens, contadores e ordenacao da Inbox.
- O canal conversation atualiza a conversa atualmente aberta.

No Laravel/Echo, o prefixo tecnico `private-` e tratado pelo broadcaster. O
frontend assina os nomes acima usando `Echo.private(...)`.

## Eventos

| Classe Laravel | Nome transmitido |
| --- | --- |
| `ConversationCreated` | `conversation.created` |
| `ConversationUpdated` | `conversation.updated` |
| `ConversationAssigned` | `conversation.assigned` |
| `ConversationReturnedToAi` | `conversation.returned_to_ai` |
| `ConversationClosed` | `conversation.closed` |
| `ConversationReopened` | `conversation.reopened` |
| `ConversationHandoffRequested` | `conversation.handoff_requested` |
| `MessageReceived` | `message.received` |
| `MessageSent` | `message.sent` |
| `MessageStatusUpdated` | `message.status_updated` |
| `TimelineUpdated` | `timeline.updated` |

Todas as classes implementam `ShouldBroadcast`, `ShouldQueue` e
`ShouldDispatchAfterCommit`. A fila e definida por
`COMMUNICATION_REALTIME_QUEUE`.

## Envelope Comum

```json
{
  "tenant_id": "tenant-1",
  "conversation_id": "conversation-uuid",
  "event": "message.status_updated",
  "timestamp": "2026-06-25T22:00:00-03:00",
  "resource": {}
}
```

### Recurso de conversa

```json
{
  "id": "conversation-uuid",
  "status": "open",
  "service_mode": "human",
  "handoff_status": "assigned",
  "channel_id": "channel-uuid",
  "contact_id": "contact-uuid",
  "assigned_external_user_id": "user-123",
  "assigned_external_user_name": "Maria",
  "last_message_at": "2026-06-25T22:00:00-03:00",
  "updated_at": "2026-06-25T22:00:00-03:00"
}
```

### Recurso de mensagem

```json
{
  "id": "message-uuid",
  "direction": "outbound",
  "message_type": "text",
  "text": "Ola",
  "status": "delivered",
  "provider": "zapi",
  "occurred_at": "2026-06-25T21:59:00-03:00",
  "sent_at": "2026-06-25T22:00:00-03:00",
  "delivered_at": "2026-06-25T22:00:05-03:00",
  "read_at": null,
  "failed_at": null
}
```

### Recurso de timeline

```json
{
  "id": "event-uuid",
  "event_type": "conversation_assigned",
  "actor_type": "human",
  "actor_name": "Maria",
  "description": "Conversation assigned to human.",
  "metadata": {},
  "occurred_at": "2026-06-25T22:00:00-03:00"
}
```

## Seguranca do Payload

Nunca sao publicados:

- payload bruto;
- headers;
- tokens, secrets ou senhas;
- `provider_response`;
- request/response completos do agente;
- prompt de IA.

O publisher remove essas chaves recursivamente antes do dispatch.

## Autorizacao

Os canais usam `PrivateChannel`, mas o communication-service nao implementa
autorizacao de usuario.

A orchestra-api deve:

1. autenticar o usuario;
2. extrair o tenant ativo do contexto autenticado;
3. para `tenant.{tenantId}.communication`, confirmar que o usuario possui acesso
   ao mesmo tenant;
4. para `conversation.{conversationId}`, consultar ou manter uma referencia que
   confirme que a conversa pertence ao tenant autorizado;
5. aplicar RBAC/TBAC antes de assinar a resposta de autenticacao do broadcaster.

O `tenantId` ou `conversationId` enviados pelo navegador nunca devem ser aceitos
como prova de acesso. O service token interno tambem nunca deve ser exposto ao
frontend.

## Publicacao Atual

Eventos sao publicados depois de:

- inbound e criacao de conversa;
- envio outbound;
- assign;
- return-to-ai;
- close e reopen;
- solicitacao de handoff;
- delivery receipt;
- gravacao de timeline.

Falha de publicacao nao interrompe o fluxo operacional.

## Limitacoes

- Sem endpoint local de auth de canais.
- Sem Echo ou frontend implementado.
- Sem presence, typing ou heartbeat.
- Sem dashboard ou metricas realtime.
- Sem replay garantido; apos reconexao, o frontend deve consultar as APIs.
- Sem ordenacao global entre eventos processados por filas diferentes.
