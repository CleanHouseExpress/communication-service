# Legacy Messaging Assessment

## 1. Sumario Executivo

O modulo legado `ApiClin/app/Modules/Messaging` ja implementa um centro de atendimento omnichannel com WhatsApp/Z-API em uso real, estrutura preparada para Instagram/Meta, historico de mensagens, lock de conversa, transferencia entre atendentes, presenca de agentes, handoff humano, realtime por broadcast privado e ponte sincrona com n8n.

A recomendacao principal e nao migrar o modulo como esta. Ele mistura tres responsabilidades que devem ser separadas:

- Communication Gateway: infraestrutura tecnica de provider, webhook, normalizacao, envio externo, payload bruto, idempotencia tecnica, retries, rate limit, media e ponte tecnica com n8n/agentes.
- Orchestra API: dominio de negocio, tenants, canais, inbox, conversas, mensagens normalizadas, atendentes, departamentos, CRM/customer link, tarefas, automacoes, analytics, RBAC, auditoria e realtime da tela.
- Fora da Fase 1: campanhas, templates de negocio, analytics avancado, omnichannel completo, billing por mensagem, agente autonomo avancado e media storage definitivo.

O legado deve ser usado como fonte de comportamento e testes, nao como fonte de copia direta. A Fase 1 deve extrair apenas o caminho minimo WhatsApp: receber webhook Z-API, validar/autenticar, registrar raw event, normalizar, deduplicar, publicar inbound para Orchestra e enviar texto via Z-API quando Orchestra comandar.

## 2. Estrutura Encontrada no Legacy

Raiz analisada:

```txt
C:\repos\Clin\ApiClin\app\Modules\Messaging
```

Estrutura principal:

```txt
Channels/
  ChannelDriverInterface.php
  ConversationBroadcastChannel.php
  DriverResolver.php
  Instagram/InstagramDriver.php
  WhatsApp/WhatsAppDriver.php
  WhatsApp/ZapiAdapter.php
Controllers/
  AgentPresenceController.php
  ConversationController.php
  MessageController.php
  MessagingAttendantController.php
  MessagingClientPanelController.php
  MessagingDashboardController.php
  MessagingMetricsController.php
  StartWhatsAppConversationController.php
  WebhookController.php
DTOs/
  IncomingMessageDTO.php
  OutgoingMessageDTO.php
  metrics DTOs
Events/
  ConversationUpdated.php
  MessageCreated.php
Exceptions/
  ConversationClosedException.php
  ConversationLockedByOtherUserException.php
  HumanMessageRequiresLockException.php
Http/Resources/
  ConversationResource.php
Listeners/
  BroadcastConversationUpdated.php
  BroadcastMessageCreated.php
Middleware/
  WebhookContextMiddleware.php
Models/
  AgentPresence.php
  ChatAgent.php
  Conversation.php
  ConversationAssignment.php
  MessageAudit.php
Providers/
  MessagingServiceProvider.php
Repositories/
  MessagingMetricsRepository.php
Services/
  AgentPresenceService.php
  AgentResponseNormalizer.php
  AssignmentService.php
  ConversationHandoffService.php
  ConversationService.php
  IADispatchService.php
  MessagingAttendantService.php
  MessagingClientPanelService.php
  MessagingDashboardService.php
  MessagingMetricsService.php
  MessagingService.php
Support/
  AlertThresholdMonitor.php
  MessagingLogger.php
  WebhookContext.php
```

Arquivos fora da pasta que fazem parte do fluxo:

- `routes/api/messaging.php`: rotas do modulo.
- `routes/api/webhooks.php`: rota legacy Z-API e rota generica `{channel}/{provider}`.
- `routes/api/n8n.php`: endpoints consumidos por n8n.
- `routes/channels.php`: canais privados de broadcast.
- `config/messaging.php`: config de canais, n8n, Instagram, provider default e webhook.
- `config/services.php`: credenciais Z-API e n8n.
- `config/broadcasting.php`: driver Pusher/Ably/Redis/log/null.
- `config/logging.php`: canal `messaging`.
- `app/Jobs/ProcessWhatsAppWebhookJob.php`: processamento assinc/sync de webhook WhatsApp.
- `app/Api/ZapiApi.php` e `app/Services/ZapiService.php`: client Z-API.
- `app/Http/Controllers/WhatsappWebhookController.php`: rota legacy Z-API com token.
- `app/Http/Controllers/WebhookController.php`: webhook generico por channel/provider.
- `app/Http/Controllers/N8NController.php` e `app/Http/Middleware/N8NAuth.php`: integracao n8n.
- `app/Utils/WhatsappWebhookUtils.php`: normalizacao/resolve user/contact pelo webhook.
- `tests/Feature/Messaging` e `tests/Unit/Messaging`: testes legados relevantes.

## 3. Capacidades Existentes

Capacidades confirmadas:

- Envio WhatsApp texto, imagem e audio via Z-API.
- Recebimento WhatsApp via webhook Z-API.
- Rota legacy de webhook Z-API com `X-Zapi-Token` ou `_token`.
- Rota nova `POST /api/messaging/webhook/whatsapp` sem autenticacao Sanctum.
- Rota generica `POST /api/webhook/{channel}/{provider}`.
- Normalizacao de payload Z-API para `IncomingMessageDTO`.
- Normalizacao de telefone brasileiro, incluindo insercao do nono digito quando Z-API envia 12 digitos.
- Resolucao/criacao de usuario e sincronizacao de `whatsapp_contacts`.
- Ignora mensagens de grupo e mensagens operacionais de OTP/login.
- Persistencia em `message_histories`.
- Idempotencia por `external_message_id` + `channel`.
- Conversas com status granular: `waiting_bot`, `waiting_human`, `in_progress`, `closed`.
- Modo de interacao: `bot` ou `human`.
- Atendimento humano com lock exclusivo por conversa.
- Transferencia entre atendentes.
- Release para bot.
- Fechamento de conversa.
- Contador de nao lidas.
- Mensagens internas/historico por conversa.
- Media outbound por URL/data URI para imagem/audio.
- Presenca de agentes em `agent_presence` com fallback para status legado em `users`.
- Lista de atendentes via `chat_agents`.
- Handoff humano solicitado por agente.
- Eventos de handoff em `messaging_handoff_events`.
- Sinais de funil/lead aplicados por resposta do agente.
- Metricas e dashboard de atendimento.
- Realtime com broadcast privado.
- Polling fallback em `GET /api/messaging/status/{conversation}`.
- Estrutura Instagram/Meta parcialmente preparada.
- Testes de conversation, lock, transfer, idempotency, webhook, logging, presence e broadcast.

Capacidades parcialmente existentes ou com lacunas:

- Status de entrega do provider nao aparece como fluxo dedicado; o legado salva `external_message_id`, mas nao ha tabela clara de delivery attempts/status callbacks.
- Retries existem apenas implicitamente via queue/job e comportamento de webhook; nao ha politica tecnica central de retries por provider.
- Rate limit de webhooks nao aparece no modulo.
- Assinatura Z-API nao implementada no driver; rota legacy usa token simples.
- Media inbound e tratada como texto sintetico (`[Image]`, `[Audio]`, etc.) ou payload bruto, sem storage definitivo.
- Campanhas/templates existem em tabelas antigas (`whats_templates`, `chat_quick_responses`), mas nao aparecem como parte central do Messaging Core moderno.

## 4. Fluxo Atual

Fluxo WhatsApp principal em `routes/api/messaging.php`:

```txt
Z-API
  |
  v
POST /api/messaging/webhook/whatsapp
  |
  v
WebhookController::whatsapp
  |
  |-- valida payload minimo
  |-- ignora grupos/OTP
  |-- valida IP opcional se WEBHOOK_VALIDATE_ORIGIN=true
  |-- resolve/cria User e whatsapp_contacts
  v
ProcessWhatsAppWebhookJob
  |
  |-- resolve/cria Conversation(provider=zapi)
  |-- parse IncomingMessageDTO
  |-- se fromMe=true: salva outbound proprio e broadcast
  v
WhatsAppDriver::handleConversation
  |
  v
MessagingService::handleConversation
  |
  |-- transacao
  |-- resolve/cria conversa
  |-- dedup por external_message_id + channel
  |-- salva MessageHistory
  |-- atualiza last_message/unread/status
  |-- se modo bot e waiting_bot: chama n8n
  |-- broadcast MessageCreated e ConversationUpdated
  v
IADispatchService::process
  |
  |-- POST N8N_WEBHOOK_URL com payload bruto + user_id
  |-- timeout N8N_TIMEOUT
  |-- normaliza resposta
  v
MessagingService::handleAgentResponse
  |
  |-- aplica sinais de funil/lead
  |-- se message_to_user: envia via Z-API
  |-- se needs_human_handoff: request handoff
  v
Z-API send-text/send-image/send-audio
  |
  v
MessageHistory outbound + broadcast
```

Fluxo generico legacy em `app/Http/Controllers/WebhookController.php`:

```txt
POST /api/webhook/{channel}/{provider}
  |
  v
DriverResolver resolve driver por config(messaging.channels)
  |
  |-- validateWebhook
  |-- normaliza audio/image via WhatsappWebhookUtils se whatsapp
  |-- resolve user/conversation
  |-- parse/salva message
  |-- atualiza conversa e broadcast
  |-- decide se chama n8n
```

Fluxo humano:

```txt
Atendente autenticado via Sanctum
  |
  v
GET /api/messaging/conversations
  |
  v
POST /api/messaging/conversations/{id}/lock
  |
  |-- exige agente online
  |-- lock_by_user_id + locked_at
  |-- interaction_mode=human
  |-- status=in_progress
  v
POST /api/messaging/messages
  |
  |-- exige status in_progress
  |-- exige lock do mesmo usuario
  |-- envia via driver provider
  |-- salva MessageHistory outbound
  |-- audit em message_audits
  v
Broadcast MessageCreated/ConversationUpdated
```

Fluxo handoff:

```txt
n8n retorna needs_human_handoff=true
  |
  v
ConversationHandoffService::requestFromAgent
  |
  |-- interaction_mode=human
  |-- status=waiting_human
  |-- handoff_status=requested
  |-- grava messaging_handoff_events
  v
Fila de atendimento via conversations.queue
```

## 5. Mapa de Arquivos Relevantes

Gateway candidates:

- `Channels/WhatsApp/ZapiAdapter.php`: adapter Z-API.
- `Channels/WhatsApp/WhatsAppDriver.php`: parse Z-API, envio provider, normalizacao de telefone, filtro de grupos/OTP.
- `app/Api/ZapiApi.php`: endpoints Z-API e headers.
- `app/Services/ZapiService.php`: wrapper atual.
- `Controllers/WebhookController.php`: receiver WhatsApp/Instagram por canal.
- `app/Http/Controllers/WhatsappWebhookController.php`: token legacy e rota antiga.
- `app/Http/Controllers/WebhookController.php`: receiver generico por channel/provider.
- `app/Jobs/ProcessWhatsAppWebhookJob.php`: processamento assinc de webhook.
- `DTOs/IncomingMessageDTO.php` e `DTOs/OutgoingMessageDTO.php`: shape inicial de normalizacao.
- `Services/IADispatchService.php`: ponte tecnica n8n.
- `Services/AgentResponseNormalizer.php`: normalizador de resposta de agente.
- `Support/MessagingLogger.php`, `Support/WebhookContext.php`, `Support/AlertThresholdMonitor.php`: observabilidade tecnica.

Orchestra candidates:

- `Models/Conversation.php`, `ConversationAssignment.php`, `AgentPresence.php`, `ChatAgent.php`, `MessageAudit.php`.
- `Services/ConversationService.php`, `MessagingService.php` na parte de estado da conversa, `AgentPresenceService.php`, `MessagingAttendantService.php`, `ConversationHandoffService.php`.
- `Controllers/ConversationController.php`, `MessageController.php`, `AgentPresenceController.php`, `MessagingAttendantController.php`, `MessagingDashboardController.php`, `MessagingMetricsController.php`, `MessagingClientPanelController.php`, `StartWhatsAppConversationController.php`.
- `Http/Resources/ConversationResource.php`.
- `Events/MessageCreated.php`, `Events/ConversationUpdated.php`.
- `routes/channels.php` e listeners de broadcast.
- Repositorio/servicos de dashboard e metricas.

Do not copy directly:

- Controllers legacy inteiros.
- Models Eloquent acoplados a `users` da ApiClin.
- Migrations como estao.
- Logs que registram payload bruto.
- Rotas com Sanctum/roles da ApiClin.

## 6. Mapa de Tabelas

### Tabelas modernas do Messaging Core

| Tabela | Finalidade | Colunas principais | Relacionamentos | Destino |
| --- | --- | --- | --- | --- |
| `messaging_conversations` | Estado de conversa/inbox | `id`, `client_id`, `channel`, `provider`, `external_user_id`, `interaction_mode`, `status`, `intent`, `locked_by_user_id`, `locked_at`, `unread_count`, `last_sender_type`, `handoff_*`, `funnel_stage`, `last_agent_payload`, timestamps | `users` por `client_id` e `locked_by_user_id`; `message_histories`; assignments; handoff events | Redesenhar em Orchestra como `communication_conversations`; manter apenas referencia tecnica no Gateway se necessario |
| `message_histories` | Historico de mensagens e payload | `provider`, `user_id`, `payload`, `conversation_id`, `channel`, `direction`, `sender_type`, `body`, `external_message_id`, `meta`, soft deletes | `messaging_conversations`; `users` | Separar: raw/payload tecnico no Gateway; mensagem normalizada de negocio na Orchestra |
| `messaging_conversation_assignments` | Historico de atribuicao humana | `conversation_id`, `user_id`, `assigned_at`, `released_at` | `messaging_conversations`, `users` | Orchestra |
| `message_audits` | Auditoria de mensagem humana | `message_id`, `conversation_id`, `user_id`, `sender_type`, `channel`, `action`, `had_lock`, `was_in_human_mode`, `context` | `message_histories`, `messaging_conversations`, `users` | Orchestra, integrado ao audit log |
| `whatsapp_contacts` | Mapa usuario -> telefone/JID WhatsApp | `user_id`, `phone`, `whatsapp_jid` | `users` | Redesenhar: provider identity no Gateway, customer link no Orchestra |
| `agent_presence` | Presenca de atendente | `user_id`, `status`, `last_seen_at` | `users` | Orchestra |
| `chat_agents` | Atendentes habilitados | `user_id`, `status_id`, `role_id`, `avatar`, `last_activity` | `users`, tabelas antigas de status/role | Orchestra; redesenhar como `communication_agents` ou usar RBAC/roles do Orchestra |
| `messaging_handoff_events` | Eventos de handoff | `conversation_id`, `user_id`, `event_type`, `reason`, `summary`, `metadata`, `created_at` | `messaging_conversations`, `users` | Orchestra |
| `clin_pro_funnel_events` | Eventos de funil ligados a conversa | `user_id`, `conversation_id`, `event_type`, `metadata`, `created_at` | `users`, `messaging_conversations` | Orchestra/CRM/analytics, fora da Fase 1 |

### Tabelas antigas de chat/atendimento

| Tabela | Finalidade | Colunas principais | Destino |
| --- | --- | --- | --- |
| `chat_conversations` | Conversas antigas antes do Messaging Core | `current_manager_id`, `client_user_id`, `status_id`, `chat_lead_id`, `is_group`, `image` | Nao migrar na Fase 1; usar apenas como referencia historica se houver dado produtivo |
| `chat_messages` | Mensagens antigas | `conversation_id`, `message`, `author_user_id`, `phone`, `senderName`, `chatName`, `status_id`, `chatLid` | Nao migrar na Fase 1 |
| `chat_message_statuses` | Catalogo de status antigo | `title` | Descartar/redesenhar |
| `chat_agent_statuses` | Catalogo antigo de status de agente | `title` | Descartar/redesenhar |
| `chat_agent_roles` | Catalogo antigo de roles de agente | `title` | Substituir por RBAC Orchestra |
| `chat_quick_responses` | Respostas rapidas | `title`, `message` | Orchestra, Fase 2/4 |
| `whats_templates` | Templates WhatsApp simples | `name`, `message` | Orchestra, Fase 4 |

### Modelo final proposto - Gateway Database

| Tabela proposta | Finalidade |
| --- | --- |
| `provider_connections` | Config tecnica por tenant/canal/provider: provider, instance_id, base_url, credentials ref, status |
| `provider_webhook_events` | Evento bruto recebido: provider, event_id/idempotency_key, tenant, headers sanitizados, payload sanitizado/encriptado, received_at, processed_at, status |
| `provider_messages` | Mensagem tecnica provider: direction, provider_message_id, normalized phone/JID, media refs, current delivery status |
| `delivery_attempts` | Tentativas de envio externo: request hash, status HTTP, provider response sanitizada, retry_count, next_retry_at |
| `raw_payload_logs` | Retencao curta/auditavel para debugging tecnico; sem PII aberta |
| `agent_runs` | Execucoes n8n/agente: trigger inbound id, request payload sanitizado, timeout, status, response summary |
| `agent_callbacks` | Callbacks/resultados de agente se houver async no futuro |

### Modelo final proposto - Orchestra Database

| Tabela proposta | Finalidade |
| --- | --- |
| `communication_channels` | Canais configurados por tenant/unidade: whatsapp, instagram, provider externo, status |
| `communication_inboxes` | Filas/inboxes por tenant, unidade, departamento |
| `communication_departments` | Departamentos de atendimento |
| `communication_conversations` | Estado de conversa, customer/link CRM, channel, status, assignment atual |
| `communication_messages` | Mensagens normalizadas de negocio, sem payload bruto |
| `communication_assignments` | Historico de assignment/transferencia |
| `communication_agent_presence` | Presenca/atividade de atendentes |
| `communication_handoff_events` | Eventos de handoff humano |
| `communication_templates` | Templates de negocio |
| `communication_campaigns` | Campanhas e disparos, Fase 4 |
| `communication_message_audits` | Auditoria de mensagens humanas |

## 7. Integracoes Externas

### Z-API

Configuracao atual:

- `config/services.php`: `ZAPI_TOKEN`, `ZAPI_INSTANCE`, `ZAPI_PRIVATE_TOKEN`, `ZAPI_WEBHOOK_TOKEN`.
- Base URL: `https://api.z-api.io/instances/{instance}/token/{token}`.
- Header: `Client-Token: {privateToken}`.
- Endpoints usados: `/send-text`, `/send-image`, `/send-audio`.

Riscos atuais:

- Credenciais ficam no monolito.
- Rota nova de webhook nao valida token; somente a rota legacy valida `X-Zapi-Token` ou `_token`.
- Rota legacy aceita token por input `_token`, o que deve ser removido no novo desenho.
- Alguns logs registram payload completo (`json_encode($payload)`), risco de PII e dados sensiveis.

### Instagram/Meta

Estrutura existe em `InstagramDriver`, config `INSTAGRAM_VERIFY_TOKEN`, `INSTAGRAM_APP_SECRET`, `INSTAGRAM_PAGE_ACCESS_TOKEN`, `INSTAGRAM_GRAPH_VERSION`, mas o proprio config marca o canal como desabilitado. Deve ficar fora da Fase 1.

### n8n/Agente

Configuracao atual:

- `config/services.php`: `N8N_WEBHOOK_URL`, default com URL produtiva hardcoded, e `N8N_TIMEOUT`.
- `config/messaging.php`: tambem define `n8n`, mas `IADispatchService` usa `services.n8n`.
- `routes/api/n8n.php`: endpoints protegidos por `N8NAuth`.
- `N8NAuth`: Bearer token `N8N_BEARER_TOKEN`, comparacao direta e bypass para `Accept: text/html`.

Fluxo atual:

- `IADispatchService` envia o payload bruto do webhook + `user_id`.
- Resposta e normalizada por `AgentResponseNormalizer`.
- Campos aceitos: `message_to_user`, `reply`, `message`, `output`, `text`, `needs_human_handoff`, `handoff_reason`, `handoff_summary`, `intent`, `suggested_funnel_stage`, `suggested_lead_status`, `detected_objection`, `next_action`.
- Se houver mensagem, bot envia ao provider.
- Se houver handoff, conversa vira `waiting_human`.

### Realtime/Broadcast

Configuracao atual:

- `config/broadcasting.php`: default `BROADCAST_DRIVER`, default `null`.
- Conexoes: `pusher`, `ably`, `redis`, `log`, `null`.
- `routes/channels.php`: `conversations.queue` e `conversations.{id}`, ambos privados e autorizados para qualquer usuario autenticado.
- Eventos: `MessageCreated` e `ConversationUpdated`, ambos `ShouldBroadcastNow`.

## 8. Realtime Atual

Tecnologia atual: Laravel Broadcasting com canais privados. Pode usar Pusher se `BROADCAST_DRIVER=pusher`, ou null/log por default.

Eventos emitidos:

- `MessageCreated`
  - Channels: `private-conversations.queue`, `private-conversations.{id}`.
  - Payload: `conversation` via `ConversationResource`, e `message` com `id`, `conversation_id`, `channel`, `direction`, `sender_type`, `body`, `external_message_id`, `created_at`.
- `ConversationUpdated`
  - Channels: mesmos canais.
  - Payload: `conversation` via `ConversationResource`.

Payload de `ConversationResource` inclui:

- conversation id, channel, provider, external_user_id;
- client com nome, phone, email, cpf, origem;
- interaction_mode, status, intent;
- handoff fields, funnel fields, detected objection, next action;
- timestamps, last sender, unread count;
- assignment atual;
- messages quando carregadas.

Decisao validada:

- Realtime de atendimento/tela deve ficar na Orchestra. Ele depende de usuario logado, RBAC, inbox, fila, atendimento humano, customer/CRM e frontend.
- Gateway deve emitir apenas eventos tecnicos internos ou callbacks para Orchestra, sem broadcast direto para tela.

## 9. n8n / Agente Atual

Como chama:

- Chamada sincrona HTTP `POST N8N_WEBHOOK_URL`.
- Timeout configuravel `N8N_TIMEOUT`, default 10s.
- Payload enviado: payload bruto do webhook Z-API/Meta com `user_id` adicionado.
- Nao ha tabela dedicada para run, timeout ou retry de agente.
- Falha retorna `null` e nao bloqueia a resposta do webhook.

Como recebe resposta:

- Resposta HTTP imediata no proprio request ao n8n.
- Nao ha callback principal async para mensagem do agente.
- Existe endpoint separado `POST /api/n8n/messaging/conversations/{conversation}/wait-human` para n8n pedir handoff.

Fallback humano:

- `needs_human_handoff=true` ou endpoint wait-human aciona `ConversationHandoffService`.
- A conversa muda para `interaction_mode=human`, `status=waiting_human`, `handoff_status=requested`.

Controle de sessao/contexto:

- O legado passa payload bruto e `user_id`, nao um contexto de conversa controlado.
- A resposta do agente pode conter sinais de funil e lead.
- Nao ha contrato versionado de agent session/run.

Arquitetura final recomendada:

```txt
Gateway recebe webhook provider
  |
  v
Gateway valida, registra raw event, normaliza e deduplica
  |
  v
Gateway -> Orchestra: inbound message normalized
  |
  v
Orchestra decide: bot, humano, fila, departamento, CRM
  |
  +--> se humano: atualiza inbox/realtime e nao chama agente
  |
  +--> se bot: Orchestra -> Gateway: start agent run
        |
        v
      Gateway chama n8n com contrato versionado
        |
        v
      Gateway recebe resposta/timeout
        |
        +--> se message_to_user: envia provider e notifica Orchestra
        +--> se handoff: notifica Orchestra para fila humana
```

Alternativa para Fase 1: manter agente fora e implementar apenas `Gateway -> Orchestra inbound` e `Orchestra -> Gateway send text`.

## 10. O Que Vai Para Gateway

Migrar/adaptar para Gateway:

- Z-API adapter: base URL, token, instance, client token.
- Provider abstraction por `MessageProviderInterface`.
- Webhook receiver publico de provider.
- Validacao de webhook por header/token/assinatura quando possivel.
- Normalizacao de payload Z-API.
- Extracao de body para text/audio transcription/image caption/document/location/video/contact.
- Filtro de grupos e mensagens operacionais OTP/login.
- Normalizacao de telefone/JID para provider identity.
- Registro de raw webhook event sanitizado.
- Idempotencia por `provider`, `provider_event_id`/`messageId`, tenant e canal.
- Envio externo: text na Fase 1; image/audio depois.
- Provider response e provider message id.
- Delivery/status provider quando Z-API enviar callbacks.
- Queue/retry tecnico de envio.
- Rate limit tecnico para webhooks e APIs internas.
- Ponte n8n/agente, se a decisao de negocio vier da Orchestra.
- Logs tecnicos sanitizados.

## 11. O Que Vai Para Orchestra

Migrar/redesenhar para Orchestra:

- Tenant settings e service tokens por tenant/canal.
- `communication_channels` e inboxes.
- Departamentos e roteamento de atendimento.
- Atendentes, presenca, disponibilidade, carga ativa.
- Conversas, estados, locks, transferencias e fechamento.
- Mensagens normalizadas de negocio.
- Relacao com customers, users, CRM leads e funil.
- Handoff humano e eventos de atendimento.
- Realtime para tela.
- RBAC/autorizacao de canais privados.
- Audit log de acoes humanas.
- Metricas de atendimento, dashboard e NOC.
- Templates e campanhas de negocio, em fases posteriores.

## 12. O Que Fica Fora da Fase 1

Fora da Fase 1:

- Instagram/Meta.
- Campanhas e broadcast marketing.
- Templates WhatsApp de negocio.
- Respostas rapidas.
- Media storage definitivo.
- Envio de imagem/audio/documento em producao, salvo se indispensavel.
- Delivery analytics avancado.
- Billing por mensagem.
- Omnichannel alem WhatsApp.
- Agente autonomo avancado, prompt/context manager e memoria longa.
- Multiprovedor complexo.
- Migracao historica completa de dados antigos.
- UI/Realtime de atendimento.
- CRM/funil profundo.
- Departamentos e roteamento sofisticado.

## 13. Proposta de Arquitetura Final

```txt
Provider WhatsApp/Z-API
  |
  v
Communication Gateway
  - public webhook
  - provider auth/signature
  - raw event log
  - normalization
  - idempotency
  - provider send/retry/status
  - optional n8n technical bridge
  |
  | service token + idempotency + correlation id
  v
Orchestra API
  - tenant context
  - communication channel/inbox
  - conversation state
  - customer/CRM link
  - assignment/departments
  - bot/human decision
  - realtime
  - audit/analytics
  |
  v
Frontend/Atendimento
```

Principios:

- Gateway nao conhece atendente, departamento, RBAC, CRM ou tela.
- Orchestra nao conhece token privado de provider nem payload bruto completo.
- Gateway sempre manda eventos idempotentes para Orchestra.
- Orchestra sempre envia comandos idempotentes para Gateway.
- Correlation ID deve atravessar provider webhook, Gateway, Orchestra, n8n e provider outbound.
- Tenant deve ser resolvido antes de gravar conversa de negocio.

## 14. Contratos Gateway <-> Orchestra

Headers comuns:

```http
Authorization: Bearer <service_token>
Content-Type: application/json
X-Service-Token: <opcional se nao usar Authorization>
X-Correlation-Id: <uuid>
Idempotency-Key: <stable-key>
X-Tenant-Id: <tenant uuid/id>
X-Tenant-Subdomain: <subdomain>
```

### Gateway -> Orchestra: inbound message

```http
POST /api/internal/communication/messages/inbound
```

Payload minimo:

```json
{
  "correlation_id": "uuid",
  "idempotency_key": "zapi:instance:messageId",
  "tenant": {
    "id": "tenant-id",
    "subdomain": "clin"
  },
  "channel": "whatsapp",
  "provider": "zapi",
  "provider_connection_id": "uuid",
  "provider_event_id": "messageId-or-webhook-id",
  "provider_message_id": "messageId",
  "direction": "inbound",
  "from": {
    "external_id": "5541999999999",
    "display_name": "Joao"
  },
  "to": {
    "external_id": "instance-phone-or-account"
  },
  "message": {
    "type": "text",
    "text": "Ola",
    "media": null
  },
  "occurred_at": "2026-06-24T12:00:00-03:00",
  "received_at": "2026-06-24T12:00:01-03:00",
  "raw_event_ref": "provider_webhook_events.id"
}
```

Expected response:

```json
{
  "accepted": true,
  "conversation_id": "uuid",
  "message_id": "uuid",
  "routing": {
    "mode": "bot",
    "department_id": null
  }
}
```

### Gateway -> Orchestra: delivery/status

```http
POST /api/internal/communication/messages/status
```

Payload minimo:

```json
{
  "correlation_id": "uuid",
  "idempotency_key": "zapi:status:provider_message_id:status",
  "tenant": {"id": "tenant-id", "subdomain": "clin"},
  "channel": "whatsapp",
  "provider": "zapi",
  "provider_message_id": "abc",
  "status": "sent|delivered|read|failed",
  "occurred_at": "2026-06-24T12:00:00-03:00",
  "reason": null
}
```

### Gateway -> Orchestra: agent result

```http
POST /api/internal/communication/agent/callback
```

Payload minimo:

```json
{
  "correlation_id": "uuid",
  "idempotency_key": "agent-run-id:result",
  "tenant": {"id": "tenant-id", "subdomain": "clin"},
  "conversation_id": "uuid",
  "agent_run_id": "uuid",
  "status": "completed|failed|timeout",
  "message_to_user": "Texto",
  "needs_human_handoff": false,
  "handoff_reason": null,
  "signals": {
    "intent": "pricing",
    "funnel_stage": "interest",
    "lead_status": "engaged",
    "detected_objection": null,
    "next_action": null
  }
}
```

### Orchestra -> Gateway: send message

```http
POST /api/internal/gateway/messages/send
```

Payload minimo:

```json
{
  "correlation_id": "uuid",
  "idempotency_key": "orchestra-message-id",
  "tenant": {"id": "tenant-id", "subdomain": "clin"},
  "channel": "whatsapp",
  "provider": "zapi",
  "provider_connection_id": "uuid",
  "conversation_id": "uuid",
  "message_id": "uuid",
  "recipient": {
    "external_id": "5541999999999"
  },
  "message": {
    "type": "text",
    "text": "Ola, como posso ajudar?"
  },
  "sender": {
    "type": "human|bot|system",
    "user_id": "uuid-or-null"
  }
}
```

Expected response:

```json
{
  "accepted": true,
  "gateway_message_id": "uuid",
  "provider_message_id": "abc",
  "status": "queued|sent"
}
```

### Orchestra -> Gateway: assign agent runtime

```http
POST /api/internal/gateway/conversations/{id}/assign-agent
```

Uso recomendado: somente se o Gateway for responsavel por executar n8n. Caso contrario, Orchestra decide internamente e chama `messages/send` quando houver resposta.

### Orchestra -> Gateway: handoff human

```http
POST /api/internal/gateway/conversations/{id}/handoff-human
```

Uso recomendado: evitar comando para Gateway se handoff for puramente negocio. Gateway deve apenas receber a decisao da Orchestra e parar/continuar agent runs tecnicos.

## 15. Riscos Tecnicos

- Acoplamento forte a `users` da ApiClin: conversas, atendentes, customers e whatsapp contacts usam a mesma tabela.
- `message_histories` mistura payload bruto tecnico com mensagem de negocio.
- Dois caminhos ativos de webhook WhatsApp: rota nova do modulo e rota legacy `/api/webhook/whatsapp/zapi/aoreceber`.
- Rota generica `POST /api/webhook/{channel}/{provider}` pode competir semanticamente com a rota nova.
- `config/messaging.php` e `config/services.php` duplicam config n8n; o servico usa `services.n8n`.
- `IADispatchService` e sincrono dentro do fluxo de mensagem; timeouts afetam processamento.
- Falhas de n8n retornam `null`, sem rastreabilidade dedicada de run/status.
- Estado de conversa e envio provider ficam na mesma transacao/servico.
- Fechamento de conversa chama URL n8n local hardcoded `http://127.0.0.1:5678/webhook/...`.
- Broadcast `ShouldBroadcastNow` pode aumentar latencia e acoplamento.
- Tests legados sao valiosos mas podem depender do schema/monolito inteiro.
- Alguns down migrations antigas tem nomes inconsistentes (`dropIfExists('conversations')`, `dropIfExists('messages')`), risco em ambientes de rollback.

## 16. Riscos de Seguranca

- Rota nova `POST /api/messaging/webhook/whatsapp` nao valida assinatura/token por padrao.
- `WhatsAppDriver::validateWebhook` retorna sempre true.
- Validacao por IP e opcional e default false.
- Rota legacy aceita token por body/query `_token`; no novo gateway isso deve ser proibido.
- `N8NAuth` compara token com `!==`, sem `hash_equals`.
- `N8NAuth` tem bypass para `Accept: text/html`; isso deve ser removido em APIs internas.
- `config/services.php` tem URL default produtiva de n8n hardcoded.
- Logs registram payload bruto do WhatsApp em pelo menos uma rota legacy.
- Logs podem conter telefone, mensagem, payload e user agent.
- Falta rate limit explicito em webhooks legados.
- Falta protecao formal contra replay alem da idempotencia de mensagem.
- Isolamento tenant nao existe no modulo legado; tudo parece banco monolitico.
- Canais privados de broadcast autorizam qualquer usuario autenticado, nao validam escopo/conversa.

Correcoes no novo desenho:

- Service token obrigatorio nas APIs internas.
- Provider webhook token/signature apenas por header.
- `hash_equals` para todos os tokens.
- Rate limit por grupo: provider webhooks, internal API, agent callbacks.
- Idempotency key obrigatoria e persistida.
- Correlation ID obrigatorio.
- Sanitizacao antes de logs.
- Raw payload com retencao curta e acesso restrito.
- Tenant resolvido antes de publicar evento a Orchestra.
- Broadcast apenas na Orchestra com RBAC/tenant/departamento.
- Agent runs com timeout, status e retry controlados.

## 17. Plano Faseado

### Fase 1 - Foundation Gateway WhatsApp

Objetivo: Gateway funcional minimo sem contaminacao de dominio.

Entregas:

- Migrations Gateway: `provider_connections`, `provider_webhook_events`, `provider_messages`, `delivery_attempts`.
- Z-API adapter real baseado no comportamento de `ZapiApi`.
- Webhook receiver `POST /api/provider/zapi/webhooks/messages` ou equivalente.
- Validacao por token/header e futura assinatura.
- Normalizador Z-API com body extractor.
- Phone/JID normalizer.
- Deduplicacao por provider message id.
- Raw event log sanitizado.
- Envio text outbound.
- Contrato `Gateway -> Orchestra inbound`.
- Contrato `Orchestra -> Gateway send text`.
- Tests baseados nos casos legados de WhatsApp webhook, idempotency e send.

Nao incluir:

- UI, realtime, departamentos, agent presence, campanhas, Instagram, media storage definitivo.

### Fase 2 - Orchestra Communication Center

Objetivo: centro de atendimento no dominio certo.

Entregas:

- Tabelas de communication channels/inboxes/conversations/messages/assignments/departments.
- Endpoints autenticados para inbox, conversa, lock, transfer, close, release-to-bot.
- Customer/CRM link.
- RBAC por tenant/departamento.
- Realtime privado via broadcast na Orchestra.
- Audit log de acoes humanas.
- Migrar comportamento dos testes legados de lock/transfer/presence para Orchestra.
- Frontend de atendimento.

### Fase 3 - Agent / n8n

Objetivo: agente controlado, observavel e com fallback.

Entregas:

- Decisao bot/humano na Orchestra.
- `agent_runs` no Gateway.
- Payload versionado para n8n, sem payload bruto completo.
- Timeout, retry, dead letter e status.
- Callback ou resposta sincrona padronizada.
- Handoff humano comandado pela Orchestra.
- Contexto minimo de conversa e customer vindo da Orchestra.

### Fase 4 - Campaigns / Broadcast

Objetivo: comunicacao ativa e marketing com governanca.

Entregas:

- Templates aprovados.
- Campanhas e segmentacao.
- Opt-in/opt-out.
- Rate limit por tenant/provider.
- Filas e agendamento.
- Metricas por campanha.
- Custos/billing por mensagem se necessario.

### Fase 5 - Omnichannel / Provider Proprio

Objetivo: expandir canais e reduzir dependencia.

Entregas:

- Instagram/Meta.
- Email/SMS se fizer sentido.
- Multiprovedor WhatsApp.
- Provider proprio.
- Roteamento por custo/saude/provider.
- SLA e observabilidade multi-provider.

## 18. Recomendacoes

- Usar o legado como mapa de comportamento, nao como codigo transplantado.
- Priorizar o caminho WhatsApp inbound/outbound texto.
- Criar contratos HTTP primeiro, com fixtures de payload.
- Congelar o formato normalizado de inbound antes de mexer na UI.
- Implementar idempotencia no Gateway antes de qualquer chamada para Orchestra.
- Nao permitir token por query/body.
- Remover hardcoded n8n URL e credenciais default em qualquer codigo novo.
- Manter raw payload fora da Orchestra.
- Manter realtime exclusivamente na Orchestra.
- Migrar testes por comportamento: idempotencia, ignore group/OTP, phone normalization, lock, human message requires lock, bot blocked in human mode.
- Adotar correlation id desde o primeiro endpoint.
- Definir retencao de raw payload e mascaramento de telefone/mensagem em logs.
- Planejar migracao de dados so depois de Fase 1/Fase 2 estarem estaveis.

## 19. Proximo Prompt Sugerido Para Implementacao da Fase 1

```txt
Implementar a Fase 1 do Communication Gateway WhatsApp sem tocar na Orchestra UI.

Escopo:
- Criar migrations do Gateway para provider_connections, provider_webhook_events, provider_messages e delivery_attempts.
- Implementar ZApiMessageProvider real usando config/communication.php.
- Criar webhook receiver Z-API com validacao por header, rate limit provider-webhooks e idempotencia.
- Criar normalizador de payload Z-API inspirado no legacy WhatsAppDriver, sem copiar arquivo.
- Criar endpoint interno POST /api/internal/gateway/messages/send protegido por EnsureServiceToken.
- Criar client interno para enviar inbound/status para Orchestra usando ORCHESTRA_API_URL e ORCHESTRA_SERVICE_TOKEN.
- Criar testes Feature cobrindo health existente, webhook invalido, webhook valido, idempotencia, envio text fake/local, e sanitizacao de logs.

Regras:
- Nao migrar conversas/atendentes/realtime.
- Nao copiar migrations do legacy.
- Nao registrar token nem payload bruto em log.
- Usar hash_equals e nao aceitar token por query string.
- Manter API only.
```

