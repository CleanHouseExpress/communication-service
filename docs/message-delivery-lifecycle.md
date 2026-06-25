# Ciclo de Vida de Entrega de Mensagens

## Estados

Mensagens outbound seguem o ciclo operacional:

- `pending`: mensagem persistida, aguardando envio;
- `sending`: tentativa de envio ao provider em andamento;
- `sent`: provider aceitou a mensagem para envio;
- `delivered`: provider confirmou entrega ao dispositivo do destinatario;
- `read`: provider confirmou leitura;
- `failed`: envio falhou antes da confirmacao de entrega.

Os estados `received` e `processing` continuam existindo para fluxos inbound e
processamento interno, mas nao fazem parte da progressao de entrega outbound.

## Progressao

```text
pending -> sending -> sent -> delivered -> read
```

Callbacks repetidos sao idempotentes. Um callback atrasado nao rebaixa uma mensagem
de `read` para `delivered` ou de `delivered` para `sent`.

`failed` pode ser aplicado antes de `delivered`. Uma falha tardia nao substitui uma
confirmacao de entrega ou leitura ja registrada.

## Callback Z-API

```http
POST /api/providers/zapi/message-status
```

O endpoint usa a mesma autenticacao de assinatura configurada para webhooks Z-API.

```json
{
  "tenant_id": "tenant-1",
  "provider_message_id": "zapi-message-123",
  "external_message_id": "zapi-message-123",
  "status": "delivered",
  "timestamp": "2026-06-25T18:30:00-03:00"
}
```

Pelo menos um dos identificadores deve ser informado. `tenant_id` e opcional com o
runtime tenant desligado e necessario para resolver o banco correto quando o
runtime estiver habilitado.

Um identificador desconhecido retorna `processed=false` sem erro.

## Persistencia

O lifecycle e atualizado em `communication_outbound_messages` e
`communication_messages`. Os campos da mensagem sao:

- `provider_message_id`;
- `sent_at`;
- `delivered_at`;
- `read_at`;
- `failed_at`.

## Timeline

As transicoes registram:

- `message_sent`;
- `message_delivered`;
- `message_read`;
- `message_failed`.

A metadata contem apenas provider, status e identificador da mensagem no provider.
Payload bruto, headers, tokens e respostas completas nao sao armazenados no evento.

## Consulta Interna

```http
GET /api/internal/inbox/conversations/{conversation_id}/messages/status?tenant_id=tenant-1
X-Service-Token: ...
```

Cada item retorna somente `message_id`, `status`, `sent_at`, `delivered_at` e
`read_at`.

## Limitacoes

- Nao ha WebSocket ou push em tempo real.
- Nao ha analytics ou SLA.
- Nao ha reconciliacao periodica com o provider.
- O endpoint depende de o provider enviar um identificador conhecido.
- Com runtime tenant habilitado, o callback deve identificar o tenant.
