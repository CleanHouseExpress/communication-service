# Internal API Contract

Todos os endpoints internos usam prefixo `/api` e exigem token de servico, exceto endpoints publicos de health/version fora do escopo interno.

Headers comuns:

```http
Accept: application/json
X-Service-Token: <service-token>
```

Tambem e aceito:

```http
Authorization: Bearer <service-token>
```

Segredos nunca devem ser enviados em respostas.

## GET /api/internal/health

Finalidade: verificar saude autenticada do servico sem expor tokens.

Payload: nenhum.

Resposta exemplo:

```json
{
  "status": "ok",
  "app": {
    "ok": true,
    "service": "communication-gateway",
    "environment": "local"
  },
  "database": {
    "ok": true
  },
  "config": {
    "ok": true,
    "service_token_configured": true,
    "provider_webhook_configured": true
  },
  "agent": {
    "enabled": false,
    "fake": true
  },
  "zapi": {
    "enabled": false,
    "fake": true
  },
  "timestamp": "2026-06-24T12:00:00-03:00"
}
```

Erros esperados:

- `401` quando o token nao for enviado;
- `403` quando o token for invalido;
- `503` se o banco nao responder.

## POST /api/internal/inbound/messages

Finalidade: receber mensagem inbound ja normalizada de um sistema interno.

Payload exemplo:

```json
{
  "provider": "zapi",
  "external_event_id": "internal-event-1",
  "external_message_id": "internal-message-1",
  "external_contact_id": "5541888888888",
  "contact_name": "Contato Interno",
  "contact_phone": "5541888888888",
  "message_type": "text",
  "text": "Mensagem normalizada",
  "occurred_at": "2026-06-24T12:00:00-03:00",
  "raw_payload": {
    "source": "test"
  }
}
```

Resposta exemplo:

```json
{
  "status": "created",
  "channel_id": "uuid",
  "contact_id": "uuid",
  "conversation_id": "uuid",
  "message_id": "uuid"
}
```

Erros esperados:

- `401` token ausente;
- `403` token invalido;
- `422` payload invalido;
- `200` com `status=duplicate` quando a mensagem ja existir.

## POST /api/internal/outbound/messages

Finalidade: solicitar envio outbound minimo via provider configurado.

Payload exemplo:

```json
{
  "tenant_id": "tenant-1",
  "channel_id": "uuid",
  "conversation_id": "uuid",
  "contact_id": "uuid",
  "external_contact_id": "5511999999999",
  "message_type": "text",
  "text": "Ola, como posso ajudar?",
  "idempotency_key": "uuid-ou-string"
}
```

Resposta exemplo:

```json
{
  "status": "sent",
  "duplicate": false,
  "outbound_message_id": "uuid",
  "message_id": "uuid",
  "provider_message_id": "fake-zapi-...",
  "failed_reason": null
}
```

Erros esperados:

- `401` token ausente;
- `403` token invalido;
- `422` payload invalido;
- `200` com `duplicate=true` para mesma `idempotency_key`.

## POST /api/internal/agent/runs

Finalidade: disparar manualmente o agente para uma mensagem inbound existente.

Payload exemplo:

```json
{
  "message_id": "uuid"
}
```

Resposta exemplo:

```json
{
  "status": "completed",
  "agent_run_id": "uuid",
  "message_id": "uuid",
  "response_text": "Resposta automatica do agente.",
  "failed_reason": null
}
```

Erros esperados:

- `401` token ausente;
- `403` token invalido;
- `404` quando `message_id` nao existir como inbound;
- `422` payload invalido.

Observacao: o endpoint manual ainda nao tem idempotencia propria. Isso e aceitavel por enquanto porque ele e uma ferramenta interna de teste/operacao.
