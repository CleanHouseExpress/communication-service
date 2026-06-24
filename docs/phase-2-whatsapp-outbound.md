# Phase 2 - WhatsApp/Z-API Outbound

## Objetivo

Implementar o fluxo minimo outbound WhatsApp/Z-API dentro do `communication-gateway`, mantendo o envio local, idempotente e ainda sem integrar com a `orchestra-api`.

Esta fase cria o contrato interno para solicitar envio de mensagem, registra a tentativa em uma tabela propria de outbound, cria a mensagem local com direcao `outbound` e chama um client Z-API simples. Por padrao o client roda em modo fake para permitir desenvolvimento e teste sem trafego externo.

## Fluxo Outbound

```txt
Sistema interno
  |
  v
POST /api/internal/outbound/messages
  |
  |-- exige X-Service-Token
  |-- valida tenant, canal, conversa, contato e texto
  v
ProcessOutboundMessageAction
  |
  |-- procura communication_outbound_messages por idempotency_key
  |-- se existir, retorna o registro sem duplicar envio local
  |-- cria communication_messages com direction=outbound e status=pending
  |-- cria communication_outbound_messages com status=pending
  |-- marca ambos como sending
  v
ZapiClient::sendText()
  |
  |-- COMMUNICATION_ZAPI_FAKE=true: retorna sucesso fake sem HTTP
  |-- COMMUNICATION_ZAPI_FAKE_FAILURE=true: retorna falha fake
  |-- COMMUNICATION_ZAPI_FAKE=false: usa Laravel HTTP Client
  v
Atualizacao local
  |
  |-- sucesso: status=sent, provider_message_id, provider_response, sent_at
  |-- falha: status=failed, provider_response, failed_reason
```

## Endpoint Interno

`POST /api/internal/outbound/messages`

Headers:

```http
X-Service-Token: <COMMUNICATION_SERVICE_TOKEN>
Accept: application/json
```

Payload minimo:

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

Resposta de criacao:

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

Quando a mesma `idempotency_key` e reenviada, o endpoint retorna `200` com `duplicate=true` e nao cria outro `communication_outbound_messages` nem outro `communication_messages`.

## Persistencia Local

A migration `communication_outbound_messages` guarda a tentativa tecnica de envio:

- identificadores locais: `tenant_id`, `channel_id`, `conversation_id`, `contact_id`, `communication_message_id`;
- provider e destino: `provider`, `external_contact_id`;
- idempotencia: `idempotency_key` unica;
- conteudo minimo: `message_type`, `text`, `payload`;
- estado local: `pending`, `sending`, `sent` ou `failed`;
- retorno provider: `provider_message_id`, `provider_response`, `failed_reason`, `sent_at`.

A tabela `communication_messages` continua sendo o historico local da conversa. Nesta fase, uma mensagem outbound e criada junto com a tentativa de envio e tem seu status atualizado para refletir o resultado local do `ZapiClient`.

## Configuracao Z-API

Variaveis adicionadas ao `.env.example`:

```env
COMMUNICATION_ZAPI_BASE_URL=
COMMUNICATION_ZAPI_INSTANCE_ID=
COMMUNICATION_ZAPI_TOKEN=
COMMUNICATION_ZAPI_CLIENT_TOKEN=
COMMUNICATION_ZAPI_FAKE=true
COMMUNICATION_ZAPI_FAKE_FAILURE=false
```

Com `COMMUNICATION_ZAPI_FAKE=true`, nenhuma chamada HTTP externa e feita. Com `COMMUNICATION_ZAPI_FAKE=false`, o client usa `COMMUNICATION_ZAPI_BASE_URL` ou monta a URL com `COMMUNICATION_ZAPI_INSTANCE_ID` e `COMMUNICATION_ZAPI_TOKEN`, enviando `Client-Token` no header.

## Limitacoes Desta Fase

- Apenas mensagem de texto.
- Sem media, audio, documento ou template.
- Sem fila, retry policy, backoff ou rate limit de provider.
- Sem callback de entrega/leitura.
- Sem WebSocket/realtime.
- Sem integracao real com Orchestra ou n8n.
- Sem multi-tenancy por banco.
- A chamada ao provider ainda acontece de forma sincrona no fluxo HTTP.

## Proximos Passos

- Mover envio real para fila com retries e timeout controlado.
- Criar callback de status Z-API para atualizar delivery/read.
- Definir contrato HTTP com a Orchestra.
- Adicionar suporte a media depois de decidir storage e antivirus.
- Separar metricas tecnicas de provider das metricas de conversa de negocio.
