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

## GET /api/internal/inbox/conversations

Finalidade: listar conversas internas por tenant.

Query obrigatoria:

- `tenant_id`

Filtros opcionais:

- `status`
- `assignment_status`: `unassigned` ou `assigned`
- `assigned_external_user_id`
- `handoff`: `requested` ou `none`
- `has_handoff_requested`: boolean (`true`, `false`, `1`, `0`)
- `closed`: boolean (`true`, `false`, `1`, `0`)
- `last_message_from`: `inbound` ou `outbound`
- `updated_since`: date
- `sort`: `last_message_at`, `created_at` ou `updated_at`
- `direction`: `asc` ou `desc`
- `contact_id`
- `channel_id`
- `search`
- `page`
- `per_page`

Ordenacao padrao: `last_message_at desc`.

Exemplo:

```http
GET /api/internal/inbox/conversations?tenant_id=tenant-1&handoff=requested&assignment_status=unassigned
GET /api/internal/inbox/conversations?tenant_id=tenant-1&assigned_external_user_id=user-123&closed=false
GET /api/internal/inbox/conversations?tenant_id=tenant-1&last_message_from=inbound&sort=updated_at&direction=desc
```

Resposta exemplo:

```json
{
  "data": [
    {
      "id": "uuid",
      "tenant_id": "tenant-1",
      "status": "pending",
      "assignment_status": "unassigned",
      "has_handoff_requested": true,
      "handoff_requested_at": "2026-06-24T12:00:00-03:00",
      "assigned_external_user_id": null,
      "closed_at": null
    }
  ]
}
```

## GET /api/internal/inbox/summary

Finalidade: retornar contadores agregados simples do inbox para abas/badges do painel sem pesar a listagem paginada.

Query obrigatoria:

- `tenant_id`

Filtros opcionais:

- `assigned_external_user_id`: quando enviado, calcula `total_my_assigned`

Resposta exemplo:

```json
{
  "data": {
    "total_open": 10,
    "total_pending": 3,
    "total_closed": 20,
    "total_unassigned": 5,
    "total_assigned": 8,
    "total_handoff_requested": 2,
    "total_my_assigned": 4,
    "total_inbound_last_message": 6,
    "total_outbound_last_message": 7
  }
}
```

Quando `assigned_external_user_id` nao for enviado, `total_my_assigned` retorna `null`.

Erros esperados:

- `401` token ausente;
- `403` token invalido;
- `422` query invalida.

Observacao: o endpoint retorna somente contadores e nao inclui payload bruto, mensagens, contatos, tokens ou dados sensiveis.

## GET /api/internal/inbox/conversations/{conversation_id}

Finalidade: retornar uma conversa especifica do tenant.

Query obrigatoria:

- `tenant_id`

## GET /api/internal/inbox/conversations/{conversation_id}/messages

Finalidade: listar mensagens de uma conversa do tenant.

Query obrigatoria:

- `tenant_id`

Filtros opcionais:

- `direction`
- `message_type`
- `page`
- `per_page`

Observacao: estes endpoints sao internos e nao implementam auth de usuario, RBAC ou TBAC. Essas permissoes ficam na `orchestra-api`.

## POST /api/internal/inbox/conversations/{conversation_id}/request-handoff

Finalidade: marcar conversa como aguardando atendimento humano.

Payload:

```json
{
  "tenant_id": "tenant-1",
  "reason": "Cliente pediu atendimento humano"
}
```

## POST /api/internal/inbox/conversations/{conversation_id}/assign

Finalidade: registrar atribuicao operacional para um usuario externo da Orchestra.

Payload:

```json
{
  "tenant_id": "tenant-1",
  "external_user_id": "user-123",
  "external_user_name": "Atendente"
}
```

## POST /api/internal/inbox/conversations/{conversation_id}/close

Finalidade: fechar conversa operacionalmente.

Payload:

```json
{
  "tenant_id": "tenant-1",
  "reason": "Resolvido"
}
```

## POST /api/internal/inbox/conversations/{conversation_id}/reopen

Finalidade: reabrir conversa fechada.

Payload:

```json
{
  "tenant_id": "tenant-1"
}
```

Observacao: estes endpoints nao implementam usuarios, permissoes, RBAC ou TBAC. Eles apenas registram estado operacional.

## POST /api/internal/tenants/sync

Finalidade: sincronizar a replica minima de tenant/rede vinda da `orchestra-api`.

Payload exemplo:

```json
{
  "orchestra_tenant_id": "tenant-1",
  "name": "Rede Exemplo",
  "slug": "rede-exemplo",
  "status": "active",
  "timezone": "America/Sao_Paulo",
  "metadata": {}
}
```

Resposta exemplo:

```json
{
  "tenant_id": "uuid-local",
  "orchestra_tenant_id": "tenant-1",
  "status": "active",
  "synced_at": "2026-06-24T12:00:00-03:00"
}
```

Erros esperados:

- `401` token ausente;
- `403` token invalido;
- `422` payload invalido.

Observacao: este endpoint apenas replica dados minimos no landlord do communication-service. Ele nao cria banco tenant, nao copia usuarios e nao replica RBAC/TBAC.

## POST /api/internal/tenants/{orchestra_tenant_id}/provision-database

Finalidade: preparar a connection de banco de comunicacao para um tenant ativo.

Payload: vazio.

Resposta exemplo:

```json
{
  "tenant_id": "uuid-local",
  "orchestra_tenant_id": "tenant_123",
  "connection_id": "uuid",
  "database_name": "communication_tenant_rede_exemplo",
  "database_host": "db.example.internal",
  "database_port": 3306,
  "database_driver": "mysql",
  "status": "skipped",
  "migrated_at": null
}
```

Erros esperados:

- `401` token ausente;
- `403` token invalido;
- `422` tenant inexistente ou desabilitado.

Observacao: com `COMMUNICATION_TENANT_DB_PROVISIONING_ENABLED=false`, nenhum banco fisico e criado. A connection fica registrada como `skipped`.

Migrations de tenant ainda sao operacionais via CLI, nao endpoint HTTP:

```bash
php artisan communication:tenant:migrate {orchestra_tenant_id}
```

## POST /api/internal/orchestra/events/tenants

Finalidade: receber eventos internos da `orchestra-api` para sincronizacao idempotente da replica minima de tenants.

Eventos suportados:

- `TenantCreated`
- `TenantUpdated`
- `TenantDisabled`
- `TenantEnabled`

Payload exemplo:

```json
{
  "event_id": "evt_123",
  "event_type": "TenantCreated",
  "occurred_at": "2026-06-24T15:00:00-03:00",
  "tenant": {
    "id": "tenant_123",
    "name": "Rede Exemplo",
    "slug": "rede-exemplo",
    "status": "active",
    "timezone": "America/Sao_Paulo",
    "metadata": {}
  }
}
```

Resposta exemplo:

```json
{
  "integration_event_id": "uuid",
  "event_id": "evt_123",
  "status": "processed",
  "tenant_id": "uuid-local",
  "idempotent": false
}
```

Erros esperados:

- `401` token ausente;
- `403` token invalido;
- `422` payload invalido.

Idempotencia:

- Eventos sao deduplicados por `source=orchestra-api` e `event_id`.
- Reenvio do mesmo `event_id` retorna `idempotent=true` e nao reaplica a alteracao.

Contrato detalhado: `docs/contracts/orchestra-events.md`.
