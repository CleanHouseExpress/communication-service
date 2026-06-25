# Eventos Realtime da Comunicacao

## Objetivo

O communication-service publica eventos resumidos para atualização futura da
Inbox. Esta fase entrega apenas eventos Laravel broadcastáveis; não instala nem
configura Echo, Pusher, Ably ou frontend WebSocket.

## Configuração

```env
COMMUNICATION_REALTIME_ENABLED=false
COMMUNICATION_REALTIME_QUEUE=communication-realtime
```

Com a flag desligada, nenhum evento realtime é despachado. Quando ligada, os
eventos usam a fila configurada e o driver de broadcast definido pelo ambiente.

O projeto inclui somente os drivers `log` e `null`. O `.env.example` usa
`BROADCAST_CONNECTION=log` para validação local. Um driver WebSocket deve ser
adicionado explicitamente em uma fase futura.

## Canais

Cada evento é publicado simultaneamente em dois canais privados:

```text
tenant.{tenantId}.communication
conversation.{conversationId}
```

O canal do tenant permite atualizar listas, contadores e ordenação da Inbox. O
canal da conversa permite atualizar a conversa aberta com menor volume.

## Autorização

Os canais são `PrivateChannel`. A autorização de usuário, RBAC/TBAC e vínculo com
tenant continuam sendo responsabilidade da orchestra-api.

Esta sprint não expõe endpoint de autenticação de broadcasting. Uma integração
futura deve autenticar o usuário na orchestra-api e autorizar:

- canal tenant somente para usuários com acesso ao tenant;
- canal conversation somente quando a conversa pertence ao tenant autorizado.

O service token interno não deve ser entregue ao navegador.

## Eventos

- `conversation.created`
- `conversation.updated`
- `conversation.assigned`
- `conversation.returned_to_ai`
- `conversation.closed`
- `conversation.reopened`
- `conversation.handoff_requested`
- `message.received`
- `message.sent`
- `message.status_updated`
- `timeline.updated`

As classes Laravel correspondentes implementam `ShouldBroadcast`, `ShouldQueue` e
disparo após commit.

## Payload

Envelope comum:

```json
{
  "tenant_id": "tenant-1",
  "conversation_id": "uuid",
  "event": "message.status_updated",
  "timestamp": "2026-06-25T22:00:00-03:00",
  "resource": {
    "id": "message-uuid",
    "direction": "outbound",
    "message_type": "text",
    "status": "delivered",
    "delivered_at": "2026-06-25T22:00:00-03:00"
  }
}
```

Recursos de conversa incluem somente estado operacional, assignment e referências.
Recursos de mensagem incluem conteúdo e lifecycle necessários à Inbox. Timeline
inclui metadata sanitizada.

Nunca são publicados:

- payload bruto;
- headers;
- tokens, secrets ou senhas;
- `provider_response`;
- request/response completos do agente;
- prompt de IA.

## Integrações Atuais

Publicação automática ocorre após:

- criação e atualização por inbound;
- mensagem inbound recebida;
- envio outbound confirmado;
- assign;
- return-to-ai;
- close e reopen;
- solicitação de handoff;
- alteração de delivery status;
- gravação de timeline.

Falha de publicação não interrompe o fluxo operacional principal.

## Futuras Integrações

1. Definir driver de broadcast da infraestrutura.
2. Criar autenticação de canais na orchestra-api.
3. Consumir os eventos com Echo ou cliente equivalente.
4. Implementar reconexão e ressincronização via APIs internas.
5. Adicionar métricas de fila e latência realtime.

## Limitações

- Sem frontend ou cliente WebSocket.
- Sem presença, typing ou heartbeat de usuário.
- Sem Pusher, Ably ou servidor WebSocket selecionado.
- Sem garantia de replay; reconexão deve consultar a API.
- Sem ordenação global entre filas diferentes.
