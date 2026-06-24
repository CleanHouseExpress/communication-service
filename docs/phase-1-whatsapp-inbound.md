# Phase 1 - WhatsApp/Z-API Inbound

## Objetivo

Implementar o fluxo minimo inbound WhatsApp/Z-API dentro do `communication-gateway`, ainda sem depender da `orchestra-api` e sem copiar codigo do legacy.

Esta fase cria a base tecnica para receber webhooks de provider, registrar payload bruto, normalizar uma mensagem inbound e persistir um estado local simples de canal, contato, conversa e mensagem.

## Fluxo Inbound Z-API

```txt
Z-API
  |
  v
POST /api/providers/zapi/webhook
  |
  v
ZapiWebhookRequest
  |
  |-- valida payload nao vazio
  |-- exige identificador de remetente
  v
ProcessProviderWebhookAction
  |
  |-- extrai external_event_id/external_message_id
  |-- cria ou reutiliza communication_raw_events
  |-- evita reprocessar evento ja processado
  v
ZapiWebhookNormalizer
  |
  |-- aceita messageId, message_id ou id
  |-- aceita phone, from, sender ou participantPhone
  |-- aceita text.message, text, message ou body
  |-- define message_type defensivamente
  v
ProcessInboundMessageAction
  |
  |-- cria/reutiliza canal
  |-- cria/reutiliza contato
  |-- cria/reutiliza conversa aberta
  |-- cria mensagem inbound se nao houver duplicidade
  |-- atualiza last_message_at
  v
HTTP 200 idempotente
```

## Endpoint Interno Local

`POST /api/internal/inbound/messages` representa o contrato futuro em que a Orchestra ou um communication-service recebera mensagens normalizadas.

Nesta fase ele persiste localmente no mesmo banco do gateway. O endpoint exige token de servico e usa o mesmo `ProcessInboundMessageAction` do webhook.

## Separacao Futura

Nesta etapa o repositorio ainda funciona como servico inicial de comunicacao. A separacao final deve manter:

- Gateway tecnico: provider adapters, webhooks, raw events, normalizacao, idempotencia tecnica, delivery status, retries e rate limits.
- Orchestra/communication-service: tenant, inbox, conversas de negocio, atendentes, departamentos, CRM, realtime, RBAC, auditoria e analytics.

## Implementado Agora

- Webhook provider `POST /api/providers/zapi/webhook`.
- Endpoint interno `POST /api/internal/inbound/messages`.
- Migrations minimas para raw events, channels, contacts, conversations e messages.
- Models Eloquent correspondentes.
- Enums de provider, direcao, tipo, status de conversa e status de mensagem.
- DTO `InboundMessageData`.
- Normalizer defensivo para payload Z-API.
- Actions `ProcessProviderWebhookAction` e `ProcessInboundMessageAction`.
- Testes de webhook, idempotencia e endpoint interno.

## Fica Para Depois

- Envio outbound real para Z-API.
- n8n/agente.
- WebSocket/realtime.
- Multi-tenancy por banco.
- Painel/inbox.
- Atendimento humano.
- Filas complexas e retry policy completa.
- Media storage definitivo.
- Status de entrega provider.
- Contratos HTTP reais com a `orchestra-api`.

