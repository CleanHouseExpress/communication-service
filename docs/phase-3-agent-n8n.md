# Phase 3 - Agent n8n/IA Bridge

## Objetivo

Implementar uma ponte minima entre mensagens inbound text e um agente externo, inicialmente n8n/IA, sem introduzir filas, painel de atendimento ou memoria longa.

Nesta fase o agente e opcional e controlado por configuracao. A criacao da mensagem inbound continua sendo prioridade: se o agente falhar, o webhook ou endpoint inbound deve continuar respondendo com sucesso quando a mensagem foi persistida corretamente.

## Quando o Agente Sera Acionado

O agente e acionado automaticamente ao final do fluxo inbound quando todas as condicoes abaixo forem verdadeiras:

- `COMMUNICATION_AGENT_ENABLED=true`;
- a mensagem inbound foi criada agora, ou seja, nao e duplicata;
- `direction=inbound`;
- `message_type=text`.

Tambem existe um endpoint interno para disparo manual:

`POST /api/internal/agent/runs`

```json
{
  "message_id": "uuid"
}
```

Esse endpoint exige `X-Service-Token` e aceita apenas mensagens inbound existentes.

## Payload Enviado ao Agente

O `DispatchMessageToAgentAction` monta um `AgentRequestData` com o contexto minimo:

```json
{
  "tenant_id": "tenant-1",
  "conversation_id": "uuid",
  "message_id": "uuid",
  "contact_id": "uuid",
  "channel_id": "uuid",
  "provider": "zapi",
  "text": "Oi, preciso de ajuda",
  "message_type": "text",
  "contact_name": "Maria Cliente",
  "contact_phone": "5541999999999",
  "history": [],
  "metadata": {
    "direction": "inbound",
    "occurred_at": "2026-06-24T12:00:00-03:00"
  }
}
```

O historico ainda e vazio nesta fase. Ele existe no contrato para permitir evolucao futura sem mudar o formato basico.

## Payload Esperado de Retorno

O client aceita uma resposta JSON simples do n8n:

```json
{
  "success": true,
  "response_text": "Claro, posso ajudar. Qual unidade voce prefere?",
  "should_reply": true,
  "should_handoff": false
}
```

Campos equivalentes `text` ou `message` tambem podem ser usados como texto de resposta. Se `should_reply` nao vier, o gateway assume `true` quando houver texto.

Em caso de falha, a execucao local fica `failed` e o inbound original permanece criado:

```json
{
  "success": false,
  "error": "motivo da falha"
}
```

## Como a Resposta Vira Outbound

Quando o agente retorna `success=true`, `should_reply=true` e `response_text` preenchido, a action chama `ProcessOutboundMessageAction`.

O outbound gerado usa:

- mesma `tenant_id`, `channel_id`, `conversation_id` e `contact_id` da mensagem inbound;
- `external_contact_id` baseado no telefone/external id do contato;
- `message_type=text`;
- `text=response_text`;
- `idempotency_key=agent-run:{communication_agent_runs.id}`.

Assim, uma execucao especifica do agente nao duplica o outbound se a action for reprocessada com o mesmo `agent_run_id`.

## Registro Local

A migration `communication_agent_runs` registra cada tentativa:

- origem: `tenant_id`, `conversation_id`, `message_id`, `provider`;
- agente: `agent`, atualmente `n8n`;
- estado: `pending`, `running`, `completed`, `failed` ou `skipped`;
- payloads: `request_payload`, `response_payload`;
- resposta: `response_text`, `failed_reason`;
- tempos: `started_at`, `finished_at`.

## Configuracao

```env
COMMUNICATION_AGENT_ENABLED=false
COMMUNICATION_AGENT_PROVIDER=n8n
COMMUNICATION_AGENT_FAKE=true
COMMUNICATION_AGENT_FAKE_FAILURE=false
COMMUNICATION_N8N_WEBHOOK_URL=
COMMUNICATION_N8N_TOKEN=
COMMUNICATION_N8N_TIMEOUT=15
```

Com `COMMUNICATION_AGENT_ENABLED=false`, o agente nao e acionado automaticamente pelo inbound.

Com `COMMUNICATION_AGENT_FAKE=true`, nenhuma chamada HTTP externa e feita. O client retorna uma resposta fake com `should_reply=true`.

Com `COMMUNICATION_AGENT_FAKE_FAILURE=true`, a execucao do agente fica `failed`, mas a mensagem inbound continua sendo processada.

Com `COMMUNICATION_AGENT_FAKE=false`, o client faz `POST` para `COMMUNICATION_N8N_WEBHOOK_URL` usando Laravel HTTP Client. Se `COMMUNICATION_N8N_TOKEN` estiver preenchido, ele e enviado como Bearer token.

## Limitacoes Desta Fase

- Sem filas/queues.
- Sem retry, backoff ou dead letter.
- Sem streaming.
- Sem WebSocket/realtime.
- Sem handoff humano real; `should_handoff=true` fica apenas registrado no payload.
- Sem painel/inbox.
- Sem multi-tenancy por banco.
- Sem memoria avancada ou contexto longo.
- Sem tools/function calling.
- Sem media.
- O envio outbound da resposta ainda usa o fluxo sincrono minimo da Fase 2.

## Proximos Passos

- Mover dispatch do agente para fila.
- Definir contrato formal entre gateway, n8n e Orchestra.
- Implementar historico curto da conversa no `AgentRequestData`.
- Criar callbacks/telemetria para runs do agente.
- Tratar handoff humano como estado de conversa na camada de negocio.
