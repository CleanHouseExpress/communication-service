# Provider Contract - Z-API

## POST /api/providers/zapi/webhook

Finalidade: receber eventos inbound WhatsApp/Z-API, persistir raw event, normalizar mensagem e criar estado local minimo.

Em `local` e `testing`, a assinatura e permissiva para facilitar desenvolvimento. Em producao, quando `COMMUNICATION_ZAPI_ENABLED=true`, o middleware exige configuracao de `COMMUNICATION_ZAPI_WEBHOOK_SECRET` e header definido por `COMMUNICATION_ZAPI_WEBHOOK_SIGNATURE_HEADER`.

Headers esperados em producao:

```http
Accept: application/json
X-Zapi-Signature: <token-ou-hmac>
```

O valor pode ser o segredo configurado ou um HMAC SHA-256 do corpo bruto usando o segredo.

## Payloads Aceitos Defensivamente

O normalizer aceita variacoes comuns:

- identificador de mensagem: `messageId`, `message_id`, `id`;
- identificador de evento: `eventId`, `event_id`, `webhookId`, `webhook_id`;
- contato/remetente: `phone`, `from`, `sender`, `participantPhone`;
- nome: `senderName`, `sender_name`, `name`;
- texto: `text.message`, `text`, `message`, `body`;
- timestamp: `timestamp`, `momment`, `createdAt`.

Exemplo:

```json
{
  "messageId": "zapi-message-1",
  "eventId": "zapi-event-1",
  "phone": "5541999999999",
  "senderName": "Maria Cliente",
  "text": {
    "message": "Oi, preciso de ajuda"
  },
  "fromMe": false,
  "isGroup": false,
  "timestamp": "2026-06-24T12:00:00-03:00"
}
```

## Campos Normalizados

O gateway converte o webhook em mensagem inbound com:

- `provider=zapi`;
- `external_event_id`;
- `external_message_id`;
- `external_contact_id`;
- `contact_name`;
- `contact_phone`;
- `message_type`;
- `text`;
- `occurred_at`;
- `raw_payload`.

## Comportamento Idempotente

O processamento evita duplicidade em duas camadas:

- `communication_raw_events` reutiliza evento quando `external_event_id` ja existe;
- `communication_messages` reutiliza mensagem quando `provider + external_message_id` ja existe.

Se o mesmo evento chegar novamente, o endpoint retorna `200` e `duplicate=true`.

Se eventos diferentes carregarem o mesmo `external_message_id`, o raw event pode ser registrado separadamente, mas a mensagem canonica nao e duplicada.

## Resposta Exemplo

```json
{
  "status": "processed",
  "duplicate": false,
  "raw_event_id": "uuid",
  "message_id": "uuid"
}
```

## Erros Esperados

- `403` assinatura/token de provider invalido em ambiente protegido;
- `422` payload sem dados suficientes para normalizacao;
- `429` se o rate limit de webhook for excedido.

## Limitacoes Atuais

- Sem callback de delivery/read.
- Sem media storage definitivo.
- Sem retry ou fila para processamento.
- Sem assinatura validada contra especificacao oficial final da Z-API.
- Sem deduplicacao multi-provider alem dos campos locais atuais.
